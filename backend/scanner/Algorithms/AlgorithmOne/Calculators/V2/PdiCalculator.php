<?php
declare(strict_types=1);

namespace Proxbet\Scanner\Algorithms\AlgorithmOne\Calculators\V2;

use Proxbet\Scanner\Algorithms\AlgorithmOne\Config;

/**
 * Calculate Pressure Dominance Index
 */
final class PdiCalculator
{
    /**
     * Calculate PDI based on dangerous attacks balance and intensity
     * 
     * @param array<string,mixed> $liveData
     * @return float Score from 0.0 to 1.0
     */
    public function calculate(array $liveData): float
    {
        $dangerHome = (int) ($liveData['dangerous_attacks_home'] ?? 0);
        $dangerAway = (int) ($liveData['dangerous_attacks_away'] ?? 0);
        $total = $dangerHome + $dangerAway;
        
        if ($total < Config::V2_PDI_MIN_ATTACKS) {
            return 0.0;
        }
        
        $balance = 1.0 - abs($dangerHome - $dangerAway) / $total;
        $intensity = min($total / 40.0, 1.0);
        
        return $balance * $intensity;
    }
}
