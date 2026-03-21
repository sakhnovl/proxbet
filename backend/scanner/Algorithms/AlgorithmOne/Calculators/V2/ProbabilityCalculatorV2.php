<?php
declare(strict_types=1);

namespace Proxbet\Scanner\Algorithms\AlgorithmOne\Calculators\V2;

use Proxbet\Scanner\Algorithms\AlgorithmOne\Config;

/**
 * Main V2 probability calculator with all components
 */
final class ProbabilityCalculatorV2
{
    public function __construct(
        private PdiCalculator $pdiCalculator,
        private ShotQualityCalculator $shotQualityCalculator,
        private TrendCalculator $trendCalculator,
        private TimePressureCalculator $timePressureCalculator,
        private LeagueFactorCalculator $leagueFactorCalculator,
        private CardFactorCalculator $cardFactorCalculator,
        private XgPressureCalculator $xgPressureCalculator,
        private RedFlagChecker $redFlagChecker
    ) {}

    /**
     * @return array{
     *   probability:float,
     *   decision:array{bet:bool,reason:string},
     *   components:array<string,mixed>,
     *   debug:array{
     *     gating_passed:bool,
     *     gating_reason:string,
     *     red_flag:?string,
     *     red_flags:list<string>,
     *     decision_reason:string,
     *     probability:float,
     *     penalties:array<string,float>,
     *     gating_context:array<string,mixed>
     *   }
     * }
     */
    public function calculate(array $formData, array $h2hData, array $liveData, int $minute): array
    {
        $leagueProfile = $this->resolveLeagueProfile($liveData);

        $gatingCheck = $this->checkGatingConditions($formData, $h2hData, $liveData, $minute, $leagueProfile);
        if (!$gatingCheck['passed']) {
            return [
                'probability' => 0.0,
                'decision' => ['bet' => false, 'reason' => $gatingCheck['reason']],
                'components' => [],
                'debug' => [
                    'gating_passed' => false,
                    'gating_reason' => $gatingCheck['reason'],
                    'red_flag' => null,
                    'red_flags' => [],
                    'decision_reason' => $gatingCheck['reason'],
                    'probability' => 0.0,
                    'penalties' => [],
                    'gating_context' => $gatingCheck['context'],
                ],
            ];
        }

        $components = $this->calculateComponents($liveData, $minute, $leagueProfile);
        $components['gating_context'] = $gatingCheck['context'];

        $redFlags = $this->redFlagChecker->collect($liveData, $minute);
        $primaryRedFlag = $redFlags[0] ?? null;
        $components['red_flag'] = $primaryRedFlag;
        $components['red_flags'] = $redFlags;

        $probabilityDetails = $this->calculateProbabilityDetails(
            $formData,
            $h2hData,
            $components,
            $redFlags,
            $leagueProfile
        );
        $probability = $probabilityDetails['probability'];
        $components['probability_breakdown'] = $probabilityDetails['breakdown'];
        $components['component_contributions'] = $probabilityDetails['contributions'];

        $penalties = $this->buildPenaltyMap($gatingCheck['context'], $redFlags, $leagueProfile);
        $penaltyFactor = $this->calculatePenaltyFactor($penalties);
        $components['penalties'] = $penalties;
        $components['penalty_factor'] = $penaltyFactor;
        $components['h2h_score_effective'] = $this->calculateH2hScore($h2hData);
        $components['probability_breakdown']['pre_penalty_probability'] = $probability;

        $probability *= $penaltyFactor;
        $components['probability_breakdown']['final_probability'] = $probability;
        $components['threshold_evaluation'] = $this->buildThresholdEvaluation(
            $probability,
            $leagueProfile['probability_threshold']
        );
        $components['component_contributions']['form_final'] =
            $components['component_contributions']['form_pre_penalty'] * $penaltyFactor;
        $components['component_contributions']['h2h_final'] =
            $components['component_contributions']['h2h_pre_penalty'] * $penaltyFactor;
        $components['component_contributions']['live_final'] =
            $components['component_contributions']['live_pre_penalty'] * $penaltyFactor;
        $components['component_contributions']['live_components_final'] = array_map(
            static fn (float $value): float => $value * $penaltyFactor,
            $components['component_contributions']['live_components_pre_penalty']
        );

        $decision = $this->makeDecision($probability, $leagueProfile['probability_threshold']);

        return [
            'probability' => $probability,
            'decision' => $decision,
            'components' => $components,
            'debug' => [
                'gating_passed' => true,
                'gating_reason' => '',
                'red_flag' => $primaryRedFlag,
                'red_flags' => $redFlags,
                'decision_reason' => $decision['reason'],
                'probability' => $probability,
                'penalties' => $penalties,
                'gating_context' => $gatingCheck['context'],
            ],
        ];
    }

