<?php

declare(strict_types=1);

namespace Proxbet\Scanner;

/**
 * Calculates probability scores for first half goal prediction.
 */
final class ProbabilityCalculator
{
    /**
     * Calculate form score based on last 5 matches.
     *
     * @param array{home_goals:int,away_goals:int,has_data:bool} $formData
     * @return float Score from 0.0 to 1.0
     */
    public function calculateFormScore(array $formData): float
    {
        if (!$formData['has_data']) {
            return 0.0;
        }

        $homeScore = $formData['home_goals'] / 5.0;
        $awayScore = $formData['away_goals'] / 5.0;

        return ($homeScore + $awayScore) / 2.0;
    }

    /**
     * Calculate H2H score based on last 5 head-to-head matches.
     *
     * @param array{home_goals:int,away_goals:int,has_data:bool} $h2hData
     * @return float Score from 0.0 to 1.0
     */
    public function calculateH2hScore(array $h2hData): float
    {
        if (!$h2hData['has_data']) {
            return 0.0;
        }

        return ($h2hData['home_goals'] + $h2hData['away_goals']) / 10.0;
    }

    /**
     * Calculate live score based on current match statistics.
     *
     * @param array{minute:int,shots_total:int,shots_on_target:int,dangerous_attacks:int,corners:int,ht_hscore:int,ht_ascore:int,time_str:string} $liveData
     * @return float Score: 0.8, 0.6, 0.4, or 0.2
     */
    public function calculateLiveScore(array $liveData): float
    {
        $shotsTotal = $liveData['shots_total'];
        $shotsOnTarget = $liveData['shots_on_target'];
        $dangerousAttacks = $liveData['dangerous_attacks'];

        // Rule 1: High activity
        if ($shotsTotal >= 6 && $shotsOnTarget >= 2 && $dangerousAttacks >= 20) {
            return 0.8;
        }

        // Rule 2: Medium-high activity
        if ($shotsTotal >= 4 && $dangerousAttacks >= 15) {
            return 0.6;
        }

        // Rule 3: Low-medium activity
        if ($shotsTotal >= 2) {
            return 0.4;
        }

        // Rule 4: Low activity
        return 0.2;
    }

    /**
     * Calculate final probability using weighted formula.
     *
     * @return float Final probability from 0.0 to 1.0
     */
    public function calculateProbability(float $formScore, float $h2hScore, float $liveScore): float
    {
        return $formScore * 0.4 + $h2hScore * 0.2 + $liveScore * 0.4;
    }

    /**
     * Calculate all scores and final probability.
     *
     * @param array{home_goals:int,away_goals:int,has_data:bool} $formData
     * @param array{home_goals:int,away_goals:int,has_data:bool} $h2hData
     * @param array{minute:int,shots_total:int,shots_on_target:int,dangerous_attacks:int,corners:int,ht_hscore:int,ht_ascore:int,time_str:string} $liveData
     * @return array{form_score:float,h2h_score:float,live_score:float,probability:float}
     */
    public function calculateAll(array $formData, array $h2hData, array $liveData): array
    {
        $formScore = $this->calculateFormScore($formData);
        $h2hScore = $this->calculateH2hScore($h2hData);
        $liveScore = $this->calculateLiveScore($liveData);
        $probability = $this->calculateProbability($formScore, $h2hScore, $liveScore);

        return [
            'form_score' => $formScore,
            'h2h_score' => $h2hScore,
            'live_score' => $liveScore,
            'probability' => $probability,
        ];
    }
}
