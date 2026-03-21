<?php
declare(strict_types=1);

namespace Proxbet\Scanner\Algorithms\AlgorithmOne\Calculators\V2;

use Proxbet\Scanner\Algorithms\AlgorithmOne\Config;

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
            return $quality * Config::V2_SHOT_QUALITY_XG_WEIGHT
                + $accuracy * Config::V2_SHOT_QUALITY_ACCURACY_WEIGHT;
        }

        $corners = (int) ($liveData['corners'] ?? 0);
        $trendShotsOnTargetDelta = max(0.0, (float) ($liveData['trend_shots_on_target_delta'] ?? 0.0));

        return min(
            1.0,
            $accuracy * Config::V2_SHOT_QUALITY_FALLBACK_ACCURACY_WEIGHT
            + min($shotsOnTarget / 4.0, 1.0) * Config::V2_SHOT_QUALITY_FALLBACK_SOT_WEIGHT
            + min($totalShots / 10.0, 1.0) * Config::V2_SHOT_QUALITY_FALLBACK_VOLUME_WEIGHT
            + min($corners / 5.0, 1.0) * Config::V2_SHOT_QUALITY_FALLBACK_CORNERS_WEIGHT
            + min($trendShotsOnTargetDelta / 3.0, 1.0) * Config::V2_SHOT_QUALITY_FALLBACK_TREND_WEIGHT
        );
    }
}
