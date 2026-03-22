<?php

declare(strict_types=1);

/**
 * Generate calibration report for AlgorithmX.
 * 
 * Analyzes historical data and generates comprehensive calibration report
 * with quality metrics, calibration curves, and recommendations.
 * 
 * Usage:
 *   php scripts/algorithmx_report_calibration.php --input=data/algorithmx_historical_data.json
 */

require_once __DIR__ . '/../backend/bootstrap/autoload.php';
require_once __DIR__ . '/../backend/bootstrap/runtime.php';

use Proxbet\Scanner\Algorithms\AlgorithmX\Metrics\QualityMetrics;
use Proxbet\Scanner\Algorithms\AlgorithmX\Config;

proxbet_bootstrap_env();

$options = getopt('', ['input::', 'output::']);
$inputFile = isset($options['input'])
    ? (string) $options['input']
    : (__DIR__ . '/../data/algorithmx_historical_data.json');
$outputFile = isset($options['output'])
    ? (string) $options['output']
    : (__DIR__ . '/../docs/reports/algorithmx_calibration_report.md');

try {
    // Load historical data
    if (!file_exists($inputFile)) {
        throw new \RuntimeException("Input file not found: {$inputFile}");
    }
    
    $data = json_decode(file_get_contents($inputFile), true);
    if (!is_array($data) || !isset($data['results'])) {
        throw new \RuntimeException("Invalid input file format");
    }
    
    $results = $data['results'];
    $summary = $data['summary'] ?? [];
    
    // Prepare predictions for metrics
    $predictions = [];
    foreach ($results as $result) {
        $predictions[] = [
            'predicted' => (float) ($result['predicted_probability'] ?? 0.0),
            'actual' => (bool) ($result['actual_goal'] ?? false),
        ];
    }
    
    $metrics = new QualityMetrics();
    $metricsData = $metrics->calculateMetrics($predictions);
    $calibrationCurve = $metrics->calculateCalibrationCurve($predictions, 10);
    
    // Generate report
    $report = generateMarkdownReport($data, $metricsData, $calibrationCurve);
    
    // Save report
    $outputDir = dirname($outputFile);
    if (!is_dir($outputDir)) {
        mkdir($outputDir, 0777, true);
    }
    
    file_put_contents($outputFile, $report);
    
    // Also print to console
    echo $report;
    echo "\nReport saved to: {$outputFile}\n";
    
} catch (\Throwable $e) {
    fwrite(STDERR, "Error: {$e->getMessage()}\n");
    exit(1);
}

/**
 * Generate markdown calibration report.
 * 
 * @param array<string,mixed> $data
 * @param array<string,mixed> $metricsData
 * @param array<array<string,mixed>> $calibrationCurve
 * @return string
 */
