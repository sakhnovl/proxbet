<?php

declare(strict_types=1);

/**
 * Calibrate AlgorithmX parameters using historical data.
 * 
 * Performs grid search to find optimal values for:
 * - k (sigmoid steepness)
 * - threshold (sigmoid calibration point)
 * - AIS weights (dangerous_attacks, shots, shots_on_target, corners)
 * 
 * Usage:
 *   php scripts/algorithmx_calibrate_parameters.php --input=data/algorithmx_historical_data.json
 */

require_once __DIR__ . '/../backend/bootstrap/autoload.php';
require_once __DIR__ . '/../backend/bootstrap/runtime.php';

use Proxbet\Scanner\Algorithms\AlgorithmX\Metrics\QualityMetrics;
use Proxbet\Scanner\Algorithms\AlgorithmX\Calculators\AisCalculator;

proxbet_bootstrap_env();

$options = getopt('', ['input::', 'output::']);
$inputFile = isset($options['input'])
    ? (string) $options['input']
    : (__DIR__ . '/../data/algorithmx_historical_data.json');
$outputFile = isset($options['output'])
    ? (string) $options['output']
    : (__DIR__ . '/../data/algorithmx_calibration_results.json');

try {
    echo "AlgorithmX Parameter Calibration\n";
    echo "=================================\n\n";
    
    // Load historical data
    if (!file_exists($inputFile)) {
        throw new \RuntimeException("Input file not found: {$inputFile}");
    }
    
    $data = json_decode(file_get_contents($inputFile), true);
    if (!is_array($data) || !isset($data['results'])) {
        throw new \RuntimeException("Invalid input file format");
    }
    
    $results = $data['results'];
    echo "Loaded " . count($results) . " historical predictions\n\n";
    
    // Define parameter search space
    $kValues = [1.5, 2.0, 2.5, 3.0, 3.5];
    $thresholdValues = [1.2, 1.5, 1.8, 2.0, 2.3];
    $weightSets = [
        'current' => [0.4, 0.3, 0.2, 0.1],
        'shots_heavy' => [0.3, 0.4, 0.2, 0.1],
        'balanced' => [0.35, 0.25, 0.25, 0.15],
        'danger_heavy' => [0.5, 0.25, 0.15, 0.1],
        'quality_focused' => [0.3, 0.2, 0.4, 0.1],
    ];
    
    $metrics = new QualityMetrics();
    $bestScore = PHP_FLOAT_MAX;
    $bestParams = null;
    $allResults = [];
    
    $totalCombinations = count($kValues) * count($thresholdValues) * count($weightSets);
    $current = 0;
    
    echo "Testing {$totalCombinations} parameter combinations...\n\n";
    
    // Grid search
    foreach ($kValues as $k) {
        foreach ($thresholdValues as $threshold) {
            foreach ($weightSets as $weightName => $weights) {
                $current++;
                
                // Recalculate predictions with these parameters
                $predictions = recalculatePredictions($results, $k, $threshold, $weights);
                
                // Calculate metrics
                $metricsData = $metrics->calculateMetrics($predictions);
                $brierScore = $metricsData['brier_score'];
                $rocAuc = $metricsData['roc_auc'];
                
                // Combined score (lower is better)
                // Brier Score (0-1, lower better) + (1 - ROC-AUC) (0-1, lower better)
                $combinedScore = $brierScore + (1.0 - $rocAuc);
                
                $result = [
                    'k' => $k,
                    'threshold' => $threshold,
                    'weights' => $weights,
                    'weight_name' => $weightName,
                    'brier_score' => $brierScore,
                    'roc_auc' => $rocAuc,
                    'combined_score' => $combinedScore,
                ];
                
                $allResults[] = $result;
                
                if ($combinedScore < $bestScore) {
                    $bestScore = $combinedScore;
                    $bestParams = $result;
                }
                
                if ($current % 10 === 0) {
                    echo "Progress: {$current}/{$totalCombinations} ";
                    echo sprintf("(Best: Brier=%.4f, AUC=%.4f)\n", $bestParams['brier_score'], $bestParams['roc_auc']);
                }
            }
        }
    }
    
    echo "\n";
    echo "Calibration Complete!\n";
    echo "=====================\n\n";
    
    echo "Best Parameters:\n";
    echo "- k (sigmoid steepness): " . $bestParams['k'] . "\n";
    echo "- threshold (calibration point): " . $bestParams['threshold'] . "\n";
    echo "- weights ({$bestParams['weight_name']}): [" . implode(', ', $bestParams['weights']) . "]\n";
    echo "\n";
    
    echo "Quality Metrics:\n";
    echo "- Brier Score: " . sprintf("%.4f", $bestParams['brier_score']) . " (target: < 0.20)\n";
    echo "- ROC-AUC: " . sprintf("%.4f", $bestParams['roc_auc']) . " (target: > 0.68)\n";
    echo "- Combined Score: " . sprintf("%.4f", $bestParams['combined_score']) . "\n";
    echo "\n";
    
    // Show top 5 configurations
    usort($allResults, fn($a, $b) => $a['combined_score'] <=> $b['combined_score']);
    $top5 = array_slice($allResults, 0, 5);
    
    echo "Top 5 Configurations:\n";
    echo str_pad('Rank', 6) . str_pad('k', 8) . str_pad('thresh', 10) . str_pad('weights', 20) 
        . str_pad('Brier', 10) . str_pad('AUC', 10) . "Combined\n";
    echo str_repeat('-', 74) . "\n";
    
    foreach ($top5 as $rank => $result) {
        echo str_pad((string)($rank + 1), 6)
            . str_pad((string)$result['k'], 8)
            . str_pad((string)$result['threshold'], 10)
            . str_pad($result['weight_name'], 20)
            . str_pad(sprintf('%.4f', $result['brier_score']), 10)
            . str_pad(sprintf('%.4f', $result['roc_auc']), 10)
            . sprintf('%.4f', $result['combined_score']) . "\n";
    }
    
    // Save results
    $outputData = [
        'generated_at' => date('Y-m-d H:i:s'),
        'input_file' => $inputFile,
        'total_predictions' => count($results),
        'best_parameters' => $bestParams,
        'top_5' => $top5,
        'all_results' => $allResults,
    ];
    
    $outputDir = dirname($outputFile);
    if (!is_dir($outputDir)) {
        mkdir($outputDir, 0777, true);
    }
    
    file_put_contents($outputFile, json_encode($outputData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    echo "\nResults saved to: {$outputFile}\n";
    
} catch (\Throwable $e) {
    fwrite(STDERR, "Error: {$e->getMessage()}\n");
    exit(1);
}

/**
 * Recalculate predictions with different parameters.
 * 
 * @param array<array<string,mixed>> $results
 * @param float $k
 * @param float $threshold
 * @param array<float> $weights
 * @return array<array{predicted:float,actual:bool}>
 */
function recalculatePredictions(array $results, float $k, float $threshold, array $weights): array
{
    $predictions = [];
    
    foreach ($results as $result) {
        $liveData = $result['live_data'];
        $minute = (int) ($liveData['minute'] ?? 0);
        
        if ($minute === 0) {
            continue;
        }
        
        // Recalculate AIS with new weights
        $aisHome = ($liveData['dangerous_attacks_home'] * $weights[0])
                 + ($liveData['shots_home'] * $weights[1])
                 + ($liveData['shots_on_target_home'] * $weights[2])
                 + ($liveData['corners_home'] * $weights[3]);
        
        $aisAway = ($liveData['dangerous_attacks_away'] * $weights[0])
                 + ($liveData['shots_away'] * $weights[1])
                 + ($liveData['shots_on_target_away'] * $weights[2])
                 + ($liveData['corners_away'] * $weights[3]);
        
        $aisTotal = $aisHome + $aisAway;
        $aisRate = $aisTotal / $minute;
        
        // Recalculate probability with new k and threshold
        $baseProb = 1.0 / (1.0 + exp(-$k * ($aisRate - $threshold)));
        
        // Apply time factor
        $timeRemaining = 45 - $minute;
        $timeFactor = $timeRemaining / 45.0;
        $probAdjusted = $baseProb * (0.4 + 0.6 * $timeFactor);
        
        // Apply score modifier
        $scoreHome = (int) ($liveData['score_home'] ?? 0);
        $scoreAway = (int) ($liveData['score_away'] ?? 0);
        $scoreDiff = abs($scoreHome - $scoreAway);
        
        $scoreModifier = match (true) {
            $scoreDiff === 0 => 1.05,
            $scoreDiff === 1 => 1.10,
            default => 0.90,
        };
        
        $probFinal = $probAdjusted * $scoreModifier;
        
        // Apply dry period modifier
        if ($scoreHome === 0 && $scoreAway === 0 && $minute > 30) {
            $probFinal *= 0.92;
        }
        
        // Clamp
        $probFinal = max(0.03, min(0.97, $probFinal));
        
        $predictions[] = [
            'predicted' => $probFinal,
            'actual' => (bool) ($result['actual_goal'] ?? false),
        ];
    }
    
    return $predictions;
}
