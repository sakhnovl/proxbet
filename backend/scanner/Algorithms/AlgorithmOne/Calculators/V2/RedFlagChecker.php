<?php
declare(strict_types=1);

namespace Proxbet\Scanner\Algorithms\AlgorithmOne\Calculators\V2;

use Proxbet\Scanner\Algorithms\AlgorithmOne\Config;

/**
 * Check for red flags that block or amplify betting decisions
 */
final class RedFlagChecker
{
    /**
     * Collect all active flags for the current live state.
     *
     * @param array<string,mixed> $liveData
     * @return list<string>
     */
    public function collect(array $liveData, int $minute): array
    {
        $flags = [];

        if ($this->hasLowAccuracy($liveData)) {
            $flags[] = 'low_accuracy';
        }

        if ($this->hasIneffectivePressure($liveData)) {
            $flags[] = 'ineffective_pressure';
        }

        if ($this->hasXgMismatch($liveData, $minute)) {
            $flags[] = 'xg_mismatch';
        }

        return $flags;
    }

    /**
     * Check for red flags
     * 
     * @param array<string,mixed> $liveData
     * @param int $minute
     * @return string|null Red flag name or null
     */
    public function check(array $liveData, int $minute): ?string
    {
        return $this->collect($liveData, $minute)[0] ?? null;
    }
    
    /**
     * Check if shot accuracy is too low (< 25%)
     */
    private function hasLowAccuracy(array $liveData): bool
    {
        $totalShots = (int) ($liveData['shots_total'] ?? 0);
        $shotsOnTarget = (int) ($liveData['shots_on_target'] ?? 0);
        
        if ($totalShots === 0) {
            return false;
        }
        
        return ($shotsOnTarget / $totalShots) < 0.25;
    }
    
    /**
     * Check if pressure is ineffective (many attacks but few shots on target)
     */
    private function hasIneffectivePressure(array $liveData): bool
    {
        $dangerHome = (int) ($liveData['dangerous_attacks_home'] ?? 0);
        $dangerAway = (int) ($liveData['dangerous_attacks_away'] ?? 0);
        $shotsOnTargetHome = (int) ($liveData['shots_on_target_home'] ?? 0);
        $shotsOnTargetAway = (int) ($liveData['shots_on_target_away'] ?? 0);
        
        return ($dangerHome > Config::V2_INEFFECTIVE_PRESSURE_THRESHOLD && $shotsOnTargetHome < 2)
            || ($dangerAway > Config::V2_INEFFECTIVE_PRESSURE_THRESHOLD && $shotsOnTargetAway < 2);
    }
    
    /**
     * Check if xG doesn't match score (amplifier for time pressure)
     */
    private function hasXgMismatch(array $liveData, int $minute): bool
    {
        if ($minute < 25) {
            return false;
        }
        
        $xgHome = $liveData['xg_home'] ?? null;
        $xgAway = $liveData['xg_away'] ?? null;
        
        if ($xgHome === null || $xgAway === null) {
            return false;
        }
        
        $totalXg = (float) $xgHome + (float) $xgAway;
        $htHscore = (int) ($liveData['ht_hscore'] ?? 0);
        $htAscore = (int) ($liveData['ht_ascore'] ?? 0);
        
        return $totalXg > 1.2 && $htHscore === 0 && $htAscore === 0;
    }
}
