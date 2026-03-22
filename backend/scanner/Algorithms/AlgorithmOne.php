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
     * @return array{
     *   bet:bool,
     *   reason:string,
     *   confidence:float,
     *   debug?:array<string,mixed>,
     *   dual_run?:array<string,mixed>
     * }
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
     * @return array{bet:bool,reason:string,confidence:float,debug:array<string,mixed>}
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
            'debug' => [
                'algorithm_version' => Config::VERSION_LEGACY,
                'gating_passed' => $decision['bet'],
                'gating_reason' => $decision['bet'] ? '' : $decision['reason'],
                'decision_reason' => $decision['reason'],
                'probability' => $result['probability'],
                'components' => [
                    'form_score' => $result['form_score'],
                    'h2h_score' => $result['h2h_score'],
                    'live_score' => $result['live_score'],
                ],
                'red_flag' => null,
                'red_flags' => [],
                'penalties' => [],
                'gating_context' => [],
            ],
        ];
    }

    /**
     * Run V2 algorithm.
     * 
     * @return array{bet:bool,reason:string,confidence:float,debug:array<string,mixed>}
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
            'reason' => $result['decision']['reason'],
            'confidence' => $result['probability'],
            'debug' => [
                'algorithm_version' => Config::VERSION_V2,
                'gating_passed' => $result['debug']['gating_passed'],
                'gating_reason' => $result['debug']['gating_reason'],
                'decision_reason' => $result['debug']['decision_reason'],
                'probability' => $result['debug']['probability'],
                'components' => $result['components'],
                'red_flag' => $result['debug']['red_flag'] ?? null,
                'red_flags' => $result['debug']['red_flags'],
                'penalties' => $result['debug']['penalties'],
                'gating_context' => $result['debug']['gating_context'],
            ],
        ];
    }

    /**
     * Run both legacy and V2 algorithms in parallel for comparison.
     * 
     * @return array{bet:bool,reason:string,confidence:float,debug:array<string,mixed>,dual_run:array<string,mixed>}
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
        if ($version === Config::VERSION_V2) {
            /** @var array<string,mixed> $v2Primary */
            $v2Primary = $dualResult['v2'];
            $primary = $v2Primary;
            /** @var array<string,mixed> $primaryDebug */
            $primaryDebug = is_array($v2Primary['debug'] ?? null) ? $v2Primary['debug'] : [];
        } else {
            /** @var array<string,mixed> $primary */
            $primary = $dualResult['legacy'];
            /** @var array<string,mixed> $primaryDebug */
            $primaryDebug = [
                'gating_passed' => $dualResult['legacy']['decision']['bet'],
                'gating_reason' => '',
                'decision_reason' => $dualResult['legacy']['decision']['reason'],
                'probability' => $dualResult['legacy']['probability'],
                'red_flag' => null,
                'red_flags' => [],
                'penalties' => [],
                'gating_context' => [],
            ];
        }
        
        return [
            'bet' => $primary['decision']['bet'],
            'reason' => $primary['decision']['reason'],
            'confidence' => $primary['probability'],
            'debug' => [
                'algorithm_version' => $version,
                'gating_passed' => $primaryDebug['gating_passed'],
                'gating_reason' => $primaryDebug['gating_reason'],
                'decision_reason' => $primaryDebug['decision_reason'],
                'probability' => $primaryDebug['probability'],
                'components' => $primary['components'],
                'red_flag' => $primaryDebug['red_flag'],
                'red_flags' => $primaryDebug['red_flags'],
                'penalties' => $primaryDebug['penalties'],
                'gating_context' => $primaryDebug['gating_context'],
            ],
            // Include dual-run comparison data for analysis
            'dual_run' => [
                'primary_version' => $version,
                'legacy_probability' => $dualResult['legacy']['probability'],
                'legacy_decision' => $dualResult['legacy']['decision']['bet'] ? 'bet' : 'no_bet',
                'legacy_reason' => $dualResult['legacy']['decision']['reason'],
                'legacy_bet' => $dualResult['legacy']['decision']['bet'],
                'v2_probability' => $dualResult['v2']['probability'],
                'v2_decision' => $dualResult['v2']['decision']['bet'] ? 'bet' : 'no_bet',
                'v2_reason' => $dualResult['v2']['decision']['reason'],
                'v2_bet' => $dualResult['v2']['decision']['bet'],
                'probability_diff' => $dualResult['comparison']['probability_diff'],
                'decision_match' => $dualResult['comparison']['decision_match'],
                'divergence_level' => $dualResult['comparison']['divergence_level'],
            ],
        ];
    }
}