    private function checkGatingConditions(
        array $formData,
        array $h2hData,
        array $liveData,
        int $minute,
        array $leagueProfile
    ): array {
        $context = [
            'minute' => $minute,
            'has_h2h_data' => (bool) ($h2hData['has_data'] ?? false),
            'shots_gate_relief' => false,
            'shots_gate_relief_signals' => [],
            'attack_tempo' => null,
            'attack_tempo_support_score' => null,
            'attack_tempo_penalty' => 1.0,
            'league_category' => $leagueProfile['category'],
            'league_profile' => $leagueProfile,
        ];

        if (!($formData['has_data'] ?? false)) {
            return ['passed' => false, 'reason' => 'no_form_data', 'context' => $context];
        }

        $htHscore = (int) ($liveData['ht_hscore'] ?? 0);
        $htAscore = (int) ($liveData['ht_ascore'] ?? 0);
        if ($htHscore !== 0 || $htAscore !== 0) {
            return ['passed' => false, 'reason' => 'ht_score_not_0_0', 'context' => $context];
        }

        if ($minute < Config::MIN_MINUTE || $minute > Config::MAX_MINUTE) {
            return ['passed' => false, 'reason' => 'minute_out_of_range', 'context' => $context];
        }

        $tempoContext = $this->buildAttackTempoContext($liveData, $minute, $leagueProfile);
        $context['attack_tempo'] = $tempoContext['tempo'];
        $context['attack_tempo_support_score'] = $tempoContext['support_score'];
        $context['attack_tempo_penalty'] = $tempoContext['penalty'];

        $shotsOnTarget = (int) ($liveData['shots_on_target'] ?? 0);
        if ($shotsOnTarget < Config::MIN_SHOTS_ON_TARGET) {
            $shotsGateRelief = $this->buildEarlyShotsReliefContext($liveData, $minute, $leagueProfile);
            $context['shots_gate_relief'] = $shotsGateRelief['bypass'];
            $context['shots_gate_relief_signals'] = $shotsGateRelief['signals'];

            if (!$shotsGateRelief['bypass']) {
                return ['passed' => false, 'reason' => 'insufficient_shots_on_target', 'context' => $context];
            }
        }

        return ['passed' => true, 'reason' => '', 'context' => $context];
    }

    private function calculateComponents(array $liveData, int $minute, array $leagueProfile): array
    {
        $xgAvailable = is_numeric($liveData['xg_home'] ?? null) && is_numeric($liveData['xg_away'] ?? null);

        return [
            'pdi' => $this->pdiCalculator->calculate($liveData),
            'shot_quality' => $this->shotQualityCalculator->calculate($liveData),
            'trend_acceleration' => $this->trendCalculator->calculate($liveData),
            'time_pressure' => $this->timePressureCalculator->calculate($minute),
            'league_factor' => $this->leagueFactorCalculator->calculate($liveData),
            'card_factor' => $this->cardFactorCalculator->calculate($liveData),
            'xg_pressure' => $this->xgPressureCalculator->calculate($liveData),
            'xg_available' => $xgAvailable,
            'shot_quality_source' => $xgAvailable ? 'xg' : 'fallback_without_xg',
            'xg_pressure_source' => $xgAvailable ? 'xg' : 'fallback_without_xg',
            'league_segment' => $leagueProfile['category'],
            'league_profile' => $leagueProfile,
            'league_context' => $liveData['league_context'] ?? [
                'category' => $leagueProfile['category'],
                'profile' => $leagueProfile,
            ],
        ];
    }

