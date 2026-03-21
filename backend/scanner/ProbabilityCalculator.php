<?php

declare(strict_types=1);

namespace Proxbet\Scanner;

/**
 * Calculates probability scores for first half goal prediction.
 */
final class ProbabilityCalculator
{
    private int $algorithmVersion = 1;

    /**
     * Set algorithm version (1 = legacy, 2 = v2).
     */
    public function setAlgorithmVersion(int $version): void
    {
        if ($version !== 1 && $version !== 2) {
            throw new \InvalidArgumentException('Algorithm version must be 1 or 2');
        }
        $this->algorithmVersion = $version;
    }

    /**
     * Get current algorithm version.
     */
    public function getAlgorithmVersion(): int
    {
        return $this->algorithmVersion;
    }

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
     * Routes to legacy or v2 implementation based on algorithm version.
     *
     * @param array{home_goals:int,away_goals:int,has_data:bool} $formData
     * @param array{home_goals:int,away_goals:int,has_data:bool} $h2hData
     * @param array<string,mixed> $liveData
     * @return array<string,mixed>
     */
    public function calculateAll(array $formData, array $h2hData, array $liveData): array
    {
        if ($this->algorithmVersion === 2) {
            return $this->calculateV2($formData, $h2hData, $liveData);
        }

        return $this->calculateLegacy($formData, $h2hData, $liveData);
    }

    /**
     * Calculate using legacy Algorithm 1 formula.
     *
     * @param array{home_goals:int,away_goals:int,has_data:bool} $formData
     * @param array{home_goals:int,away_goals:int,has_data:bool} $h2hData
     * @param array<string,mixed> $liveData
     * @return array{form_score:float,h2h_score:float,live_score:float,probability:float,algorithm_version:int}
     */
    public function calculateLegacy(array $formData, array $h2hData, array $liveData): array
    {
        $formScore = $this->calculateFormScore($formData);
        $h2hScore = $this->calculateH2hScore($h2hData);
        $liveScore = $this->calculateLiveScore($liveData);
        $probability = $this->calculateProbability($formScore, $h2hScore, $liveScore);

        return [
            'algorithm_version' => 1,
            'form_score' => $formScore,
            'h2h_score' => $h2hScore,
            'live_score' => $liveScore,
            'probability' => $probability,
        ];
    }

    /**
     * Calculate using Algorithm 1 v2 with full component decomposition.
     *
     * @param array{home_goals:int,away_goals:int,has_data:bool,weighted?:array<string,mixed>|null} $formData
     * @param array{home_goals:int,away_goals:int,has_data:bool} $h2hData
     * @param array<string,mixed> $liveData
     * @return array{
     *   algorithm_version:int,
     *   form_score:float,
     *   h2h_score:float,
     *   live_score:float,
     *   probability:float,
     *   components:array<string,mixed>,
     *   decision:array{bet:bool,reason:string}
     * }
     */
    public function calculateV2(array $formData, array $h2hData, array $liveData): array
    {
        // Calculate weighted form score
        $formScore = $this->calculateWeightedFormScore($formData);
        
        // Calculate H2H score (same as legacy for now)
        $h2hScore = $this->calculateH2hScore($h2hData);
        
        // Extract minute for time-based calculations
        $minute = (int) ($liveData['minute'] ?? 0);
        
        // Calculate all v2 components
        $pdi = $this->calculatePdi($liveData);
        $shotQuality = $this->calculateShotQuality($liveData);
        $trendAcceleration = $this->calculateTrendAcceleration($liveData);
        $timePressure = $this->getTimePressure($minute);
        $leagueFactor = $this->getLeagueFactor($liveData);
        $cardFactor = $this->calculateCardFactor($liveData);
        $xgPressure = $this->calculateXgPressure($liveData);
        
        // Check red flags
        $redFlag = $this->checkRedFlags($liveData, $minute);
        
        // Apply xg_mismatch amplifier if detected
        if ($redFlag === 'xg_mismatch') {
            $timePressure *= 1.2;
        }
        
        // Check volatility: if last 5+ minutes without shots, reduce components
        $windowSeconds = $liveData['trend_window_seconds'] ?? 0;
        $shotsDelta = $liveData['trend_shots_total_delta'] ?? null;
        if ($windowSeconds >= 300 && $shotsDelta === 0) {
            $trendAcceleration *= 0.5;
            $shotQuality *= 0.7;
            $pdi *= 0.8;
        }
        
        $xgPressureAdjusted = $xgPressure;
        
        // Assemble live score from components
        $liveScore = (
            $pdi * 0.20 +
            $shotQuality * 0.25 +
            $trendAcceleration * 0.25 +
            $xgPressureAdjusted * 0.20 +
            $cardFactor * 0.10
        );
        
        // Clamp live_score_base to prevent negative values from card_factor
        $liveScore = max(0.0, $liveScore);
        
        // Apply time pressure adjustment
        $liveScoreAdjusted = $liveScore * (0.7 + 0.3 * $timePressure);
        
        // Calculate base probability
        $baseProbability = (
            $formScore * 0.25 +
            $h2hScore * 0.10 +
            $liveScoreAdjusted * 0.65
        );
        
        // Apply league factor and clamp to 0..1
        $finalProbability = $this->clamp01($baseProbability * $leagueFactor);
        
        // Build components structure
        $components = [
            'form' => $this->extractFormComponents($formData, $formScore),
            'pdi' => $pdi,
            'shot_quality' => $shotQuality,
            'trend_acceleration' => $trendAcceleration,
            'time_pressure' => $timePressure,
            'league_factor' => $leagueFactor,
            'card_factor' => $cardFactor,
            'xg_pressure' => $xgPressure,
            'red_flag' => $redFlag,
        ];
        
        // Make betting decision based on gating conditions
        $decision = $this->makeV2Decision($formData, $h2hData, $liveData, $finalProbability, $redFlag);
        
        return [
            'algorithm_version' => 2,
            'form_score' => $formScore,
            'h2h_score' => $h2hScore,
            'live_score' => $liveScoreAdjusted,
            'probability' => $finalProbability,
            'components' => $components,
            'decision' => $decision,
        ];
    }

