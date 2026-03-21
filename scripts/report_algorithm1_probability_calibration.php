<?php

declare(strict_types=1);

require_once __DIR__ . '/../backend/bootstrap/autoload.php';
require_once __DIR__ . '/../backend/bootstrap/runtime.php';

use Proxbet\Line\Db;
use Proxbet\Scanner\Algorithms\AlgorithmOne\Config;

const CALIBRATION_DEFAULT_WINDOWS = ['1d', '2d', '7d'];

proxbet_bootstrap_env();

$options = getopt('', ['window:']);
$window = isset($options['window']) ? strtolower((string) $options['window']) : 'all';

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

$windows = $window === 'all' ? CALIBRATION_DEFAULT_WINDOWS : [$window];

try {
    $db = Db::connectFromEnv();
    $rows = fetchAlgorithmOneCalibrationRows($db);

    if ($rows === []) {
        echo "No Algorithm 1 v2 calibration rows found in matches.live_score_components.\n";
        exit(0);
    }

    echo "Algorithm 1 probability calibration report\n";
    echo "Rows with analyzable v2 payload: " . count($rows) . "\n";
    echo "Active threshold: " . number_format(Config::getV2MinProbability(), 2, '.', '') . "\n";
    echo "Threshold candidates: " . implode(', ', array_map(
        static fn (float $value): string => number_format($value, 2, '.', ''),
        Config::getV2ThresholdCandidates()
    )) . "\n\n";

    foreach ($windows as $windowKey) {
        $days = $supportedWindows[$windowKey];
        $filteredRows = filterRowsByDays($rows, $days);

        echo "=== Window: {$windowKey} ===\n";

        if ($filteredRows === []) {
            echo "No rows for this window.\n\n";
            continue;
        }

        printContributionTable($filteredRows);
        echo "\n";
        printXgAvailabilitySummary($filteredRows);
        echo "\n";
        printTimePressureTable($filteredRows);
        echo "\n";
        printThresholdComparison($filteredRows);
        echo "\n";
    }
} catch (\Throwable $e) {
    fwrite(STDERR, "Error: {$e->getMessage()}\n");
    exit(1);
}

/**
 * @return list<array<string,mixed>>
 */
