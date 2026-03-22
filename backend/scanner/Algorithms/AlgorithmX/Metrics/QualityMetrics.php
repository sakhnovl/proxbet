<?php

declare(strict_types=1);

namespace Proxbet\Scanner\Algorithms\AlgorithmX\Metrics;

/**
 * Quality metrics calculator for probability predictions.
 * 
 * Implements Brier Score and ROC-AUC for model calibration.
 */
final class QualityMetrics
{
    /**
     * Calculate Brier Score.
     * 
     * Measures accuracy of probabilistic predictions.
     * Lower is better. Perfect score = 0.0, worst = 1.0.
     * Target: < 0.20 for good calibration.
     * 
     * @param array<array{predicted:float,actual:bool}> $predictions
     * @return float
     */
    public function calculateBrierScore(array $predictions): float
    {
        if ($predictions === []) {
            return 1.0;
        }
        
        $sum = 0.0;
        foreach ($predictions as $prediction) {
            $predicted = (float) $prediction['predicted'];
            $actual = $prediction['actual'] ? 1.0 : 0.0;
            $sum += ($predicted - $actual) ** 2;
        }
        
        return $sum / count($predictions);
    }
    
    /**
     * Calculate ROC-AUC (Area Under Receiver Operating Characteristic Curve).
     * 
     * Measures ability to distinguish between classes.
     * Higher is better. 0.5 = random, 1.0 = perfect.
     * Target: > 0.68 for acceptable discrimination.
     * 
     * @param array<array{predicted:float,actual:bool}> $predictions
     * @return float
     */
    public function calculateRocAuc(array $predictions): float
    {
        if ($predictions === []) {
            return 0.5;
        }
        
        // Separate positive and negative cases
        $positives = [];
        $negatives = [];
        
        foreach ($predictions as $prediction) {
            $predicted = (float) $prediction['predicted'];
            $actual = $prediction['actual'];
            
            if ($actual) {
                $positives[] = $predicted;
            } else {
                $negatives[] = $predicted;
            }
        }
        
        if ($positives === [] || $negatives === []) {
            return 0.5;
        }
        
        // Count pairs where positive score > negative score
        $concordant = 0;
        $total = count($positives) * count($negatives);
        
        foreach ($positives as $posScore) {
            foreach ($negatives as $negScore) {
                if ($posScore > $negScore) {
                    $concordant++;
                } elseif ($posScore === $negScore) {
                    $concordant += 0.5; // Tie
                }
            }
        }
        
        return $concordant / $total;
    }
    
    /**
     * Calculate calibration curve data.
     * 
     * Groups predictions into bins and compares predicted vs actual frequencies.
     * 
     * @param array<array{predicted:float,actual:bool}> $predictions
     * @param int $bins Number of bins (default 10)
     * @return array<array{bin:string,predicted_mean:float,actual_frequency:float,count:int}>
     */
    public function calculateCalibrationCurve(array $predictions, int $bins = 10): array
    {
        if ($predictions === []) {
            return [];
        }
        
        $binSize = 1.0 / $bins;
        /** @var array<int, array{predicted_sum: float, actual_sum: int, count: int}> $binData */
        $binData = [];
        
        foreach ($predictions as $prediction) {
            $predicted = (float) $prediction['predicted'];
            $actual = $prediction['actual'];
            
            $binIndex = min($bins - 1, (int) floor($predicted / $binSize));

            if (!isset($binData[$binIndex])) {
                $binData[$binIndex] = [
                    'predicted_sum' => 0.0,
                    'actual_sum' => 0,
                    'count' => 0,
                ];
            }
            
            $binData[$binIndex]['predicted_sum'] += $predicted;
            $binData[$binIndex]['actual_sum'] += $actual ? 1 : 0;
            $binData[$binIndex]['count']++;
        }
        
        $result = [];
        foreach ($binData as $index => $data) {
            $count = (int) $data['count'];
            $result[] = [
                'bin' => sprintf('%.2f-%.2f', $index * $binSize, ($index + 1) * $binSize),
                'predicted_mean' => $data['predicted_sum'] / $count,
                'actual_frequency' => $data['actual_sum'] / $count,
                'count' => $count,
            ];
        }
        
        return $result;
    }
    
    /**
     * Calculate comprehensive metrics report.
     * 
     * @param array<array{predicted:float,actual:bool}> $predictions
     * @return array{brier_score:float,roc_auc:float,total_predictions:int,positive_cases:int,negative_cases:int}
     */
    public function calculateMetrics(array $predictions): array
    {
        $positives = 0;
        $negatives = 0;
        
        foreach ($predictions as $prediction) {
            if ($prediction['actual']) {
                $positives++;
            } else {
                $negatives++;
            }
        }
        
        return [
            'brier_score' => $this->calculateBrierScore($predictions),
            'roc_auc' => $this->calculateRocAuc($predictions),
            'total_predictions' => count($predictions),
            'positive_cases' => $positives,
            'negative_cases' => $negatives,
        ];
    }
}