    /**
     * Calculate weighted form score with attack/defense separation.
     *
     * @param array{home_goals:int,away_goals:int,has_data:bool,weighted?:array<string,mixed>|null} $formData
     */
    private function calculateWeightedFormScore(array $formData): float
    {
        if (!$formData['has_data']) {
            return 0.0;
        }
        
        // Use weighted metrics if available
        if (isset($formData['weighted']['score'])) {
            return $this->clamp01($formData['weighted']['score']);
        }
        
        // Fallback to legacy calculation
        return $this->calculateFormScore($formData);
    }

    /**
     * Extract form components for debug output.
     *
     * @param array{home_goals:int,away_goals:int,has_data:bool,weighted?:array<string,mixed>|null} $formData
     * @return array{weighted_score:float,home:array{attack:float,defense:float},away:array{attack:float,defense:float}}
     */
    private function extractFormComponents(array $formData, float $formScore): array
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

    /**
     * Calculate Pressure Dominance Index (PDI).
     * High score for balanced, intense open games.
     *
     * Threshold: Returns 0.0 if total dangerous_attacks < 20.
     * This threshold (20) is lower than ineffective_pressure threshold (30) by design:
     * - PDI threshold (20): Minimum activity level for any calculation
     * - ineffective_pressure threshold (30): High activity without conversion indicates inefficiency
     *
     * @param array<string,mixed> $liveData
     */
    private function calculatePdi(array $liveData): float
    {
        $dangerHome = (int) ($liveData['dangerous_attacks_home'] ?? 0);
        $dangerAway = (int) ($liveData['dangerous_attacks_away'] ?? 0);
        $total = $dangerHome + $dangerAway;
        
        // Minimum activity threshold for PDI calculation
        if ($total < 20) {
            return 0.0;
        }
        
        $balance = 1.0 - abs($dangerHome - $dangerAway) / $total;
        $intensity = min($total / 40.0, 1.0);
        
        return $balance * $intensity;
    }

    /**
     * Calculate shot quality based on xG efficiency and accuracy.
     *
     * @param array<string,mixed> $liveData
     */
    private function calculateShotQuality(array $liveData): float
    {
        $totalShots = (int) ($liveData['shots_total'] ?? 0);
        $shotsOnTarget = (int) ($liveData['shots_on_target'] ?? 0);
        
        if ($totalShots === 0) {
            return 0.0;
        }
        
        $accuracy = $shotsOnTarget / $totalShots;
        
        // Try to use xG if available
        $xgHome = $liveData['xg_home'] ?? null;
        $xgAway = $liveData['xg_away'] ?? null;
        
        if ($xgHome !== null && $xgAway !== null) {
            $totalXg = (float) $xgHome + (float) $xgAway;
            $xgPerShot = $totalXg / $totalShots;
            $quality = min($xgPerShot / 0.33, 1.0);
            
            return $quality * 0.7 + $accuracy * 0.3;
        }
        
        // Fallback to accuracy-only mode
        return $accuracy;
    }

