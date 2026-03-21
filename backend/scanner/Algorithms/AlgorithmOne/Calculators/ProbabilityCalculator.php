<?php

declare(strict_types=1);

namespace Proxbet\Scanner\Algorithms\AlgorithmOne\Calculators;

/**
 * Calculate final probability using weighted formula (Legacy version).
 */
final class ProbabilityCalculator
{
    private FormScoreCalculator $formCalculator;
    private H2hScoreCalculator $h2hCalculator;
    private LiveScoreCalculator $liveCalculator;

    public function __construct(
        ?FormScoreCalculator $formCalculator = null,
        ?H2hScoreCalculator $h2hCalculator = null,
        ?LiveScoreCalculator $liveCalculator = null
    ) {
        $this->formCalculator = $formCalculator ?? new FormScoreCalculator();
        $this->h2hCalculator = $h2hCalculator ?? new H2hScoreCalculator();
        $this->liveCalculator = $liveCalculator ?? new LiveScoreCalculator();
    }

    /**
     * Calculate all scores and final probability using legacy formula.
     *
     * @param array{home_goals:int,away_goals:int,has_data:bool} $formData
     * @param array{home_goals:int,away_goals:int,has_data:bool} $h2hData
     * @param array<string,mixed> $liveData
     * @return array{form_score:float,h2h_score:float,live_score:float,probability:float}
     */
    public function calculate(array $formData, array $h2hData, array $liveData): array
    {
        $formScore = $this->formCalculator->calculate($formData);
        $h2hScore = $this->h2hCalculator->calculate($h2hData);
        $liveScore = $this->liveCalculator->calculate($liveData);
        $probability = $this->calculateProbability($formScore, $h2hScore, $liveScore);

        return [
            'form_score' => $formScore,
            'h2h_score' => $h2hScore,
            'live_score' => $liveScore,
            'probability' => $probability,
        ];
    }

    /**
     * Calculate final probability using weighted formula.
     * Formula: form * 0.35 + h2h * 0.15 + live * 0.50
     *
     * @return float Final probability from 0.0 to 1.0
     */
    private function calculateProbability(float $formScore, float $h2hScore, float $liveScore): float
    {
        return $formScore * 0.35 + $h2hScore * 0.15 + $liveScore * 0.50;
    }
}