    /**
     * @return array{
     *   probability:float,
     *   breakdown:array<string,mixed>,
     *   contributions:array<string,mixed>
     * }
     */
    private function calculateProbabilityDetails(
        array $formData,
        array $h2hData,
        array $components,
        array $redFlags,
        array $leagueProfile
    ): array {
        $formScore = $this->calculateWeightedFormScore($formData);
        $h2hScore = $this->calculateH2hScore($h2hData);
        $liveWeights = $this->buildLiveComponentWeights(
            $leagueProfile,
            (bool) ($components['xg_available'] ?? false)
        );
        $liveScoreBase = (
            $components['pdi'] * $liveWeights['pdi'] +
            $components['shot_quality'] * $liveWeights['shot_quality'] +
            $components['trend_acceleration'] * $liveWeights['trend_acceleration'] +
            $components['xg_pressure'] * $liveWeights['xg_pressure'] +
            $components['card_factor'] * $liveWeights['card_factor']
        );

        $liveScoreBase = max(0.0, $liveScoreBase);
        $timePressure = $components['time_pressure'];

        if (in_array('xg_mismatch', $redFlags, true)) {
            $timePressure *= (1.0 + Config::V2_XG_MISMATCH_AMPLIFIER);
        }

        $timePressureMultiplier = Config::V2_TIME_PRESSURE_BASE + Config::V2_TIME_PRESSURE_MULTIPLIER * $timePressure;
        $liveScoreAdjusted = $liveScoreBase * $timePressureMultiplier;
        $baseProbability = (
            $formScore * Config::V2_FORM_WEIGHT +
            $h2hScore * Config::V2_H2H_WEIGHT +
            $liveScoreAdjusted * Config::V2_LIVE_WEIGHT
        );
        $finalProbability = $baseProbability * $components['league_factor'];

        $liveComponentsPrePenalty = [
            'pdi' => $components['pdi'] * $liveWeights['pdi'] * $timePressureMultiplier * Config::V2_LIVE_WEIGHT,
            'shot_quality' => $components['shot_quality'] * $liveWeights['shot_quality'] * $timePressureMultiplier * Config::V2_LIVE_WEIGHT,
            'trend_acceleration' => $components['trend_acceleration'] * $liveWeights['trend_acceleration'] * $timePressureMultiplier * Config::V2_LIVE_WEIGHT,
            'xg_pressure' => $components['xg_pressure'] * $liveWeights['xg_pressure'] * $timePressureMultiplier * Config::V2_LIVE_WEIGHT,
            'card_factor' => $components['card_factor'] * $liveWeights['card_factor'] * $timePressureMultiplier * Config::V2_LIVE_WEIGHT,
        ];

        return [
            'probability' => max(0.0, min(1.0, $finalProbability)),
            'breakdown' => [
                'form_score' => $formScore,
                'h2h_score' => $h2hScore,
                'live_score_base' => $liveScoreBase,
                'live_score_adjusted' => $liveScoreAdjusted,
                'time_pressure_multiplier' => $timePressureMultiplier,
                'league_factor' => $components['league_factor'],
                'base_probability' => $baseProbability,
                'xg_available' => (bool) ($components['xg_available'] ?? false),
                'shot_quality_source' => (string) ($components['shot_quality_source'] ?? 'unknown'),
                'xg_pressure_source' => (string) ($components['xg_pressure_source'] ?? 'unknown'),
                'league_segment' => $leagueProfile['category'],
                'probability_threshold' => $leagueProfile['probability_threshold'],
                'live_component_weights' => $liveWeights,
            ],
            'contributions' => [
                'form_pre_penalty' => $formScore * Config::V2_FORM_WEIGHT * $components['league_factor'],
                'h2h_pre_penalty' => $h2hScore * Config::V2_H2H_WEIGHT * $components['league_factor'],
                'live_pre_penalty' => $liveScoreAdjusted * Config::V2_LIVE_WEIGHT * $components['league_factor'],
                'live_components_pre_penalty' => array_map(
                    static fn (float $value): float => $value * $components['league_factor'],
                    $liveComponentsPrePenalty
                ),
            ],
        ];
    }

    private function calculateH2hScore(array $h2hData): float
    {
        if (!($h2hData['has_data'] ?? false)) {
            return 0.5;
        }

        return ($h2hData['home_goals'] + $h2hData['away_goals']) / 10.0;
    }

    private function calculateWeightedFormScore(array $formData): float
    {
        if (!($formData['has_data'] ?? false)) {
            return 0.0;
        }

        if (isset($formData['weighted']['score'])) {
            return max(0.0, min(1.0, $formData['weighted']['score']));
        }

        $homeScore = $formData['home_goals'] / 5.0;
        $awayScore = $formData['away_goals'] / 5.0;

        return ($homeScore + $awayScore) / 2.0;
    }

