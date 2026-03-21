<?php
declare(strict_types=1);

namespace Proxbet\Scanner\Algorithms\AlgorithmOne\Calculators\V2;

/**
 * Calculate card factor (can be negative)
 */
final class CardFactorCalculator
{
    /**
     * Calculate card factor based on yellow/red cards difference
     * Positive when away has more cards (advantage for home)
     * Negative when home has more cards (disadvantage)
     * 
     * @param array<string,mixed> $liveData
     * @return float Factor (can be negative)
     */
    public function calculate(array $liveData): float
    {
        $yellowHome = (int) ($liveData['yellow_cards_home'] ?? 0);
        $yellowAway = (int) ($liveData['yellow_cards_away'] ?? 0);
        $redHome = (int) ($liveData['red_cards_home'] ?? 0);
        $redAway = (int) ($liveData['red_cards_away'] ?? 0);
        
        $totalHome = $yellowHome + $redHome * 2;
        $totalAway = $yellowAway + $redAway * 2;
        
        $diff = $totalAway - $totalHome;
        
        if ($diff === 0) {
            return 0.0;
        }
        
        // Scaling: diff=1 → 0.03, diff=2 → 0.08, diff≥3 → 0.15
        if (abs($diff) === 1) {
            return $diff > 0 ? 0.03 : -0.03;
        } elseif (abs($diff) === 2) {
            return $diff > 0 ? 0.08 : -0.08;
        } else {
            return $diff > 0 ? 0.15 : -0.15;
        }
    }
}