    /**
     * Calculate time pressure factor (non-linear growth from 15 to 30 minutes).
     */
    private function getTimePressure(int $minute): float
    {
        if ($minute < 15 || $minute > 30) {
            return 0.0;
        }
        
        $progress = ($minute - 15) / 15.0;
        return pow($progress, 1.5);
    }

    /**
     * Calculate league factor based on table_avg.
     *
     * @param array<string,mixed> $liveData
     */
    private function getLeagueFactor(array $liveData): float
    {
        $tableAvg = $liveData['table_avg'] ?? null;
        
        if ($tableAvg === null) {
            return 1.0;
        }
        
        $factor = (float) $tableAvg / 2.5;
        return max(0.7, min(1.3, $factor));
    }

    /**
     * Calculate trend acceleration using velocity approach.
     *
     * @param array<string,mixed> $liveData
     */
    private function calculateTrendAcceleration(array $liveData): float
    {
        if (!($liveData['has_trend_data'] ?? false)) {
            return 0.0;
        }
        
        $windowSeconds = $liveData['trend_window_seconds'] ?? null;
        if ($windowSeconds === null || $windowSeconds <= 0) {
            return 0.0;
        }
        
        $windowMinutes = max(1.0, $windowSeconds / 60.0);
        
        $shotsDelta = (int) ($liveData['trend_shots_total_delta'] ?? 0);
        $dangerDelta = (int) ($liveData['trend_dangerous_attacks_delta'] ?? 0);
        $xgDelta = (float) ($liveData['trend_xg_delta'] ?? 0.0);
        
        $shotsVelocity = $shotsDelta / $windowMinutes;
        $dangerVelocity = $dangerDelta / $windowMinutes;
        $xgVelocity = $xgDelta / $windowMinutes;
        
        $score = (
            min($shotsVelocity / 5.0, 1.0) * 0.3 +
            min($dangerVelocity / 10.0, 1.0) * 0.5 +
            min($xgVelocity / 0.2, 1.0) * 0.2
        );
        
        return $this->clamp01($score);
    }

    /**
     * Calculate card factor (can be negative).
     *
     * @param array<string,mixed> $liveData
     */
    private function calculateCardFactor(array $liveData): float
    {
        $cardsHome = (int) ($liveData['yellow_cards_home'] ?? 0);
        $cardsAway = (int) ($liveData['yellow_cards_away'] ?? 0);
        
        $diff = $cardsAway - $cardsHome;
        
        if (abs($diff) <= 1) {
            return $diff * 0.03;
        }
        
        if (abs($diff) === 2) {
            return ($diff > 0 ? 1 : -1) * 0.08;
        }
        
        return ($diff > 0 ? 1 : -1) * 0.15;
    }

    /**
     * Calculate xG pressure (impending goal indicator).
     *
     * @param array<string,mixed> $liveData
     */
    private function calculateXgPressure(array $liveData): float
    {
        $xgHome = $liveData['xg_home'] ?? null;
        $xgAway = $liveData['xg_away'] ?? null;
        
        if ($xgHome === null || $xgAway === null) {
            return 0.0;
        }
        
        $totalXg = (float) $xgHome + (float) $xgAway;
        return min($totalXg / 1.5, 1.0);
    }

