<?php

declare(strict_types=1);

namespace Proxbet\Scanner\Algorithms\AlgorithmOne\Calculators;

/**
 * Calculate form score based on last 5 matches.
 */
final class FormScoreCalculator
{
    /**
     * Calculate form score based on last 5 matches.
     * 
     * @param array{home_goals:int,away_goals:int,has_data:bool,weighted?:array|null} $formData
     * @return float Score from 0.0 to 1.0
     */
    public function calculate(array $formData): float
    {
        if (!$formData['has_data']) {
            return 0.0;
        }

        // Use weighted if available (v2)
        if (isset($formData['weighted']['score'])) {
            return $this->clamp($formData['weighted']['score']);
        }

        // Legacy calculation
        $homeScore = $formData['home_goals'] / 5.0;
        $awayScore = $formData['away_goals'] / 5.0;
        
        return ($homeScore + $awayScore) / 2.0;
    }

    private function clamp(float $value): float
    {
        return max(0.0, min(1.0, $value));
    }
}
