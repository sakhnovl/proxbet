<?php

declare(strict_types=1);

namespace Proxbet\Scanner\Algorithms\AlgorithmX;

use Proxbet\Core\Interfaces\AlgorithmInterface;

/**
 * AlgorithmX: Goal Probability Algorithm.
 * 
 * Predicts the probability of a goal in the remaining time of the first half
 * based on live match statistics.
 * 
 * Algorithm flow:
 * 1. Extract live data from match record
 * 2. Validate input data
 * 3. Calculate Attack Intensity Score (AIS)
 * 4. Calculate base probability using sigmoid function
 * 5. Apply time, score, and dry period modifiers
 * 6. Make betting decision based on thresholds
 * 
 * @see docs/goal_probability_agent_prompt.md for detailed specification
 */
final class AlgorithmX implements AlgorithmInterface
{
    public function __construct(
        private Config $config,
        private DataExtractor $extractor,
        private DataValidator $validator,
        private Calculators\ProbabilityCalculator $calculator,
        private Filters\DecisionFilter $filter
    ) {
    }

    /**
     * Get algorithm ID.
     * 
     * @return int
     */
    public function getId(): int
    {
        return $this->config::ALGORITHM_ID;
    }

    /**
     * Get algorithm name.
     * 
     * @return string
     */
    public function getName(): string
    {
        return $this->config::ALGORITHM_NAME;
    }

    /**
     * Analyze match data and determine if a bet should be placed.
     * 
     * @param array<string,mixed> $matchData Must contain live_data
     * @return array{
     *   bet:bool,
     *   reason:string,
     *   confidence:float,
     *   debug?:array<string,mixed>
     * }
     */
    public function analyze(array $matchData): array
    {
        // Check if algorithm is enabled
        if (!$this->config::isEnabled()) {
            return [
                'bet' => false,
                'reason' => 'AlgorithmX is disabled',
                'confidence' => 0.0,
            ];
        }

        $liveData = $this->resolveLiveData($matchData);
        
        // Validate data
        $validation = $this->validator->validate($liveData);
        if (!$validation['valid']) {
            return [
                'bet' => false,
                'reason' => $validation['reason'],
                'confidence' => 0.0,
                'debug' => [
                    'validation_failed' => true,
                    'validation_reason' => $validation['reason'],
                ],
            ];
        }
        
        // Calculate probability
        $result = $this->calculator->calculate($liveData);
        
        // Make decision
        $decision = $this->filter->shouldBet(
            $result['probability'],
            $liveData,
            $result['debug']
        );
        
        // Return result
        return [
            'bet' => $decision['bet'],
            'reason' => $decision['reason'],
            'confidence' => $result['probability'],
            'debug' => array_merge($result['debug'], [
                'decision_reason' => $decision['reason'],
                'algorithm_version' => 'AlgorithmX v1.0',
            ]),
        ];
    }

    /**
     * Accept either raw match payload or pre-extracted `live_data`.
     *
     * @param array<string,mixed> $matchData
     * @return array<string,mixed>
     */
    private function resolveLiveData(array $matchData): array
    {
        $liveData = $matchData['live_data'] ?? null;
        if (is_array($liveData)) {
            return $liveData;
        }

        return $this->extractor->extract($matchData);
    }
}
