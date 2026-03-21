<?php
declare(strict_types=1);

namespace Proxbet\Scanner\Algorithms\AlgorithmOne\Calculators\V2;

/**
 * Calculate shot quality based on xG and accuracy
 */
final class ShotQualityCalculator
{
    /**
     * Calculate shot quality
     * Formula: quality*0.7 + accuracy*0.3 (with xG)
     * Fallback: accuracy only (without xG)
     * 
     * @param array<string,mixed> $liveData
     * @return float Score from 0.0 to 1.0
     */
    public function calculate(array $liveData): float
    {
        $totalShots = (int) ($liveData['shots_total'] ?? 0);
        $shotsOnTarget = (int) ($liveData['shots_on_target'] ?? 0);
        
        if ($totalShots === 0) {
            return 0.0;
        }
        
        $accuracy = $shotsOnTarget / $totalShots;
        
        $xgHome = $liveData['xg_home'] ?? null;
        $xgAway = $liveData['xg_away'] ?? null;
        
        if ($xgHome !== null && $xgAway !== null) {
            $totalXg = (float) $xgHome + (float) $xgAway;
            $xgPerShot = $totalXg / $totalShots;
            $quality = min($xgPerShot / 0.33, 1.0);
            return $quality * 0.7 + $accuracy * 0.3;
        }
        
        return $accuracy;
    }
}
