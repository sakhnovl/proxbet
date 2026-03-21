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
     * Calculate V2 probability with all components
     * 
     * @return array{probability:float,decision:array,components:array}
     */
    public function calculate(array $formData, array $h2hData, array $liveData, int $minute): array
    {
        // Check gating conditions first
        $gatingCheck = $this->checkGatingConditions($formData, $h2hData, $liveData, $minute);
        if (!$gatingCheck['passed']) {
            return [
                'probability' => 0.0,
                'decision' => ['bet' => false, 'reason' => $gatingCheck['reason']],
                'components' => [],
            ];
        }

        // Calculate all components
        $components = $this->calculateComponents($liveData, $minute);
        
        // Check red flags
        $redFlag = $this->redFlagChecker->check($liveData, $minute);
        if ($redFlag !== null && $redFlag !== 'xg_mismatch') {
            return [
                'probability' => 0.0,
                'decision' => ['bet' => false, 'reason' => "red_flag_{$redFlag}"],
                'components' => $components,
            ];
        }

        // Calculate probability
        $probability = $this->calculateProbability($formData, $h2hData, $components, $redFlag);
        
        // Make decision
        $decision = $this->makeDecision($probability);
        
        return [
            'probability' => $probability,
            'decision' => $decision,
            'components' => $components,
        ];
    }

    private function checkGatingConditions(array $formData, array $h2hData, array $liveData, int $minute): array
    {
        // Check form data
        if (!($formData['has_data'] ?? false)) {
            return ['passed' => false, 'reason' => 'no_form_data'];
        }
        
        // Check H2H data
        if (!($h2hData['has_data'] ?? false)) {
            return ['passed' => false, 'reason' => 'no_h2h_data'];
        }
        
        // Check score is 0:0
        $htHscore = (int) ($liveData['ht_hscore'] ?? 0);
        $htAscore = (int) ($liveData['ht_ascore'] ?? 0);
        if ($htHscore !== 0 || $htAscore !== 0) {
            return ['passed' => false, 'reason' => 'ht_score_not_0_0'];
        }
        
        // Check minute range
        if ($minute < Config::MIN_MINUTE || $minute > Config::MAX_MINUTE) {
            return ['passed' => false, 'reason' => 'minute_out_of_range'];
        }
        
        // Check shots on target
        $shotsOnTarget = (int) ($liveData['shots_on_target'] ?? 0);
        if ($shotsOnTarget < Config::MIN_SHOTS_ON_TARGET) {
            return ['passed' => false, 'reason' => 'insufficient_shots_on_target'];
        }
        
        // Check attack tempo
        $dangerousAttacks = (int) ($liveData['dangerous_attacks'] ?? 0);
        if ($minute > 0) {
            $attackTempo = $dangerousAttacks / $minute;
            if ($attackTempo <= Config::V2_MIN_ATTACK_TEMPO) {
                return ['passed' => false, 'reason' => 'insufficient_attack_tempo'];
            }
        } else {
            if ($dangerousAttacks < Config::MIN_DANGEROUS_ATTACKS) {
                return ['passed' => false, 'reason' => 'insufficient_dangerous_attacks'];
            }
        }
        
        return ['passed' => true, 'reason' => ''];
    }

    private function calculateComponents(array $liveData, int $minute): array
    {
        return [
            'pdi' => $this->pdiCalculator->calculate($liveData),
            'shot_quality' => $this->shotQualityCalculator->calculate($liveData),
            'trend_acceleration' => $this->trendCalculator->calculate($liveData),
            'time_pressure' => $this->timePressureCalculator->calculate($minute),
            'league_factor' => $this->leagueFactorCalculator->calculate($liveData),
            'card_factor' => $this->cardFactorCalculator->calculate($liveData),
            'xg_pressure' => $this->xgPressureCalculator->calculate($liveData),
        ];
    }

    private function calculateProbability(array $formData, array $h2hData, array $components, ?string $redFlag): float
    {
        // Calculate weighted form score
        $formScore = $this->calculateWeightedFormScore($formData);
        
        // Calculate H2H score (simple average)
        $h2hScore = 0.0;
        if ($h2hData['has_data'] ?? false) {
            $h2hScore = ($h2hData['home_goals'] + $h2hData['away_goals']) / 10.0;
        }
        
        // Calculate live score from components
        $liveScoreBase = (
            $components['pdi'] * 0.20 +
            $components['shot_quality'] * 0.25 +
            $components['trend_acceleration'] * 0.25 +
            $components['xg_pressure'] * 0.20 +
            $components['card_factor'] * 0.10
        );
        
        // Clamp to prevent negative values from card_factor
        $liveScoreBase = max(0.0, $liveScoreBase);
        
        // Apply time pressure adjustment
        $timePressure = $components['time_pressure'];
        
        // Apply xg_mismatch amplifier if detected
        if ($redFlag === 'xg_mismatch') {
            $timePressure *= 1.2;
        }
        
        $liveScoreAdjusted = $liveScoreBase * (0.7 + 0.3 * $timePressure);
        
        // Calculate base probability
        $baseProbability = (
            $formScore * 0.25 +
            $h2hScore * 0.10 +
            $liveScoreAdjusted * 0.65
        );
        
        // Apply league factor
        $finalProbability = $baseProbability * $components['league_factor'];
        
        // Clamp to 0..1
        return max(0.0, min(1.0, $finalProbability));
    }
    
    private function calculateWeightedFormScore(array $formData): float
    {
        if (!($formData['has_data'] ?? false)) {
            return 0.0;
        }
        
        // Use weighted metrics if available
        if (isset($formData['weighted']['score'])) {
            return max(0.0, min(1.0, $formData['weighted']['score']));
        }
        
        // Fallback to simple calculation
        $homeScore = $formData['home_goals'] / 5.0;
        $awayScore = $formData['away_goals'] / 5.0;
        return ($homeScore + $awayScore) / 2.0;
    }

    private function makeDecision(float $probability): array
    {
        if ($probability >= Config::MIN_PROBABILITY) {
            return ['bet' => true, 'reason' => 'probability_threshold_met'];
        }
        
        return ['bet' => false, 'reason' => 'probability_too_low'];
    }
}
