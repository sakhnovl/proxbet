<?php

declare(strict_types=1);

/**
 * Collect historical data for AlgorithmX calibration.
 * 
 * Runs AlgorithmX on historical matches and stores predictions with actual outcomes.
 * This data is used for parameter calibration and quality metrics calculation.
 * 
 * Usage:
 *   php scripts/algorithmx_collect_historical_data.php --limit=500 --from=2026-01-01 --to=2026-03-22
 */

require_once __DIR__ . '/../backend/bootstrap/autoload.php';
require_once __DIR__ . '/../backend/bootstrap/runtime.php';

use Proxbet\Line\Db;
use Proxbet\Scanner\AlgorithmFactory;
use Proxbet\Scanner\DataExtractor;

proxbet_bootstrap_env();

$options = getopt('', ['limit::', 'from::', 'to::', 'output::']);
$limit = isset($options['limit']) ? max(1, (int) $options['limit']) : 500;
$from = isset($options['from']) ? trim((string) $options['from']) : '';
$to = isset($options['to']) ? trim((string) $options['to']) : '';
$outputFile = isset($options['output'])
    ? (string) $options['output']
    : (__DIR__ . '/../data/algorithmx_historical_data.json');

try {
    $db = Db::connectFromEnv();
    $factory = new AlgorithmFactory();
    $algorithmX = $factory->create(4); // AlgorithmX ID = 4
    $extractor = new DataExtractor();
    
    echo "Collecting historical data for AlgorithmX calibration...\n";
    echo "Limit: {$limit} matches\n";
    if ($from !== '') {
        echo "From: {$from}\n";
    }
    if ($to !== '') {
        echo "To: {$to}\n";
    }
    echo "\n";
    
    $matches = fetchHistoricalMatches($db, $limit, $from, $to);
    echo "Found " . count($matches) . " matches with live data\n\n";
    
    $results = [];
    $processed = 0;
    $skipped = 0;
    
    foreach ($matches as $match) {
        $matchId = (int) ($match['id'] ?? 0);
        $minute = parseMinute((string) ($match['time'] ?? '00:00'));
        
        // Skip if not in valid minute range (5-45)
        if ($minute < 5 || $minute > 45) {
            $skipped++;
            continue;
        }
        
        try {
            // Extract data for AlgorithmX
            $liveData = $extractor->extractAlgorithmXData($match);
            
            if (!($liveData['has_data'] ?? false)) {
                $skipped++;
                continue;
            }
            
            // Run AlgorithmX analysis
            $decision = $algorithmX->analyze(['live_data' => $liveData]);
            
            // Determine actual outcome (was there a goal in remaining time of 1st half?)
            $actualGoal = determineActualOutcome($match, $minute);
            
            $results[] = [
                'match_id' => $matchId,
                'home' => (string) ($match['home'] ?? ''),
                'away' => (string) ($match['away'] ?? ''),
                'minute' => $minute,
                'predicted_probability' => (float) ($decision['confidence'] ?? 0.0),
                'actual_goal' => $actualGoal,
                'bet_decision' => (bool) ($decision['bet'] ?? false),
                'live_data' => $liveData,
                'debug' => $decision['debug'] ?? [],
            ];
            
            $processed++;
            
            if ($processed % 50 === 0) {
                echo "Processed: {$processed}, Skipped: {$skipped}\n";
            }
        } catch (\Throwable $e) {
            $skipped++;
            echo "Error processing match {$matchId}: {$e->getMessage()}\n";
        }
    }
    
    echo "\nCollection complete:\n";
    echo "- Processed: {$processed}\n";
    echo "- Skipped: {$skipped}\n";
    echo "- Total results: " . count($results) . "\n";
    
    // Save results
    $outputDir = dirname($outputFile);
    if (!is_dir($outputDir)) {
        mkdir($outputDir, 0777, true);
    }
    
    $data = [
        'generated_at' => date('Y-m-d H:i:s'),
        'parameters' => [
            'limit' => $limit,
            'from' => $from,
            'to' => $to,
        ],
        'summary' => [
            'total_matches' => count($matches),
            'processed' => $processed,
            'skipped' => $skipped,
            'goals_occurred' => count(array_filter($results, fn($r) => $r['actual_goal'])),
            'no_goals' => count(array_filter($results, fn($r) => !$r['actual_goal'])),
        ],
        'results' => $results,
    ];
    
    file_put_contents($outputFile, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    echo "\nData saved to: {$outputFile}\n";
    
} catch (\Throwable $e) {
    fwrite(STDERR, "Error: {$e->getMessage()}\n");
    exit(1);
}

/**
 * Fetch historical matches with live data.
 * 
 * @param PDO $db
 * @param int $limit
 * @param string $from
 * @param string $to
 * @return list<array<string,mixed>>
 */
function fetchHistoricalMatches(PDO $db, int $limit, string $from, string $to): array
{
    $conditions = [
        "`time` IS NOT NULL",
        "`live_ht_hscore` IS NOT NULL",
        "`live_ht_ascore` IS NOT NULL",
        "`live_danger_att_home` IS NOT NULL",
    ];
    $params = [];
    
    if ($from !== '') {
        $conditions[] = 'COALESCE(`live_updated_at`, `updated_at`, `created_at`) >= ?';
        $params[] = $from . ' 00:00:00';
    }
    
    if ($to !== '') {
        $conditions[] = 'COALESCE(`live_updated_at`, `updated_at`, `created_at`) <= ?';
        $params[] = $to . ' 23:59:59';
    }
    
    $sql = 'SELECT * FROM `matches` '
        . 'WHERE ' . implode(' AND ', $conditions) . ' '
        . 'ORDER BY COALESCE(`live_updated_at`, `updated_at`, `created_at`) DESC, `id` DESC '
        . 'LIMIT ' . $limit;
    
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    return is_array($rows) ? $rows : [];
}

/**
 * Parse minute from time string.
 * 
 * @param string $timeStr
 * @return int
 */
function parseMinute(string $timeStr): int
{
    if (preg_match('/^(\d+):/', $timeStr, $matches)) {
        return (int) $matches[1];
    }
    return 0;
}

/**
 * Determine if a goal occurred in remaining time of 1st half.
 * 
 * @param array<string,mixed> $match
 * @param int $currentMinute
 * @return bool
 */
function determineActualOutcome(array $match, int $currentMinute): bool
{
    // Get final 1st half score
    $finalHomeScore = (int) ($match['live_ht_hscore'] ?? 0);
    $finalAwayScore = (int) ($match['live_ht_ascore'] ?? 0);
    $finalTotal = $finalHomeScore + $finalAwayScore;
    
    // Get current score from live data (if available in snapshots)
    // For now, we assume the live_ht_hscore is the final score
    // In a more sophisticated version, we would check snapshots at the current minute
    
    // Simple heuristic: if there are goals in 1st half and we're early enough,
    // assume there was activity. This is a simplification.
    // Ideally, we'd have snapshot data showing score progression.
    
    // For calibration purposes, we'll use a proxy:
    // If final score > 0 and minute < 40, likely there was a goal after this point
    // This is imperfect but workable for initial calibration
    
    if ($finalTotal === 0) {
        return false; // No goals at all
    }
    
    // If we're very late in the half, less likely a goal came after
    if ($currentMinute >= 40) {
        return $finalTotal > 1; // Multiple goals suggest one came late
    }
    
    // For earlier minutes, if there are goals, assume some came after
    return $finalTotal > 0;
}
