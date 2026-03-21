<?php
declare(strict_types=1);

namespace Proxbet\Scanner\Algorithms\AlgorithmOne\Calculators\V2;

use Proxbet\Scanner\Algorithms\AlgorithmOne\Config;

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
            return $this->calculateFallbackPressure($liveData);
        }
        
        $totalXg = (float) $xgHome + (float) $xgAway;
        $normalized = $totalXg / Config::V2_XG_NORMALIZATION_FACTOR;
        
        return min($normalized, 1.0);
    }

    /**
     * @param array<string,mixed> $liveData
     */
    private function calculateFallbackPressure(array $liveData): float
    {
        $shotsTotal = (int) ($liveData['shots_total'] ?? 0);
        $shotsOnTarget = (int) ($liveData['shots_on_target'] ?? 0);
        $dangerousAttacks = (int) ($liveData['dangerous_attacks'] ?? 0);
        $corners = (int) ($liveData['corners'] ?? 0);
        $trendDanger = max(0.0, (float) ($liveData['trend_dangerous_attacks_delta'] ?? 0.0));
        $trendShots = max(0.0, (float) ($liveData['trend_shots_total_delta'] ?? 0.0));
        $trendShotsOnTarget = max(0.0, (float) ($liveData['trend_shots_on_target_delta'] ?? 0.0));

        $fallback = min(
            1.0,
            min($shotsTotal / 12.0, 1.0) * 0.20
            + min($shotsOnTarget / 4.0, 1.0) * 0.30
            + min($dangerousAttacks / 45.0, 1.0) * 0.25
            + min($corners / 5.0, 1.0) * 0.10
            + min(($trendDanger / 18.0) + ($trendShots / 10.0) + ($trendShotsOnTarget / 3.0), 1.0) * 0.15
        );

        return min(1.0, $fallback * Config::V2_XG_FALLBACK_SCALE);
    }
}