    /**
     * Check for red flags that affect betting decision.
     *
     * Red flag thresholds:
     * - low_accuracy: shot accuracy < 25%
     * - ineffective_pressure: dangerous_attacks > 30 but shots_on_target < 2
     *   (Higher threshold than PDI's 20 to identify truly inefficient pressure)
     * - xg_mismatch: xG > 1.2 but score still 0:0 at minute ≥ 25 (amplifier, not blocker)
     *
     * @param array<string,mixed> $liveData
     * @return string|null Red flag identifier or null
     */
    private function checkRedFlags(array $liveData, int $minute): ?string
    {
        $totalShots = (int) ($liveData['shots_total'] ?? 0);
        $shotsOnTarget = (int) ($liveData['shots_on_target'] ?? 0);
        
        // Check low_accuracy (blocking flag)
        if ($totalShots > 0) {
            $accuracy = $shotsOnTarget / $totalShots;
            if ($accuracy < 0.25) {
                return 'low_accuracy';
            }
        }
        
        // Check ineffective_pressure (blocking flag)
        // Threshold: 30 dangerous attacks (higher than PDI's 20) indicates high activity
        // If this high activity doesn't convert to shots on target, it's ineffective
        $dangerHome = (int) ($liveData['dangerous_attacks_home'] ?? 0);
        $dangerAway = (int) ($liveData['dangerous_attacks_away'] ?? 0);
        $shotsOnTargetHome = (int) ($liveData['shots_on_target_home'] ?? 0);
        $shotsOnTargetAway = (int) ($liveData['shots_on_target_away'] ?? 0);
        
        if ($dangerHome > 30 && $shotsOnTargetHome < 2) {
            return 'ineffective_pressure';
        }
        if ($dangerAway > 30 && $shotsOnTargetAway < 2) {
            return 'ineffective_pressure';
        }
        
        // Check xg_mismatch
        $xgHome = $liveData['xg_home'] ?? null;
        $xgAway = $liveData['xg_away'] ?? null;
        $htHscore = (int) ($liveData['ht_hscore'] ?? 0);
        $htAscore = (int) ($liveData['ht_ascore'] ?? 0);
        
        if ($xgHome !== null && $xgAway !== null && $minute >= 25) {
            $totalXg = (float) $xgHome + (float) $xgAway;
            if ($totalXg > 1.2 && $htHscore === 0 && $htAscore === 0) {
                return 'xg_mismatch';
            }
        }
        
        return null;
    }

    /**
     * Make betting decision based on v2 gating conditions.
     *
     * @param array{home_goals:int,away_goals:int,has_data:bool,weighted?:array<string,mixed>|null} $formData
     * @param array{home_goals:int,away_goals:int,has_data:bool} $h2hData
     * @param array<string,mixed> $liveData
     * @return array{bet:bool,reason:string}
     */
    private function makeV2Decision(
        array $formData,
        array $h2hData,
        array $liveData,
        float $probability,
        ?string $redFlag
    ): array {
        // Check form data availability
        if (!$formData['has_data']) {
            return ['bet' => false, 'reason' => 'no_form_data'];
        }
        
        // Check H2H data availability
        if (!$h2hData['has_data']) {
            return ['bet' => false, 'reason' => 'no_h2h_data'];
        }
        
        // Check HT score is 0:0
        $htHscore = (int) ($liveData['ht_hscore'] ?? 0);
        $htAscore = (int) ($liveData['ht_ascore'] ?? 0);
        if ($htHscore !== 0 || $htAscore !== 0) {
            return ['bet' => false, 'reason' => 'ht_score_not_0_0'];
        }
        
        // Check minute range 15..30
        $minute = (int) ($liveData['minute'] ?? 0);
        if ($minute < 15 || $minute > 30) {
            return ['bet' => false, 'reason' => 'minute_out_of_range'];
        }
        
        // Check shots on target >= 1
        $shotsOnTarget = (int) ($liveData['shots_on_target'] ?? 0);
        if ($shotsOnTarget < 1) {
            return ['bet' => false, 'reason' => 'insufficient_shots_on_target'];
        }
        
        // Check dangerous attacks tempo: dangerous_attacks / minute > 1.5
        $dangerousAttacks = (int) ($liveData['dangerous_attacks'] ?? 0);
        if ($minute > 0) {
            $attackTempo = $dangerousAttacks / $minute;
            if ($attackTempo <= 1.5) {
                return ['bet' => false, 'reason' => 'insufficient_attack_tempo'];
            }
        } else {
            // Fallback if minute is 0
            if ($dangerousAttacks < 20) {
                return ['bet' => false, 'reason' => 'insufficient_dangerous_attacks'];
            }
        }
        
        // Check blocking red flags
        if ($redFlag === 'low_accuracy') {
            return ['bet' => false, 'reason' => 'red_flag_low_accuracy'];
        }
        if ($redFlag === 'ineffective_pressure') {
            return ['bet' => false, 'reason' => 'red_flag_ineffective_pressure'];
        }
        
        // Check probability threshold
        if ($probability < 0.55) {
            return ['bet' => false, 'reason' => 'probability_below_threshold'];
        }
        
        // All gates passed
        return ['bet' => true, 'reason' => 'all_conditions_met'];
    }

    /**
     * Clamp value to 0..1 range.
     */
    private function clamp01(float $value): float
    {
        return max(0.0, min(1.0, $value));
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
