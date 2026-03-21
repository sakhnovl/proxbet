<?php

declare(strict_types=1);

namespace Proxbet\Scanner\Algorithms;

use Proxbet\Core\Interfaces\AlgorithmInterface;
use Proxbet\Scanner\Algorithms\AlgorithmOne\Config;
use Proxbet\Scanner\Algorithms\AlgorithmOne\Calculators\ProbabilityCalculator;
use Proxbet\Scanner\Algorithms\AlgorithmOne\Calculators\V2\ProbabilityCalculatorV2;
use Proxbet\Scanner\Algorithms\AlgorithmOne\Filters\LegacyFilter;
use Proxbet\Scanner\Algorithms\AlgorithmOne\Services\DualRunService;

/**
 * Algorithm 1: First half goal prediction based on form, H2H, and live data.
 * 
 * Supports:
 * - Legacy mode (v1): Original algorithm with form*0.35 + h2h*0.15 + live*0.50
 * - V2 mode: Enhanced algorithm with PDI, shot quality, trends, and more
 * - Dual-run mode: Run both versions in parallel for comparison
 * 
 * Version is controlled by ALGORITHM_VERSION environment variable.
 * Dual-run is enabled by ALGORITHM1_DUAL_RUN=1 environment variable.
 */
final class AlgorithmOne implements AlgorithmInterface
{
    public function __construct(
        private ProbabilityCalculator $legacyCalculator,
        private ProbabilityCalculatorV2 $v2Calculator,
        private LegacyFilter $legacyFilter,
        private ?DualRunService $dualRunService = null
    ) {
    }

    public function getId(): int
    {
        return Config::ALGORITHM_ID;
    }

    public function getName(): string
    {
        return Config::ALGORITHM_NAME;
    }

    /**
     * Analyze match data and determine if a bet should be placed.
     * 
     * @param array<string,mixed> $matchData Must contain form_data, h2h_data, live_data
     * @return array{bet:bool,reason:string,confidence:float,dual_run?:array<string,mixed>}
     */
    public function analyze(array $matchData): array
    {
        $formData = $matchData['form_data'] ?? [];
        $h2hData = $matchData['h2h_data'] ?? [];
        $liveData = $matchData['live_data'] ?? [];
        $minute = (int) ($liveData['minute'] ?? 0);

        // Check if dual-run mode is enabled
        if (Config::isDualRunEnabled() && $this->dualRunService !== null) {
            return $this->runDualMode($formData, $h2hData, $liveData, $minute);
        }

        // Single mode: check version
        $version = Config::getAlgorithmVersion();
        
        if ($version === Config::VERSION_V2) {
            return $this->runV2Mode($formData, $h2hData, $liveData, $minute);
        }
        
        return $this->runLegacyMode($formData, $h2hData, $liveData, $minute);
    }

    /**
     * Run legacy (v1) algorithm.
     * 
     * @return array{bet:bool,reason:string,confidence:float}
     */
    private function runLegacyMode(
        array $formData,
        array $h2hData,
        array $liveData,
        int $minute
    ): array {
        // Calculate probability using legacy calculator
        $result = $this->legacyCalculator->calculate($formData, $h2hData, $liveData);
        
        // Apply legacy filter to make decision
        $decision = $this->legacyFilter->shouldBet(
            $liveData,
            $result['probability'],
            $formData,
            $h2hData
        );

        return [
            'bet' => $decision['bet'],
            'reason' => $decision['reason'],
            'confidence' => $result['probability'],
        ];
    }

    /**
     * Run V2 algorithm.
     * 
     * @return array{bet:bool,reason:string,confidence:float}
     */
    private function runV2Mode(
        array $formData,
        array $h2hData,
        array $liveData,
        int $minute
    ): array {
        // V2 calculator includes decision making (gating conditions + red flags)
        $result = $this->v2Calculator->calculate($formData, $h2hData, $liveData, $minute);

        return [
            'bet' => $result['decision']['bet'],
            'reason' => $result['decision']['reason'] ?? 'unknown',
            'confidence' => $result['probability'],
        ];
    }

    /**
     * Run both legacy and V2 algorithms in parallel for comparison.
     * 
     * @return array{bet:bool,reason:string,confidence:float,dual_run:array<string,mixed>}
     */
    private function runDualMode(
        array $formData,
        array $h2hData,
        array $liveData,
        int $minute
    ): array {
        // Run both versions and get comparison
        $dualResult = $this->dualRunService->runBoth($formData, $h2hData, $liveData, $minute);
        
        // Use the configured version as primary
        $version = Config::getAlgorithmVersion();
        $primary = $version === Config::VERSION_V2 ? $dualResult['v2'] : $dualResult['legacy'];
        
        return [
            'bet' => $primary['decision']['bet'],
            'reason' => $primary['decision']['reason'] ?? 'unknown',
            'confidence' => $primary['probability'],
            // Include dual-run comparison data for analysis
            'dual_run' => [
                'primary_version' => $version,
                'legacy_probability' => $dualResult['legacy']['probability'],
                'legacy_bet' => $dualResult['legacy']['decision']['bet'],
                'v2_probability' => $dualResult['v2']['probability'],
                'v2_bet' => $dualResult['v2']['decision']['bet'],
                'probability_diff' => $dualResult['comparison']['probability_diff'],
                'decision_match' => $dualResult['comparison']['decision_match'],
                'divergence_level' => $dualResult['comparison']['divergence_level'],
            ],
        ];
    }
}
