<?php

declare(strict_types=1);

require_once __DIR__ . '/../backend/bootstrap/autoload.php';
require_once __DIR__ . '/../backend/bootstrap/runtime.php';

use Proxbet\Line\Db;

const GATING_DEFAULT_WINDOWS = ['1d', '2d', '7d'];

proxbet_bootstrap_env();

$options = getopt('', ['window:', 'limit:']);
$window = isset($options['window']) ? strtolower((string) $options['window']) : 'all';
$limit = isset($options['limit']) ? max(1, (int) $options['limit']) : 20;

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

$windows = $window === 'all' ? GATING_DEFAULT_WINDOWS : [$window];

try {
    $db = Db::connectFromEnv();
    $rows = fetchAlgorithmOneV2Rows($db);

    if ($rows === []) {
        echo "No Algorithm 1 v2 rows found in matches.live_score_components.\n";
        exit(0);
    }

    echo "Algorithm 1 gating impact report\n";
    echo "Rows with v2 payload: " . count($rows) . "\n\n";

    foreach ($windows as $windowKey) {
        $days = $supportedWindows[$windowKey];
        $filteredRows = filterRowsByDays($rows, $days);

        echo "=== Window: {$windowKey} ===\n";

        if ($filteredRows === []) {
            echo "No rows for this window.\n\n";
            continue;
        }

        printImpactTable($filteredRows);
        echo "\n";
        printTopAffectedMatches($filteredRows, $limit);
        echo "\n";
    }
} catch (\Throwable $e) {
    fwrite(STDERR, "Error: {$e->getMessage()}\n");
    exit(1);
}

/**
 * @return list<array<string,mixed>>
 */
function fetchAlgorithmOneV2Rows(PDO $db): array
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
        if (!is_array($payload) || (int) ($payload['algorithm_version'] ?? 0) !== 2) {
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
            'observed_at' => $observedAt,
            'observed_at_text' => $observedAtRaw,
            'payload' => $payload,
            'is_bet' => (string) ($payload['decision_reason'] ?? '') === 'probability_threshold_met',
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
function printImpactTable(array $rows): void
{
    $stats = [];

    foreach ($rows as $row) {
        $payload = $row['payload'];
        $isBet = (bool) $row['is_bet'];

        $gatingReason = trim((string) ($payload['gating_reason'] ?? ''));
        if ($gatingReason !== '') {
            registerImpactRow($stats, $gatingReason, 'gating_reject', $isBet);
        }

        $penalties = is_array($payload['penalties'] ?? null) ? $payload['penalties'] : [];
        foreach ($penalties as $name => $factor) {
            registerImpactRow($stats, (string) $name, 'penalty', $isBet, (float) $factor);
        }

        $redFlags = is_array($payload['red_flags'] ?? null) ? $payload['red_flags'] : [];
        foreach ($redFlags as $flag) {
            registerImpactRow($stats, (string) $flag, 'red_flag', $isBet);
        }
    }

    if ($stats === []) {
        echo "No gating, penalty or red-flag events found.\n";
        return;
    }

    uasort($stats, static function (array $left, array $right): int {
        return $right['affected'] <=> $left['affected'];
    });

    echo str_pad('filter', 28)
        . str_pad('type', 16)
        . str_pad('affected', 12)
        . str_pad('bets', 8)
        . str_pad('no_bets', 10)
        . "avg_factor\n";
    echo str_repeat('-', 82) . "\n";

    foreach ($stats as $name => $entry) {
        $avgFactor = $entry['factor_hits'] > 0
            ? number_format($entry['factor_sum'] / $entry['factor_hits'], 3, '.', '')
            : '-';

        echo str_pad($name, 28)
            . str_pad($entry['type'], 16)
            . str_pad((string) $entry['affected'], 12)
            . str_pad((string) $entry['bets'], 8)
            . str_pad((string) $entry['no_bets'], 10)
            . $avgFactor . "\n";
    }
}

/**
 * @param array<string,array<string,int|float|string>> $stats
 */
function registerImpactRow(array &$stats, string $name, string $type, bool $isBet, ?float $factor = null): void
{
    if (!isset($stats[$name])) {
        $stats[$name] = [
            'type' => $type,
            'affected' => 0,
            'bets' => 0,
            'no_bets' => 0,
            'factor_sum' => 0.0,
            'factor_hits' => 0,
        ];
    }

    $stats[$name]['affected']++;
    if ($isBet) {
        $stats[$name]['bets']++;
    } else {
        $stats[$name]['no_bets']++;
    }

    if ($factor !== null) {
        $stats[$name]['factor_sum'] += $factor;
        $stats[$name]['factor_hits']++;
    }
}

/**
 * @param list<array<string,mixed>> $rows
 */
function printTopAffectedMatches(array $rows, int $limit): void
{
    $affectedRows = array_values(array_filter($rows, static function (array $row): bool {
        $payload = $row['payload'];

        return trim((string) ($payload['gating_reason'] ?? '')) !== ''
            || (is_array($payload['penalties'] ?? null) && $payload['penalties'] !== [])
            || (is_array($payload['red_flags'] ?? null) && $payload['red_flags'] !== []);
    }));

    usort($affectedRows, static function (array $left, array $right): int {
        return $right['observed_at'] <=> $left['observed_at'];
    });

    echo "Recent affected rows\n";
    if ($affectedRows === []) {
        echo "  none\n";
        return;
    }

    foreach (array_slice($affectedRows, 0, $limit) as $row) {
        $payload = $row['payload'];
        $penalties = is_array($payload['penalties'] ?? null) ? implode(',', array_keys($payload['penalties'])) : '-';
        $redFlags = is_array($payload['red_flags'] ?? null) ? implode(',', $payload['red_flags']) : '-';
        $gatingReason = trim((string) ($payload['gating_reason'] ?? ''));
        if ($gatingReason === '') {
            $gatingReason = '-';
        }

        echo sprintf(
            "  #%d %s | %s vs %s | bet=%s | gating=%s | penalties=%s | red_flags=%s | probability=%.3f\n",
            $row['id'],
            $row['observed_at_text'],
            $row['home'],
            $row['away'],
            $row['is_bet'] ? 'yes' : 'no',
            $gatingReason,
            $penalties === '' ? '-' : $penalties,
            $redFlags === '' ? '-' : $redFlags,
            (float) ($payload['probability'] ?? 0.0)
        );
    }
}
