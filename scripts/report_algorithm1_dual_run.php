<?php

declare(strict_types=1);

require_once __DIR__ . '/../backend/bootstrap/autoload.php';
require_once __DIR__ . '/../backend/bootstrap/runtime.php';

use Proxbet\Line\Db;

const DEFAULT_LIMIT = 20;
const DEFAULT_WINDOWS = ['1d', '2d', '7d'];

proxbet_bootstrap_env();

$options = getopt('', ['window:', 'limit:']);
$window = isset($options['window']) ? strtolower((string) $options['window']) : 'all';
$limit = isset($options['limit']) ? max(1, (int) $options['limit']) : DEFAULT_LIMIT;

$supportedWindows = [
    'today' => 1,
    '1d' => 1,
    '2d' => 2,
    '7d' => 7,
];

if ($window !== 'all' && !isset($supportedWindows[$window])) {
    fwrite(STDERR, "Unsupported --window value. Use one of: today, 1d, 2d, 7d, all\n");
    exit(1);
}

$windows = $window === 'all' ? DEFAULT_WINDOWS : [$window];

try {
    $db = Db::connectFromEnv();
    $rows = fetchDualRunRows($db);

    if ($rows === []) {
        echo "No Algorithm 1 dual-run records found in matches.live_score_components.\n";
        exit(0);
    }

    echo "Algorithm 1 dual-run report\n";
    echo "Rows with dual_run payload: " . count($rows) . "\n\n";

    foreach ($windows as $windowKey) {
        $days = $supportedWindows[$windowKey];
        $filteredRows = filterRowsByDays($rows, $days);

        echo "=== Window: {$windowKey} ===\n";

        if ($filteredRows === []) {
            echo "No rows for this window.\n\n";
            continue;
        }

        printSummary($filteredRows);
        echo "\n";
        printTopMatches('legacy = bet, v2 = no bet', filterByDecisionPair($filteredRows, 'bet', 'no_bet'), $limit);
        printTopMatches('legacy = no bet, v2 = bet', filterByDecisionPair($filteredRows, 'no_bet', 'bet'), $limit);
        printTopMatches('same decision, different probability', filterSameDecisionWithProbabilityGap($filteredRows), $limit);
        echo "\n";
    }
} catch (\Throwable $e) {
    fwrite(STDERR, "Error: {$e->getMessage()}\n");
    exit(1);
}

/**
 * @return list<array<string,mixed>>
 */
function fetchDualRunRows(PDO $db): array
{
    $sql = <<<'SQL'
SELECT
    `id`,
    `country`,
    `liga`,
    `home`,
    `away`,
    `time`,
    `match_status`,
    `algorithm_version`,
    `live_score_components`,
    COALESCE(`live_updated_at`, `updated_at`, `created_at`) AS observed_at
FROM `matches`
WHERE `live_score_components` IS NOT NULL
ORDER BY COALESCE(`live_updated_at`, `updated_at`, `created_at`) DESC, `id` DESC
SQL;

    $stmt = $db->query($sql);
    $rawRows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $rows = [];

    foreach ($rawRows as $row) {
        $payload = json_decode((string) ($row['live_score_components'] ?? ''), true);
        if (!is_array($payload)) {
            continue;
        }

        $dualRun = $payload['dual_run'] ?? null;
        if (!is_array($dualRun)) {
            continue;
        }

        $legacyDecision = normalizeDecision($dualRun['legacy_decision'] ?? ($dualRun['legacy_bet'] ?? null));
        $v2Decision = normalizeDecision($dualRun['v2_decision'] ?? ($dualRun['v2_bet'] ?? null));

        if ($legacyDecision === null || $v2Decision === null) {
            continue;
        }

        $observedAtRaw = (string) ($row['observed_at'] ?? '');
        $observedAt = strtotime($observedAtRaw);
        if ($observedAt === false) {
            continue;
        }

        $rows[] = [
            'id' => (int) $row['id'],
            'country' => (string) ($row['country'] ?? ''),
            'liga' => (string) ($row['liga'] ?? ''),
            'home' => (string) ($row['home'] ?? ''),
            'away' => (string) ($row['away'] ?? ''),
            'time' => (string) ($row['time'] ?? ''),
            'match_status' => (string) ($row['match_status'] ?? ''),
            'algorithm_version' => (int) ($row['algorithm_version'] ?? 1),
            'observed_at' => $observedAt,
            'observed_at_text' => $observedAtRaw,
            'legacy_probability' => (float) ($dualRun['legacy_probability'] ?? 0.0),
            'legacy_decision' => $legacyDecision,
            'legacy_reason' => (string) ($dualRun['legacy_reason'] ?? ''),
            'v2_probability' => (float) ($dualRun['v2_probability'] ?? 0.0),
            'v2_decision' => $v2Decision,
            'v2_reason' => (string) ($dualRun['v2_reason'] ?? ''),
            'decision_match' => (bool) ($dualRun['decision_match'] ?? ($legacyDecision === $v2Decision)),
            'divergence_level' => (string) ($dualRun['divergence_level'] ?? 'none'),
            'probability_diff' => abs((float) ($dualRun['probability_diff'] ?? 0.0)),
        ];
    }

    return $rows;
}

