<?php
declare(strict_types=1);

namespace Proxbet\Scanner\Algorithms\AlgorithmOne\Calculators\V2;

/**
 * Calculate trend acceleration using velocity approach
 */
final class TrendCalculator
{
    /**
     * Calculate trend acceleration
     * 
     * @param array<string,mixed> $liveData
     * @return float Score from 0.0 to 1.0
     */
    public function calculate(array $liveData): float
    {
        $hasTrendData = $liveData['has_trend_data'] ?? false;
        
        if (!$hasTrendData) {
            return 0.0;
        }
        
        $windowSeconds = $liveData['trend_window_seconds'] ?? null;
        if ($windowSeconds === null || $windowSeconds <= 0) {
            return 0.0;
        }
        
        $windowMinutes = max(1.0, $windowSeconds / 60.0);
        
        // Calculate velocities (delta per minute)
        $shotsDelta = (int) ($liveData['trend_shots_total_delta'] ?? 0);
        $dangerDelta = (int) ($liveData['trend_dangerous_attacks_delta'] ?? 0);
        $xgDelta = (float) ($liveData['trend_xg_delta'] ?? 0.0);
        
        $shotsVelocity = $shotsDelta / $windowMinutes;
        $dangerVelocity = $dangerDelta / $windowMinutes;
        $xgVelocity = $xgDelta / $windowMinutes;
        
        // Normalize and weight components
        $score = (
            min($shotsVelocity / 5.0, 1.0) * 0.3 +
            min($dangerVelocity / 10.0, 1.0) * 0.5 +
            min($xgVelocity / 0.2, 1.0) * 0.2
        );
        
        return $this->clamp($score);
    }
    
    private function clamp(float $value): float
    {
        return max(0.0, min(1.0, $value));
    }
}