function generateMarkdownReport(array $data, array $metricsData, array $calibrationCurve): string
{
    $generatedAt = $data['generated_at'] ?? date('Y-m-d H:i:s');
    $summary = $data['summary'] ?? [];
    $results = $data['results'] ?? [];
    
    $lines = [];
    $lines[] = "# AlgorithmX Calibration Report";
    $lines[] = "";
    $lines[] = "**Generated:** {$generatedAt}";
    $lines[] = "";
    
    // Summary
    $lines[] = "## Summary";
    $lines[] = "";
    $lines[] = "| Metric | Value |";
    $lines[] = "|--------|-------|";
    $lines[] = "| Total Matches | " . ($summary['total_matches'] ?? 0) . " |";
    $lines[] = "| Processed | " . ($summary['processed'] ?? 0) . " |";
    $lines[] = "| Skipped | " . ($summary['skipped'] ?? 0) . " |";
    $lines[] = "| Goals Occurred | " . ($summary['goals_occurred'] ?? 0) . " |";
    $lines[] = "| No Goals | " . ($summary['no_goals'] ?? 0) . " |";
    $lines[] = "| Base Rate | " . sprintf("%.2f%%", (($summary['goals_occurred'] ?? 0) / max(1, ($summary['processed'] ?? 1))) * 100) . " |";
    $lines[] = "";
    
    // Current Parameters
    $lines[] = "## Current Parameters";
    $lines[] = "";
    $lines[] = "| Parameter | Value |";
    $lines[] = "|-----------|-------|";
    $lines[] = "| Sigmoid K | " . Config::getSigmoidK() . " |";
    $lines[] = "| Sigmoid Threshold | " . Config::getSigmoidThreshold() . " |";
    $weights = Config::getAisWeights();
    $lines[] = "| Weight: Dangerous Attacks | " . $weights['dangerous_attacks'] . " |";
    $lines[] = "| Weight: Shots | " . $weights['shots'] . " |";
    $lines[] = "| Weight: Shots on Target | " . $weights['shots_on_target'] . " |";
    $lines[] = "| Weight: Corners | " . $weights['corners'] . " |";
    $lines[] = "";
    
    // Quality Metrics
    $lines[] = "## Quality Metrics";
    $lines[] = "";
    $brierScore = $metricsData['brier_score'];
    $rocAuc = $metricsData['roc_auc'];
    
    $lines[] = "| Metric | Value | Target | Status |";
    $lines[] = "|--------|-------|--------|--------|";
    $lines[] = sprintf(
        "| Brier Score | %.4f | < 0.20 | %s |",
        $brierScore,
        $brierScore < 0.20 ? '✅ PASS' : '❌ FAIL'
    );
    $lines[] = sprintf(
        "| ROC-AUC | %.4f | > 0.68 | %s |",
        $rocAuc,
        $rocAuc > 0.68 ? '✅ PASS' : '❌ FAIL'
    );
    $lines[] = "| Total Predictions | " . $metricsData['total_predictions'] . " | - | - |";
    $lines[] = "| Positive Cases | " . $metricsData['positive_cases'] . " | - | - |";
    $lines[] = "| Negative Cases | " . $metricsData['negative_cases'] . " | - | - |";
    $lines[] = "";
    
    // Interpretation
    $lines[] = "### Interpretation";
    $lines[] = "";
    $lines[] = "**Brier Score:** " . interpretBrierScore($brierScore);
    $lines[] = "";
    $lines[] = "**ROC-AUC:** " . interpretRocAuc($rocAuc);
    $lines[] = "";
    
    // Calibration Curve
    $lines[] = "## Calibration Curve";
    $lines[] = "";
    $lines[] = "Shows how well predicted probabilities match actual frequencies.";
    $lines[] = "Perfect calibration = predicted = actual.";
    $lines[] = "";
    $lines[] = "| Bin | Predicted Mean | Actual Frequency | Count | Calibration |";
    $lines[] = "|-----|----------------|------------------|-------|-------------|";
    
    foreach ($calibrationCurve as $bin) {
        $predicted = $bin['predicted_mean'];
        $actual = $bin['actual_frequency'];
        $diff = abs($predicted - $actual);
        $status = $diff < 0.10 ? '✅ Good' : ($diff < 0.20 ? '⚠️ Fair' : '❌ Poor');
        
        $lines[] = sprintf(
            "| %s | %.3f | %.3f | %d | %s |",
            $bin['bin'],
            $predicted,
            $actual,
            $bin['count'],
            $status
        );
    }
    $lines[] = "";
    
    // Probability Distribution
    $lines[] = "## Probability Distribution";
    $lines[] = "";
    $probBins = analyzeProbabilityDistribution($results);
    $lines[] = "| Range | Count | Percentage | Bet Rate |";
    $lines[] = "|-------|-------|------------|----------|";
    
    foreach ($probBins as $range => $stats) {
        $lines[] = sprintf(
            "| %s | %d | %.1f%% | %.1f%% |",
            $range,
            $stats['count'],
            $stats['percentage'],
            $stats['bet_rate']
        );
    }
    $lines[] = "";
    
    // Recommendations
    $lines[] = "## Recommendations";
    $lines[] = "";
    $lines[] = generateRecommendations($brierScore, $rocAuc, $calibrationCurve);
    $lines[] = "";
    
    // Next Steps
    $lines[] = "## Next Steps";
    $lines[] = "";
    $lines[] = "1. **If metrics are poor:** Run `php scripts/algorithmx_calibrate_parameters.php` to find optimal parameters";
    $lines[] = "2. **Update Config.php:** Apply the best parameters from calibration";
    $lines[] = "3. **Re-collect data:** Run collection script again with new parameters";
    $lines[] = "4. **Validate:** Generate new report to confirm improvements";
    $lines[] = "5. **Deploy:** Update environment variables and deploy to production";
    $lines[] = "";
    
    return implode("\n", $lines);
}