/**
 * @param list<array<string,mixed>> $rows
 * @return list<array<string,mixed>>
 */
function filterRowsByDays(array $rows, int $days): array
{
    $threshold = strtotime("-{$days} days");
    if ($threshold === false) {
        return $rows;
    }

    return array_values(array_filter(
        $rows,
        static fn (array $row): bool => (int) $row['observed_at'] >= $threshold
    ));
}

/**
 * @param list<array<string,mixed>> $rows
 */
function printSummary(array $rows): void
{
    $summary = [
        'total' => count($rows),
        'decision_mismatch' => 0,
        'legacy_bet_v2_no_bet' => 0,
        'legacy_no_bet_v2_bet' => 0,
        'same_decision_probability_gap' => 0,
        'divergence_none' => 0,
        'divergence_low' => 0,
        'divergence_medium' => 0,
        'divergence_high' => 0,
    ];

    foreach ($rows as $row) {
        if ($row['legacy_decision'] !== $row['v2_decision']) {
            $summary['decision_mismatch']++;
        }

        if ($row['legacy_decision'] === 'bet' && $row['v2_decision'] === 'no_bet') {
            $summary['legacy_bet_v2_no_bet']++;
        }

        if ($row['legacy_decision'] === 'no_bet' && $row['v2_decision'] === 'bet') {
            $summary['legacy_no_bet_v2_bet']++;
        }

        if ($row['legacy_decision'] === $row['v2_decision'] && $row['probability_diff'] >= 0.05) {
            $summary['same_decision_probability_gap']++;
        }

        $level = (string) $row['divergence_level'];
        $key = 'divergence_' . $level;
        if (array_key_exists($key, $summary)) {
            $summary[$key]++;
        }
    }

    foreach ($summary as $label => $value) {
        echo str_pad($label, 30) . ": {$value}\n";
    }
}

/**
 * @param list<array<string,mixed>> $rows
 * @return list<array<string,mixed>>
 */
function filterByDecisionPair(array $rows, string $legacyDecision, string $v2Decision): array
{
    $filtered = array_values(array_filter(
        $rows,
        static fn (array $row): bool => $row['legacy_decision'] === $legacyDecision && $row['v2_decision'] === $v2Decision
    ));

    usort($filtered, static function (array $left, array $right): int {
        return $right['probability_diff'] <=> $left['probability_diff'];
    });

    return $filtered;
}

/**
 * @param list<array<string,mixed>> $rows
 * @return list<array<string,mixed>>
 */
function filterSameDecisionWithProbabilityGap(array $rows): array
{
    $filtered = array_values(array_filter(
        $rows,
        static fn (array $row): bool => $row['legacy_decision'] === $row['v2_decision'] && $row['probability_diff'] >= 0.05
    ));

    usort($filtered, static function (array $left, array $right): int {
        return $right['probability_diff'] <=> $left['probability_diff'];
    });

    return $filtered;
}

/**
 * @param list<array<string,mixed>> $rows
 */
function printTopMatches(string $title, array $rows, int $limit): void
{
    echo $title . "\n";

    if ($rows === []) {
        echo "  none\n";
        return;
    }

    foreach (array_slice($rows, 0, $limit) as $row) {
        $match = sprintf(
            '#%d %s | %s vs %s | legacy %.3f (%s) | v2 %.3f (%s) | diff %.3f | %s',
            $row['id'],
            $row['observed_at_text'],
            $row['home'],
            $row['away'],
            $row['legacy_probability'],
            $row['legacy_decision'],
            $row['v2_probability'],
            $row['v2_decision'],
            $row['probability_diff'],
            $row['divergence_level']
        );

        echo '  ' . $match . "\n";
    }
}

/**
 * @param bool|string|null $value
 */
function normalizeDecision($value): ?string
{
    if ($value === true || $value === 1 || $value === '1' || $value === 'bet') {
        return 'bet';
    }

    if ($value === false || $value === 0 || $value === '0' || $value === 'no_bet') {
        return 'no_bet';
    }

    return null;
}
