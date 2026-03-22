<?php

declare(strict_types=1);

namespace Proxbet\Scanner\Algorithms\AlgorithmX\Calculators;

use Proxbet\Scanner\Algorithms\AlgorithmX\Config;

/**
 * Probability Calculator.
 * 
 * Main calculator that orchestrates the probability calculation process:
 * 1. Calculate AIS (Attack Intensity Score)
 * 2. Apply sigmoid function for base probability
 * 3. Apply time factor
 * 4. Apply score modifier
 * 5. Apply dry period modifier
 * 6. Clamp to valid range
 * 7. Generate interpretation
 */
final class ProbabilityCalculator
{
    public function __construct(
        private AisCalculator $aisCalculator,
        private ModifierCalculator $modifierCalculator,
        private InterpretationGenerator $interpretationGenerator
    ) {
    }

    /**
     * Calculate probability of a goal in remaining first half time.
     * 
     * @param array<string,mixed> $liveData Live match data
     * @return array{
     *   probability: float,
     *   debug: array<string,mixed>
     * }
     */
    public function calculate(array $liveData): array
    {
        $minute = (int) ($liveData['minute'] ?? 0);
        $scoreHome = (int) ($liveData['score_home'] ?? 0);
        $scoreAway = (int) ($liveData['score_away'] ?? 0);
        
        // Step 1: Calculate AIS
        $aisResult = $this->aisCalculator->calculate($liveData);
        
        // Step 2: Calculate base probability using sigmoid
        $baseProb = $this->calculateSigmoid($aisResult['ais_rate']);
        
        // Step 3: Apply time factor
        $timeResult = $this->modifierCalculator->applyTimeFactor($baseProb, $minute);
        $probAdjusted = $timeResult['probability'];
        
        // Step 4: Apply score modifier
        $scoreResult = $this->modifierCalculator->applyScoreModifier($probAdjusted, $scoreHome, $scoreAway);
        $probWithScore = $scoreResult['probability'];
        
        // Step 5: Apply dry period modifier
        $dryPeriodResult = $this->modifierCalculator->applyDryPeriodModifier(
            $probWithScore,
            $scoreHome,
            $scoreAway,
            $minute
        );
        $probFinal = $dryPeriodResult['probability'];
        
        // Step 6: Clamp to valid range
        $probClamped = $this->modifierCalculator->clampProbability($probFinal);
        
        // Step 7: Generate interpretation
        $interpretation = $this->interpretationGenerator->generate($probClamped);
        
        // Compile debug information
        $debug = [
            'ais_home' => $aisResult['ais_home'],
            'ais_away' => $aisResult['ais_away'],
            'ais_total' => $aisResult['ais_total'],
            'ais_rate' => $aisResult['ais_rate'],
            'base_prob' => $baseProb,
            'time_remaining' => $timeResult['time_remaining'],
            'time_factor' => $timeResult['time_factor'],
            'prob_adjusted' => $probAdjusted,
            'score_diff' => $scoreResult['score_diff'],
            'score_modifier' => $scoreResult['modifier'],
            'prob_with_score' => $probWithScore,
            'dry_period_applied' => $dryPeriodResult['applied'],
            'prob_before_clamp' => $probFinal,
            'prob_final' => $probClamped,
            'interpretation' => $interpretation,
        ];
        
        return [
            'probability' => $probClamped,
            'debug' => $debug,
        ];
    }

    /**
     * Calculate base probability using sigmoid function.
     * 
     * Formula: P = 1 / (1 + e^(-k × (AIS_rate - threshold)))
     * 
     * Where:
     * - k = steepness parameter (default: 2.5)
     * - threshold = calibration value (default: 1.8)
     * - AIS_rate = Attack Intensity Score per minute
     * 
     * @param float $aisRate AIS per minute
     * @return float Base probability (0.0-1.0)
     */
    private function calculateSigmoid(float $aisRate): float
    {
        $k = Config::getSigmoidK();
        $threshold = Config::getSigmoidThreshold();
        
        // Calculate exponent: -k × (AIS_rate - threshold)
        $exponent = -$k * ($aisRate - $threshold);
        
        // Prevent overflow for very large exponents
        if ($exponent > 20) {
            return 0.0;
        }
        if ($exponent < -20) {
            return 1.0;
        }
        
        // Calculate sigmoid: 1 / (1 + e^exponent)
        return 1.0 / (1.0 + exp($exponent));
    }
}