function interpretBrierScore(float $score): string
{
    if ($score < 0.10) {
        return "Excellent calibration. Predictions are highly accurate.";
    } elseif ($score < 0.20) {
        return "Good calibration. Predictions are reliable for betting decisions.";
    } elseif ($score < 0.30) {
        return "Fair calibration. Consider parameter tuning to improve accuracy.";
    } else {
        return "Poor calibration. Significant parameter optimization needed.";
    }
}

function interpretRocAuc(float $auc): string
{
    if ($auc > 0.80) {
        return "Excellent discrimination. Model distinguishes well between goal/no-goal scenarios.";
    } elseif ($auc > 0.68) {
        return "Good discrimination. Model has acceptable predictive power.";
    } elseif ($auc > 0.60) {
        return "Fair discrimination. Model shows some predictive ability but needs improvement.";
    } else {
        return "Poor discrimination. Model barely better than random guessing.";
    }
}

/**
 * @param array<array<string,mixed>> $results
 * @return array<string,array{count:int,percentage:float,bet_rate:float}>
 */
function analyzeProbabilityDistribution(array $results): array
{
    $bins = [
        '0.00-0.20' => ['count' => 0, 'bets' => 0],
        '0.20-0.40' => ['count' => 0, 'bets' => 0],
        '0.40-0.60' => ['count' => 0, 'bets' => 0],
        '0.60-0.80' => ['count' => 0, 'bets' => 0],
        '0.80-1.00' => ['count' => 0, 'bets' => 0],
    ];
    
    foreach ($results as $result) {
        $prob = (float) ($result['predicted_probability'] ?? 0.0);
        $bet = (bool) ($result['bet_decision'] ?? false);
        
        $range = match (true) {
            $prob < 0.20 => '0.00-0.20',
            $prob < 0.40 => '0.20-0.40',
            $prob < 0.60 => '0.40-0.60',
            $prob < 0.80 => '0.60-0.80',
            default => '0.80-1.00',
        };
        
        $bins[$range]['count']++;
        if ($bet) {
            $bins[$range]['bets']++;
        }
    }
    
    $total = max(1, count($results));
    $output = [];
    
    foreach ($bins as $range => $data) {
        $output[$range] = [
            'count' => $data['count'],
            'percentage' => ($data['count'] / $total) * 100,
            'bet_rate' => $data['count'] > 0 ? ($data['bets'] / $data['count']) * 100 : 0.0,
        ];
    }
    
    return $output;
}

/**
 * @param array<array<string,mixed>> $calibrationCurve
 */
function generateRecommendations(float $brierScore, float $rocAuc, array $calibrationCurve): string
{
    $recommendations = [];
    
    if ($brierScore >= 0.20) {
        $recommendations[] = "- **Brier Score is high:** Run parameter calibration to optimize k and threshold values";
    }
    
    if ($rocAuc < 0.68) {
        $recommendations[] = "- **ROC-AUC is low:** Consider adding more features or adjusting AIS weights";
    }
    
    // Check calibration curve for systematic bias
    $overestimating = 0;
    $underestimating = 0;
    
    foreach ($calibrationCurve as $bin) {
        $diff = $bin['predicted_mean'] - $bin['actual_frequency'];
        if ($diff > 0.10) {
            $overestimating++;
        } elseif ($diff < -0.10) {
            $underestimating++;
        }
    }
    
    if ($overestimating > count($calibrationCurve) / 2) {
        $recommendations[] = "- **Systematic overestimation:** Model predicts higher probabilities than actual. Increase sigmoid threshold or decrease k";
    }
    
    if ($underestimating > count($calibrationCurve) / 2) {
        $recommendations[] = "- **Systematic underestimation:** Model predicts lower probabilities than actual. Decrease sigmoid threshold or increase k";
    }
    
    if ($recommendations === []) {
        return "✅ **No major issues detected.** Current parameters appear well-calibrated.";
    }
    
    return implode("\n", $recommendations);
}