function fetchAlgorithmOneCalibrationRows(PDO $db): array
{
    $sql = <<<'SQL'
SELECT
    `id`,
    `home`,
    `away`,
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

        $components = is_array($payload['components'] ?? null) ? $payload['components'] : [];
        $breakdown = is_array($components['probability_breakdown'] ?? null)
            ? $components['probability_breakdown']
            : [];
        $contributions = is_array($components['component_contributions'] ?? null)
            ? $components['component_contributions']
            : [];

        if ($breakdown === [] || $contributions === []) {
            continue;
        }

        $observedAtRaw = (string) ($row['observed_at'] ?? '');
        $observedAt = strtotime($observedAtRaw);
        if ($observedAt === false) {
            continue;
        }

        $rows[] = [
            'id' => (int) $row['id'],
            'home' => (string) ($row['home'] ?? ''),
            'away' => (string) ($row['away'] ?? ''),
            'observed_at' => $observedAt,
            'probability' => (float) ($payload['probability'] ?? 0.0),
            'is_bet' => (string) ($payload['decision_reason'] ?? '') === 'probability_threshold_met',
            'minute' => (int) (($payload['gating_context']['minute'] ?? 0)),
            'breakdown' => $breakdown,
            'contributions' => $contributions,
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
function printContributionTable(array $rows): void
{
    $averages = [
        'form_final' => 0.0,
        'h2h_final' => 0.0,
        'live_final' => 0.0,
        'live_pdi' => 0.0,
        'live_shot_quality' => 0.0,
        'live_trend' => 0.0,
        'live_xg_pressure' => 0.0,
        'live_card_factor' => 0.0,
    ];

    foreach ($rows as $row) {
        $contributions = $row['contributions'];
        $liveComponents = is_array($contributions['live_components_final'] ?? null)
            ? $contributions['live_components_final']
            : [];

        $averages['form_final'] += (float) ($contributions['form_final'] ?? 0.0);
        $averages['h2h_final'] += (float) ($contributions['h2h_final'] ?? 0.0);
        $averages['live_final'] += (float) ($contributions['live_final'] ?? 0.0);
        $averages['live_pdi'] += (float) ($liveComponents['pdi'] ?? 0.0);
        $averages['live_shot_quality'] += (float) ($liveComponents['shot_quality'] ?? 0.0);
        $averages['live_trend'] += (float) ($liveComponents['trend_acceleration'] ?? 0.0);
        $averages['live_xg_pressure'] += (float) ($liveComponents['xg_pressure'] ?? 0.0);
        $averages['live_card_factor'] += (float) ($liveComponents['card_factor'] ?? 0.0);
    }

    echo "Average final probability contribution per row\n";
    foreach ($averages as $label => $total) {
        echo str_pad($label, 24) . ': ' . number_format($total / count($rows), 4, '.', '') . "\n";
    }
}

/**
 * @param list<array<string,mixed>> $rows
 */
function printXgAvailabilitySummary(array $rows): void
{
    $groups = [
        'with_xg' => ['rows' => 0, 'bets' => 0, 'probability_sum' => 0.0, 'xg_pressure_sum' => 0.0, 'shot_quality_sum' => 0.0],
        'without_xg' => ['rows' => 0, 'bets' => 0, 'probability_sum' => 0.0, 'xg_pressure_sum' => 0.0, 'shot_quality_sum' => 0.0],
    ];

    foreach ($rows as $row) {
        $key = ((bool) ($row['breakdown']['xg_available'] ?? false)) ? 'with_xg' : 'without_xg';
        $groups[$key]['rows']++;
        $groups[$key]['bets'] += $row['is_bet'] ? 1 : 0;
        $groups[$key]['probability_sum'] += (float) $row['probability'];
        $groups[$key]['xg_pressure_sum'] += (float) (($row['contributions']['live_components_final']['xg_pressure'] ?? 0.0));
        $groups[$key]['shot_quality_sum'] += (float) (($row['contributions']['live_components_final']['shot_quality'] ?? 0.0));
    }

    echo "xG availability impact\n";
    echo str_pad('group', 14)
        . str_pad('rows', 8)
        . str_pad('bet_rate', 12)
        . str_pad('avg_prob', 12)
        . str_pad('avg_xg_live', 14)
        . "avg_shot_live\n";
    echo str_repeat('-', 60) . "\n";

    foreach ($groups as $name => $group) {
        $rowsCount = max(1, $group['rows']);
        echo str_pad($name, 14)
            . str_pad((string) $group['rows'], 8)
            . str_pad(number_format($group['bets'] / $rowsCount, 3, '.', ''), 12)
            . str_pad(number_format($group['probability_sum'] / $rowsCount, 3, '.', ''), 12)
            . str_pad(number_format($group['xg_pressure_sum'] / $rowsCount, 3, '.', ''), 14)
            . number_format($group['shot_quality_sum'] / $rowsCount, 3, '.', '') . "\n";
    }
}

/**
 * @param list<array<string,mixed>> $rows
 */
function printTimePressureTable(array $rows): void
{
    $minutes = [];
    foreach ($rows as $row) {
        $minute = (int) ($row['minute'] ?? 0);
        if ($minute < 15 || $minute > 20) {
            continue;
        }

        if (!isset($minutes[$minute])) {
            $minutes[$minute] = [
                'rows' => 0,
                'time_pressure_sum' => 0.0,
                'probability_sum' => 0.0,
            ];
        }

        $minutes[$minute]['rows']++;
        $minutes[$minute]['time_pressure_sum'] += (float) ($row['breakdown']['time_pressure_multiplier'] ?? 0.0);
        $minutes[$minute]['probability_sum'] += (float) $row['probability'];
    }

    ksort($minutes);

    echo "Time pressure in minutes 15-20\n";
    if ($minutes === []) {
        echo "No rows in 15-20 minute band.\n";
        return;
    }

    echo str_pad('minute', 10)
        . str_pad('rows', 8)
        . str_pad('avg_tp_mult', 14)
        . "avg_prob\n";
    echo str_repeat('-', 42) . "\n";

    foreach ($minutes as $minute => $stats) {
        $rowsCount = max(1, $stats['rows']);
        echo str_pad((string) $minute, 10)
            . str_pad((string) $stats['rows'], 8)
            . str_pad(number_format($stats['time_pressure_sum'] / $rowsCount, 3, '.', ''), 14)
            . number_format($stats['probability_sum'] / $rowsCount, 3, '.', '') . "\n";
    }
}

/**
 * @param list<array<string,mixed>> $rows
 */
function printThresholdComparison(array $rows): void
{
    $thresholds = Config::getV2ThresholdCandidates();

    echo "Threshold comparison\n";
    foreach ($thresholds as $threshold) {
        $bets = 0;
        foreach ($rows as $row) {
            if ((float) $row['probability'] >= $threshold) {
                $bets++;
            }
        }

        echo str_pad(number_format($threshold, 2, '.', ''), 8)
            . ': '
            . str_pad((string) $bets, 6)
            . 'signals (' . number_format($bets / max(1, count($rows)), 3, '.', '') . ")\n";
    }
}
