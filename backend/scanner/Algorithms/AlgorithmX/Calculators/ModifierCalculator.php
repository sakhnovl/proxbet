<?php

declare(strict_types=1);

namespace Proxbet\Scanner\Algorithms\AlgorithmX\Calculators;

use Proxbet\Scanner\Algorithms\AlgorithmX\Config;

/**
 * Modifier Calculator.
 * 
 * Applies various modifiers to the base probability:
 * - Time factor: Adjusts based on remaining time in first half
 * - Score modifier: Adjusts based on current score difference
 * - Dry period modifier: Reduces probability if no goals after 30 minutes
 */
final class ModifierCalculator
{
    /**
     * Apply time factor to base probability.
     * 
     * The time factor increases probability as more time remains.
     * Formula: prob × (0.4 + 0.6 × (time_remaining / 45))
     * 
     * @param float $baseProb Base probability from sigmoid
     * @param int $minute Current minute
     * @return array{probability: float, time_remaining: int, time_factor: float}
     */
    public function applyTimeFactor(float $baseProb, int $minute): array
    {
        $timeRemaining = 45 - $minute;
        $timeFactor = $timeRemaining / 45.0;
        
        // Apply weighted time factor
        $probability = $baseProb * (
            Config::TIME_FACTOR_MIN_WEIGHT + 
            (Config::TIME_FACTOR_MAX_WEIGHT * $timeFactor)
        );
        
        return [
            'probability' => $probability,
            'time_remaining' => $timeRemaining,
            'time_factor' => $timeFactor,
        ];
    }

    /**
     * Apply score modifier based on current score difference.
     * 
     * - Draw (0-0, 1-1, etc.): Both teams motivated → 1.05
     * - 1 goal difference: Losing team pushes → 1.10
     * - 2+ goal difference: Winner defends → 0.90
     * 
     * @param float $prob Current probability
     * @param int $scoreHome Home team score
     * @param int $scoreAway Away team score
     * @return array{probability: float, score_diff: int, modifier: float}
     */
    public function applyScoreModifier(float $prob, int $scoreHome, int $scoreAway): array
    {
        $scoreDiff = abs($scoreHome - $scoreAway);
        
        $modifier = match (true) {
            $scoreDiff === 0 => Config::SCORE_MODIFIER_DRAW,
            $scoreDiff === 1 => Config::SCORE_MODIFIER_ONE_GOAL,
            default => Config::SCORE_MODIFIER_TWO_PLUS,
        };
        
        return [
            'probability' => $prob * $modifier,
            'score_diff' => $scoreDiff,
            'modifier' => $modifier,
        ];
    }

    /**
     * Apply dry period modifier if no goals after 30 minutes.
     * 
     * If the match is still 0-0 after 30 minutes, reduce probability
     * as teams may be playing more defensively.
     * 
     * @param float $prob Current probability
     * @param int $scoreHome Home team score
     * @param int $scoreAway Away team score
     * @param int $minute Current minute
     * @return array{probability: float, applied: bool}
     */
    public function applyDryPeriodModifier(float $prob, int $scoreHome, int $scoreAway, int $minute): array
    {
        $isDryPeriod = ($scoreHome === 0) 
                    && ($scoreAway === 0) 
                    && ($minute > Config::DRY_PERIOD_MINUTE_THRESHOLD);
        
        if ($isDryPeriod) {
            return [
                'probability' => $prob * Config::DRY_PERIOD_MODIFIER,
                'applied' => true,
            ];
        }
        
        return [
            'probability' => $prob,
            'applied' => false,
        ];
    }

    /**
     * Clamp probability to valid range [3%, 97%].
     * 
     * Prevents extreme probabilities that are unrealistic.
     * 
     * @param float $prob Probability to clamp
     * @return float Clamped probability
     */
    public function clampProbability(float $prob): float
    {
        return max(
            Config::PROBABILITY_MIN,
            min(Config::PROBABILITY_MAX, $prob)
        );
    }
}