    private function makeDecision(float $probability, float $threshold): array
    {
        if ($probability >= $threshold) {
            return ['bet' => true, 'reason' => 'probability_threshold_met'];
        }

        return ['bet' => false, 'reason' => 'probability_too_low'];
    }

    /**
     * @return array{active:float,candidates:array<string,bool>}
     */
    private function buildThresholdEvaluation(float $probability, float $activeThreshold): array
    {
        $candidates = [];

        foreach (Config::getV2ThresholdCandidates() as $threshold) {
            $candidates[number_format($threshold, 2, '.', '')] = $probability >= $threshold;
        }

        $formattedActive = number_format($activeThreshold, 2, '.', '');
        if (!array_key_exists($formattedActive, $candidates)) {
            $candidates[$formattedActive] = $probability >= $activeThreshold;
            krsort($candidates, SORT_NATURAL);
        }

        return [
            'active' => $activeThreshold,
            'candidates' => $candidates,
        ];
    }

    /**
     * @param array<string,mixed> $liveData
     * @return array{tempo:float,support_score:float,penalty:float}
     */
    private function buildAttackTempoContext(array $liveData, int $minute, array $leagueProfile): array
    {
        $dangerousAttacks = (int) ($liveData['dangerous_attacks'] ?? 0);
        $shotsOnTarget = (int) ($liveData['shots_on_target'] ?? 0);
        $shotsTotal = (int) ($liveData['shots_total'] ?? 0);
        $corners = (int) ($liveData['corners'] ?? 0);
        $trendScore = $this->trendCalculator->calculate($liveData);
        $minAttackTempo = (float) ($leagueProfile['min_attack_tempo'] ?? Config::V2_MIN_ATTACK_TEMPO);

        $tempo = $minute > 0 ? $dangerousAttacks / $minute : 0.0;
        $tempoNormalized = $minAttackTempo > 0.0 ? min($tempo / $minAttackTempo, 1.0) : 1.0;
        $supportScore = min(
            1.0,
            $tempoNormalized * 0.45
            + min($shotsOnTarget / 3.0, 1.0) * 0.20
            + min($shotsTotal / 10.0, 1.0) * 0.15
            + min($corners / 5.0, 1.0) * 0.10
            + $trendScore * 0.10
        );

        if ($tempo >= $minAttackTempo) {
            return [
                'tempo' => $tempo,
                'support_score' => $supportScore,
                'penalty' => 1.0,
            ];
        }

        return [
            'tempo' => $tempo,
            'support_score' => $supportScore,
            'penalty' => min(
                1.0,
                Config::V2_ATTACK_TEMPO_SOFT_PENALTY_MIN
                + (1.0 - Config::V2_ATTACK_TEMPO_SOFT_PENALTY_MIN) * $supportScore
            ),
        ];
    }

    /**
     * @param array<string,mixed> $liveData
     * @return array{bypass:bool,signals:array<string,bool>}
     */
    private function buildEarlyShotsReliefContext(array $liveData, int $minute, array $leagueProfile): array
    {
        $tempoContext = $this->buildAttackTempoContext($liveData, $minute, $leagueProfile);
        $xgHome = $liveData['xg_home'] ?? null;
        $xgAway = $liveData['xg_away'] ?? null;
        $totalXg = ($xgHome !== null && $xgAway !== null) ? (float) $xgHome + (float) $xgAway : 0.0;

        $signals = [
            'very_high_pressure' => $tempoContext['tempo'] >= Config::V2_EARLY_SHOTS_PRESSURE_THRESHOLD,
            'strong_trend' => $this->trendCalculator->calculate($liveData) >= Config::V2_EARLY_SHOTS_TREND_THRESHOLD,
            'good_xg' => $totalXg >= Config::V2_EARLY_SHOTS_XG_THRESHOLD,
            'good_corners' => (int) ($liveData['corners'] ?? 0) >= Config::V2_EARLY_SHOTS_CORNERS_THRESHOLD,
        ];

        if ($minute > Config::V2_EARLY_SHOTS_WINDOW_END) {
            return ['bypass' => false, 'signals' => $signals];
        }

        $supportCount = count(array_filter($signals));
        $hasPressureLedCase = $signals['very_high_pressure']
            && ($signals['strong_trend'] || $signals['good_xg'] || $signals['good_corners']);

        return [
            'bypass' => $hasPressureLedCase || $supportCount >= 2,
            'signals' => $signals,
        ];
    }

