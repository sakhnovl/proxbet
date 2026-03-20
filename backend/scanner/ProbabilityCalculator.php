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
     * @param array{
     *   minute:int,
     *   shots_total:int,
     *   shots_on_target:int,
     *   dangerous_attacks:int,
     *   corners:int,
     *   shots_on_target_home:int,
     *   shots_on_target_away:int,
     *   shots_off_target_home:int,
     *   shots_off_target_away:int,
     *   dangerous_attacks_home:int,
     *   dangerous_attacks_away:int,
     *   corners_home:int,
     *   corners_away:int,
     *   xg_home:?float,
     *   xg_away:?float,
     *   yellow_cards_home:?int,
     *   yellow_cards_away:?int,
     *   trend_shots_total_delta:?int,
     *   trend_shots_on_target_delta:?int,
     *   trend_dangerous_attacks_delta:?int,
     *   trend_xg_delta:?float,
     *   trend_window_seconds:?int,
     *   has_trend_data:bool,
     *   ht_hscore:int,
     *   ht_ascore:int,
     *   live_hscore:int,
     *   live_ascore:int,
     *   time_str:string,
     *   match_status:string
     * } $liveData
     * @return float Score from 0.0 to 1.0
     */
    public function calculateLiveScore(array $liveData): float
    {
        $weights = [];
        $scores = [];

        $weights[] = 0.22;
        $scores[] = $this->cap($liveData['shots_total'] / 8.0);

        $weights[] = 0.28;
        $scores[] = $this->cap($liveData['shots_on_target'] / 4.0);

        $weights[] = 0.25;
        $scores[] = $this->cap($liveData['dangerous_attacks'] / 28.0);

        $weights[] = 0.10;
        $scores[] = $this->calculateDominanceScore($liveData);

        $trendScore = $this->calculateTrendScore($liveData);
        if ($trendScore !== null) {
            $weights[] = 0.12;
            $scores[] = $trendScore;
        }

        $xgScore = $this->calculateXgScore($liveData);
        if ($xgScore !== null) {
            $weights[] = 0.10;
            $scores[] = $xgScore;
        }

        $disciplineScore = $this->calculateDisciplineScore($liveData);
        if ($disciplineScore !== null) {
            $weights[] = 0.05;
            $scores[] = $disciplineScore;
        }

        $weightedSum = 0.0;
        $weightTotal = 0.0;
        foreach ($scores as $index => $score) {
            $weightedSum += $score * $weights[$index];
            $weightTotal += $weights[$index];
        }

        if ($weightTotal <= 0.0) {
            return 0.0;
        }

        return round($weightedSum / $weightTotal, 4);
    }

    /**
     * Calculate final probability using weighted formula.
     *
     * @return float Final probability from 0.0 to 1.0
     */
    public function calculateProbability(float $formScore, float $h2hScore, float $liveScore): float
    {
        return $formScore * 0.35 + $h2hScore * 0.15 + $liveScore * 0.50;
    }

    /**
     * Calculate all scores and final probability.
     *
     * @param array{home_goals:int,away_goals:int,has_data:bool} $formData
     * @param array{home_goals:int,away_goals:int,has_data:bool} $h2hData
     * @param array<string,mixed> $liveData
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

    /**
     * @param array<string,mixed> $liveData
     */
    private function calculateDominanceScore(array $liveData): float
    {
        $homePressure = $this->buildSidePressure(
            (int) $liveData['shots_on_target_home'],
            (int) $liveData['shots_off_target_home'],
            (int) $liveData['dangerous_attacks_home'],
            (int) $liveData['corners_home'],
            $liveData['xg_home']
        );
        $awayPressure = $this->buildSidePressure(
            (int) $liveData['shots_on_target_away'],
            (int) $liveData['shots_off_target_away'],
            (int) $liveData['dangerous_attacks_away'],
            (int) $liveData['corners_away'],
            $liveData['xg_away']
        );

        $totalPressure = $homePressure + $awayPressure;
        if ($totalPressure <= 0.0) {
            return 0.0;
        }

        $dominance = max($homePressure, $awayPressure) / $totalPressure;

        return $this->cap(($dominance - 0.5) * 2.0);
    }

    /**
     * @param mixed $xg
     */
    private function buildSidePressure(
        int $shotsOnTarget,
        int $shotsOffTarget,
        int $dangerousAttacks,
        int $corners,
        mixed $xg
    ): float {
        $pressure = ($shotsOnTarget * 1.4)
            + ($shotsOffTarget * 0.45)
            + ($dangerousAttacks * 0.08)
            + ($corners * 0.25);

        if (is_numeric($xg)) {
            $pressure += (float) $xg * 2.2;
        }

        return $pressure;
    }

    /**
     * @param array<string,mixed> $liveData
     */
    private function calculateXgScore(array $liveData): ?float
    {
        if (!is_numeric($liveData['xg_home']) || !is_numeric($liveData['xg_away'])) {
            return null;
        }

        return $this->cap((((float) $liveData['xg_home']) + ((float) $liveData['xg_away'])) / 1.2);
    }

    /**
     * @param array<string,mixed> $liveData
     */
    private function calculateDisciplineScore(array $liveData): ?float
    {
        if ($liveData['yellow_cards_home'] === null || $liveData['yellow_cards_away'] === null) {
            return null;
        }

        $cards = (int) $liveData['yellow_cards_home'] + (int) $liveData['yellow_cards_away'];

        return $this->cap($cards / 4.0);
    }

    /**
     * @param array<string,mixed> $liveData
     */
    private function calculateTrendScore(array $liveData): ?float
    {
        if (($liveData['has_trend_data'] ?? false) !== true) {
            return null;
        }

        $windowSeconds = is_numeric($liveData['trend_window_seconds'] ?? null)
            ? max(1, (int) $liveData['trend_window_seconds'])
            : 1;

        $windowFactor = min(1.0, $windowSeconds / 300.0);
        $shotDelta = is_numeric($liveData['trend_shots_total_delta'] ?? null)
            ? $this->cap(((int) $liveData['trend_shots_total_delta']) / 4.0)
            : 0.0;
        $shotsOnTargetDelta = is_numeric($liveData['trend_shots_on_target_delta'] ?? null)
            ? $this->cap(((int) $liveData['trend_shots_on_target_delta']) / 2.0)
            : 0.0;
        $dangerDelta = is_numeric($liveData['trend_dangerous_attacks_delta'] ?? null)
            ? $this->cap(((int) $liveData['trend_dangerous_attacks_delta']) / 10.0)
            : 0.0;
        $xgDelta = is_numeric($liveData['trend_xg_delta'] ?? null)
            ? $this->cap(((float) $liveData['trend_xg_delta']) / 0.35)
            : 0.0;

        return $this->cap((($shotDelta * 0.20) + ($shotsOnTargetDelta * 0.35) + ($dangerDelta * 0.30) + ($xgDelta * 0.15)) * $windowFactor);
    }

    private function cap(float $value): float
    {
        if ($value < 0.0) {
            return 0.0;
        }

        if ($value > 1.0) {
            return 1.0;
        }

        return $value;
    }
}
