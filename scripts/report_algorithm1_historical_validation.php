<?php

declare(strict_types=1);

require_once __DIR__ . '/../backend/bootstrap/autoload.php';
require_once __DIR__ . '/../backend/bootstrap/runtime.php';

use Proxbet\Line\Db;
use Proxbet\Scanner\Algorithms\AlgorithmOne\Services\HistoricalReplayService;

proxbet_bootstrap_env();

$options = getopt('', ['limit::', 'from::', 'to::', 'report-file::', 'json']);
$limit = isset($options['limit']) ? max(1, (int) $options['limit']) : 500;
$from = isset($options['from']) ? trim((string) $options['from']) : '';
$to = isset($options['to']) ? trim((string) $options['to']) : '';
$reportFile = isset($options['report-file'])
    ? (string) $options['report-file']
    : (__DIR__ . '/../docs/reports/algorithm1_historical_validation.md');
$jsonOutput = isset($options['json']);

try {
    $db = Db::connectFromEnv();
    $matches = fetchHistoricalReplayMatches($db, $limit, $from, $to);
    $snapshots = fetchHistoricalReplaySnapshots($db, array_column($matches, 'id'));
    $payload = hydrateReplayPayload($matches, $snapshots);

    $service = new HistoricalReplayService();
    $report = $service->replay($payload);

    if ($jsonOutput) {
        echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    } else {
        echo renderConsoleReport($report);
    }

    if ($reportFile !== '') {
        $reportDir = dirname($reportFile);
        if (!is_dir($reportDir)) {
            mkdir($reportDir, 0777, true);
        }

        file_put_contents($reportFile, $service->buildMarkdownReport($report));

        if (!$jsonOutput) {
            echo PHP_EOL . 'Markdown report saved: ' . $reportFile . PHP_EOL;
        }
    }
} catch (\Throwable $e) {
    fwrite(STDERR, 'Error: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}

/**
 * @return list<array<string,mixed>>
 */
function fetchHistoricalReplayMatches(PDO $db, int $limit, string $from, string $to): array
{
    $conditions = [
        'EXISTS (SELECT 1 FROM `live_match_snapshots` s WHERE s.`match_id` = m.`id` AND s.`minute` BETWEEN 15 AND 30)',
    ];
    $params = [];

    if ($from !== '') {
        $conditions[] = 'COALESCE(m.`live_updated_at`, m.`updated_at`, m.`created_at`) >= ?';
        $params[] = $from . ' 00:00:00';
    }

    if ($to !== '') {
        $conditions[] = 'COALESCE(m.`live_updated_at`, m.`updated_at`, m.`created_at`) <= ?';
        $params[] = $to . ' 23:59:59';
    }

    $sql = 'SELECT m.* '
        . 'FROM `matches` m '
        . 'WHERE ' . implode(' AND ', $conditions) . ' '
        . 'ORDER BY COALESCE(m.`live_updated_at`, m.`updated_at`, m.`created_at`) DESC, m.`id` DESC '
        . 'LIMIT ' . $limit;

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    return is_array($rows) ? $rows : [];
}

/**
 * @param list<int|string> $matchIds
 * @return array<int,list<array<string,mixed>>>
 */
function fetchHistoricalReplaySnapshots(PDO $db, array $matchIds): array
{
    $matchIds = array_values(array_filter(array_map(
        static fn ($value): int => (int) $value,
        $matchIds,
    )));

    if ($matchIds === []) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($matchIds), '?'));
    $sql = 'SELECT * '
        . 'FROM `live_match_snapshots` '
        . 'WHERE `match_id` IN (' . $placeholders . ') '
        . 'AND `minute` BETWEEN 15 AND 30 '
        . 'ORDER BY `match_id` ASC, `minute` ASC, `captured_at` ASC';

    $stmt = $db->prepare($sql);
    foreach ($matchIds as $index => $matchId) {
        $stmt->bindValue($index + 1, $matchId, PDO::PARAM_INT);
    }
    $stmt->execute();

    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $grouped = [];

    foreach (is_array($rows) ? $rows : [] as $row) {
        $matchId = (int) ($row['match_id'] ?? 0);
        if ($matchId <= 0) {
            continue;
        }

        $grouped[$matchId] ??= [];
        $grouped[$matchId][] = $row;
    }

    return $grouped;
}

/**
 * @param list<array<string,mixed>> $matches
 * @param array<int,list<array<string,mixed>>> $snapshots
 * @return list<array{match:array<string,mixed>,snapshots:list<array<string,mixed>>}>
 */
function hydrateReplayPayload(array $matches, array $snapshots): array
{
    $payload = [];

    foreach ($matches as $match) {
        $matchId = (int) ($match['id'] ?? 0);
        $payload[] = [
            'match' => $match,
            'snapshots' => $snapshots[$matchId] ?? [],
        ];
    }

    return $payload;
}

/**
 * @param array<string,mixed> $report
 */
function renderConsoleReport(array $report): string
{
    $lines = [];
    $lines[] = 'Algorithm 1 historical validation';
    $lines[] = 'Generated at: ' . (string) ($report['generated_at'] ?? '');
    $lines[] = 'Matches replayed: ' . (string) (($report['summary']['matches_replayed'] ?? 0));
    $lines[] = 'Matches skipped: ' . (string) (($report['summary']['matches_skipped'] ?? 0));
    $lines[] = '';
    $lines[] = str_pad('profile', 14)
        . str_pad('matches', 10)
        . str_pad('signals', 10)
        . str_pad('wins', 8)
        . str_pad('losses', 9)
        . str_pad('signal%', 10)
        . 'win%';
    $lines[] = str_repeat('-', 68);

    foreach (['legacy', 'current_v2', 'fixed_v2', 'tuned_v2'] as $profile) {
        $summary = $report['profiles'][$profile] ?? [];
        $lines[] = str_pad($profile, 14)
            . str_pad((string) ($summary['matches'] ?? 0), 10)
            . str_pad((string) ($summary['signals'] ?? 0), 10)
            . str_pad((string) ($summary['wins'] ?? 0), 8)
            . str_pad((string) ($summary['losses'] ?? 0), 9)
            . str_pad(number_format(((float) ($summary['signal_rate'] ?? 0.0)) * 100, 2, '.', ''), 10)
            . number_format(((float) ($summary['win_rate'] ?? 0.0)) * 100, 2, '.', '');
    }

    $lines[] = '';
    $lines[] = 'Top rejection reasons';
    foreach (['legacy', 'current_v2', 'fixed_v2', 'tuned_v2'] as $profile) {
        $summary = $report['profiles'][$profile] ?? [];
        $reasons = array_slice((array) ($summary['rejection_reasons'] ?? []), 0, 3, true);
        $lines[] = '- ' . $profile . ': '
            . ($reasons === []
                ? 'none'
                : implode(', ', array_map(
                    static fn (string $reason, int $count): string => $reason . '=' . $count,
                    array_keys($reasons),
                    array_values($reasons),
                )));
    }

    return implode(PHP_EOL, $lines) . PHP_EOL;
}
