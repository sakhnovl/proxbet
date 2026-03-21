<?php
declare(strict_types=1);

namespace Proxbet\Scanner\Algorithms\AlgorithmOne\Calculators\V2;

/**
 * Calculate league factor based on table_avg
 */
final class LeagueFactorCalculator
{
    /**
     * Calculate league factor
     * Formula: table_avg / 2.5
     * Clamped to 0.7-1.3
     * 
     * @param array<string,mixed> $liveData
     * @return float Factor from 0.7 to 1.3
     */
    public function calculate(array $liveData): float
    {
        $tableAvg = $liveData['table_avg'] ?? null;
        
        if ($tableAvg === null) {
            return 1.0;
        }
        
        $factor = (float) $tableAvg / 2.5;
        
        return max(0.7, min(1.3, $factor));
    }
}
