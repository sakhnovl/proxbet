<?php
declare(strict_types=1);

namespace Proxbet\Scanner\Algorithms\AlgorithmOne\Calculators\V2;

/**
 * Calculate xG pressure
 */
final class XgPressureCalculator
{
    /**
     * Calculate xG pressure
     * Formula: total_xg / 1.5
     * Clamped to 0-1
     * 
     * @param array<string,mixed> $liveData
     * @return float Score from 0.0 to 1.0
     */
    public function calculate(array $liveData): float
    {
        $xgHome = $liveData['xg_home'] ?? null;
        $xgAway = $liveData['xg_away'] ?? null;
        
        if ($xgHome === null || $xgAway === null) {
            return 0.0;
        }
        
        $totalXg = (float) $xgHome + (float) $xgAway;
        $normalized = $totalXg / 1.5;
        
        return min($normalized, 1.0);
    }
}
