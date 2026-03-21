<?php

declare(strict_types=1);

namespace Proxbet\Scanner\Algorithms\AlgorithmOne\Services;

use Proxbet\Scanner\Algorithms\AlgorithmOne\Calculators\ProbabilityCalculator;
use Proxbet\Scanner\Algorithms\AlgorithmOne\Calculators\V2\ProbabilityCalculatorV2;
use Proxbet\Scanner\Algorithms\AlgorithmOne\Filters\LegacyFilter;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Service for running both legacy and v2 algorithms in parallel.
 * 
 * Enables comparison of results between versions for validation
 * and gradual migration.
 */
final class DualRunService
{
    private ProbabilityCalculator $legacyCalculator;
    private ProbabilityCalculatorV2 $v2Calculator;
    private LegacyFilter $legacyFilter;
    private LoggerInterface $logger;

    public function __construct(
        ProbabilityCalculator $legacyCalculator,
        ProbabilityCalculatorV2 $v2Calculator,
        LegacyFilter $legacyFilter,
        ?LoggerInterface $logger = null
    ) {
        $this->legacyCalculator = $legacyCalculator;
        $this->v2Calculator = $v2Calculator;
        $this->legacyFilter = $legacyFilter;
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * Run both legacy and v2 algorithms and compare results.
     *
     * @param array<string,mixed> $formData
     * @param array<string,mixed> $h2hData
     * @param array<string,mixed> $liveData
     * @param int $minute Current match minute
     * @return array{
     *     legacy: array{probability: float, decision: array{bet: bool, reason: string|null}, components: array<string,float>},
     *     v2: array{probability: float, decision: array{bet: bool, reason: string|null}, components: array<string,mixed>},
     *     comparison: array{
     *         probability_diff: float,
     *         decision_match: bool,
     *         legacy_bet: bool,
     *         v2_bet: bool,
     *         divergence_level: string
     *     }
     * }
     */
    public function runBoth(
        array $formData,
        array $h2hData,
        array $liveData,
        int $minute
    ): array {
        // Run legacy version
        $legacyResult = $this->runLegacy($formData, $h2hData, $liveData, $minute);

        // Run v2 version
        $v2Result = $this->runV2($formData, $h2hData, $liveData, $minute);

        // Compare results
        $comparison = $this->compareResults($legacyResult, $v2Result);

        // Log if there's significant divergence
        if ($comparison['divergence_level'] !== 'none') {
            $this->logDivergence($comparison, $legacyResult, $v2Result);
        }

        return [
            'legacy' => $legacyResult,
            'v2' => $v2Result,
            'comparison' => $comparison,
        ];
    }

    /**
     * Run legacy algorithm.
     *
     * @return array{probability: float, decision: array{bet: bool, reason: string|null}, components: array<string,float>}
     */
    private function runLegacy(
        array $formData,
        array $h2hData,
        array $liveData,
        int $minute
    ): array {
        $legacyResult = $this->legacyCalculator->calculate($formData, $h2hData, $liveData);
        
        $filterResult = $this->legacyFilter->shouldBet(
            $liveData,
            $legacyResult['probability'],
            $formData,
            $h2hData
        );

        return [
            'probability' => $legacyResult['probability'],
            'decision' => $filterResult,
            'components' => [
                'form_score' => $legacyResult['form_score'],
                'h2h_score' => $legacyResult['h2h_score'],
                'live_score' => $legacyResult['live_score'],
            ],
        ];
    }

    /**
     * Run v2 algorithm.
     *
     * @return array{probability: float, decision: array{bet: bool, reason: string|null}, components: array<string,mixed>}
     */
    private function runV2(
        array $formData,
        array $h2hData,
        array $liveData,
        int $minute
    ): array {
        return $this->v2Calculator->calculate($formData, $h2hData, $liveData, $minute);
    }

    /**
     * Compare results from both versions.
     *
     * @param array{probability: float, decision: array{bet: bool, reason: string|null}} $legacyResult
     * @param array{probability: float, decision: array{bet: bool, reason: string|null}} $v2Result
     * @return array{
     *     probability_diff: float,
     *     decision_match: bool,
     *     legacy_bet: bool,
     *     v2_bet: bool,
     *     divergence_level: string
     * }
     */
    private function compareResults(array $legacyResult, array $v2Result): array
    {
        $probabilityDiff = abs($legacyResult['probability'] - $v2Result['probability']);
        $legacyBet = $legacyResult['decision']['bet'];
        $v2Bet = $v2Result['decision']['bet'];
        $decisionMatch = $legacyBet === $v2Bet;

        // Determine divergence level
        $divergenceLevel = $this->calculateDivergenceLevel(
            $probabilityDiff,
            $decisionMatch
        );

        return [
            'probability_diff' => $probabilityDiff,
            'decision_match' => $decisionMatch,
            'legacy_bet' => $legacyBet,
            'v2_bet' => $v2Bet,
            'divergence_level' => $divergenceLevel,
        ];
    }

    /**
     * Calculate divergence level between versions.
     *
     * @param float $probabilityDiff Absolute difference in probabilities
     * @param bool $decisionMatch Whether decisions match
     * @return string 'none', 'low', 'medium', 'high'
     */
    private function calculateDivergenceLevel(float $probabilityDiff, bool $decisionMatch): string
    {
        // Decision mismatch is always high divergence
        if (!$decisionMatch) {
            return 'high';
        }

        // Both agree on decision, check probability difference
        if ($probabilityDiff < 0.05) {
            return 'none';
        }

        if ($probabilityDiff < 0.10) {
            return 'low';
        }

        if ($probabilityDiff < 0.20) {
            return 'medium';
        }

        return 'high';
    }

    /**
     * Log divergence between versions.
     */
    private function logDivergence(
        array $comparison,
        array $legacyResult,
        array $v2Result
    ): void {
        $this->logger->warning('Algorithm 1 dual-run divergence detected', [
            'divergence_level' => $comparison['divergence_level'],
            'probability_diff' => $comparison['probability_diff'],
            'decision_match' => $comparison['decision_match'],
            'legacy' => [
                'probability' => $legacyResult['probability'],
                'bet' => $legacyResult['decision']['bet'],
                'reason' => $legacyResult['decision']['reason'],
            ],
            'v2' => [
                'probability' => $v2Result['probability'],
                'bet' => $v2Result['decision']['bet'],
                'reason' => $v2Result['decision']['reason'],
            ],
        ]);
    }

    /**
     * Check if divergence is significant enough to investigate.
     *
     * @param array<string,mixed> $comparison
     * @return bool
     */
    public function hasSignificantDivergence(array $comparison): bool
    {
        return in_array(
            $comparison['divergence_level'] ?? 'none',
            ['medium', 'high'],
            true
        );
    }

    /**
     * Get statistics about divergence.
     *
     * @param array<string,mixed> $comparison
     * @return array{
     *     is_divergent: bool,
     *     level: string,
     *     probability_diff_percent: float,
     *     decisions_match: bool
     * }
     */
    public function getDivergenceStats(array $comparison): array
    {
        $probabilityDiff = $comparison['probability_diff'] ?? 0.0;
        $level = $comparison['divergence_level'] ?? 'none';

        return [
            'is_divergent' => $level !== 'none',
            'level' => $level,
            'probability_diff_percent' => $probabilityDiff * 100,
            'decisions_match' => $comparison['decision_match'] ?? true,
        ];
    }
}