    /**
     * @param array<string,mixed> $gatingContext
     * @param list<string> $redFlags
     * @return array<string,float>
     */
    private function buildPenaltyMap(array $gatingContext, array $redFlags, array $leagueProfile): array
    {
        $penalties = [];

        if (($gatingContext['has_h2h_data'] ?? true) === false) {
            $missingH2hPenalty = (float) ($leagueProfile['missing_h2h_penalty'] ?? Config::V2_NO_H2H_PENALTY);
            if ($missingH2hPenalty < 1.0) {
                $penalties['missing_h2h'] = $missingH2hPenalty;
            }
        }

        $attackTempoPenalty = (float) ($gatingContext['attack_tempo_penalty'] ?? 1.0);
        if ($attackTempoPenalty < 1.0) {
            $penalties['soft_attack_tempo'] = $attackTempoPenalty;
        }

        if (($gatingContext['shots_gate_relief'] ?? false) === true) {
            $penalties['early_shots_relief'] = Config::V2_EARLY_SHOTS_RELIEF_PENALTY;
        }

        if (in_array('low_accuracy', $redFlags, true)) {
            $penalties['low_accuracy'] = Config::V2_LOW_ACCURACY_PENALTY;
        }

        if (in_array('ineffective_pressure', $redFlags, true)) {
            $penalties['ineffective_pressure'] = Config::V2_INEFFECTIVE_PRESSURE_PENALTY;
        }

        return $penalties;
    }

    /**
     * @param array<string,float> $penalties
     */
    private function calculatePenaltyFactor(array $penalties): float
    {
        if ($penalties === []) {
            return 1.0;
        }

        $factor = 1.0;
        foreach ($penalties as $penalty) {
            $factor *= $penalty;
        }

        return max(0.0, min(1.0, $factor));
    }

    /**
     * @param array<string,mixed> $liveData
     * @return array{
     *   category:string,
     *   min_attack_tempo:float,
     *   missing_h2h_penalty:float,
     *   xg_weight_multiplier:float,
     *   probability_threshold:float
     * }
     */
    private function resolveLeagueProfile(array $liveData): array
    {
        $profile = $liveData['league_profile'] ?? null;
        if (is_array($profile)) {
            return Config::getLeagueSegmentProfile((string) ($profile['category'] ?? null));
        }

        return Config::getLeagueSegmentProfile((string) ($liveData['league_category'] ?? null));
    }

    /**
     * @return array{
     *   pdi:float,
     *   shot_quality:float,
     *   trend_acceleration:float,
     *   xg_pressure:float,
     *   card_factor:float
     * }
     */
    private function buildLiveComponentWeights(array $leagueProfile, bool $xgAvailable): array
    {
        $weights = [
            'pdi' => Config::V2_PDI_WEIGHT,
            'shot_quality' => Config::V2_SHOT_QUALITY_WEIGHT,
            'trend_acceleration' => Config::V2_TREND_WEIGHT,
            'xg_pressure' => Config::V2_XG_PRESSURE_WEIGHT * (float) ($leagueProfile['xg_weight_multiplier'] ?? 1.0),
            'card_factor' => Config::V2_CARD_FACTOR_WEIGHT,
        ];

        if (!$xgAvailable) {
            $weights['xg_pressure'] = 0.0;
        }

        $sum = array_sum($weights);
        if ($sum <= 0.0) {
            return [
                'pdi' => Config::V2_PDI_WEIGHT,
                'shot_quality' => Config::V2_SHOT_QUALITY_WEIGHT,
                'trend_acceleration' => Config::V2_TREND_WEIGHT,
                'xg_pressure' => $xgAvailable ? Config::V2_XG_PRESSURE_WEIGHT : 0.0,
                'card_factor' => Config::V2_CARD_FACTOR_WEIGHT,
            ];
        }

        return [
            'pdi' => $weights['pdi'] / $sum,
            'shot_quality' => $weights['shot_quality'] / $sum,
            'trend_acceleration' => $weights['trend_acceleration'] / $sum,
            'xg_pressure' => $weights['xg_pressure'] / $sum,
            'card_factor' => $weights['card_factor'] / $sum,
        ];
    }
}
