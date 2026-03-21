<?php

declare(strict_types=1);

namespace Proxbet\Scanner\Calculators;

/**
 * Form Score Calculator - calculates team form based on recent matches.
 */
final class FormScoreCalculator
{
    /**
     * Calculate form score based on last 5 matches.
     *
     * @param array{home_goals:int,away_goals:int,has_data:bool,weighted?:array<string,mixed>|null} $formData
     * @return float Score from 0.0 to 1.0
     */
    public function calculate(array $formData): float
    {
        if (!$formData['has_data']) {
            return 0.0;
        }

        // Use weighted metrics if available
        if (isset($formData['weighted']['score'])) {
            return $this->clamp($formData['weighted']['score']);
        }

        // Fallback to simple calculation
        return $this->calculateSimple($formData);
    }

    /**
     * Calculate simple form score.
     *
     * @param array{home_goals:int,away_goals:int,has_data:bool} $formData
     */
    private function calculateSimple(array $formData): float
    {
        $homeScore = $formData['home_goals'] / 5.0;
        $awayScore = $formData['away_goals'] / 5.0;

        return ($homeScore + $awayScore) / 2.0;
    }

    /**
     * Extract form components for detailed output.
     *
     * @param array{home_goals:int,away_goals:int,has_data:bool,weighted?:array<string,mixed>|null} $formData
     * @return array{weighted_score:float,home:array{attack:float,defense:float},away:array{attack:float,defense:float}}
     */
    public function extractComponents(array $formData, float $formScore): array
    {
        if (isset($formData['weighted'])) {
            return [
                'weighted_score' => $formScore,
                'home' => $formData['weighted']['home'] ?? ['attack' => 0.0, 'defense' => 0.0],
                'away' => $formData['weighted']['away'] ?? ['attack' => 0.0, 'defense' => 0.0],
            ];
        }

        return [
            'weighted_score' => $formScore,
            'home' => ['attack' => 0.0, 'defense' => 0.0],
            'away' => ['attack' => 0.0, 'defense' => 0.0],
        ];
    }

    private function clamp(float $value): float
    {
        return max(0.0, min(1.0, $value));
    }
}
