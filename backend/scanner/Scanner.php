<?php

declare(strict_types=1);

namespace Proxbet\Scanner;

use Proxbet\Line\Logger;
use Proxbet\Statistic\HtMetricsCalculator;
use Proxbet\Scanner\Algorithms\AlgorithmOne;
use Proxbet\Scanner\Algorithms\AlgorithmX\AlgorithmX;

/**
 * Main scanner orchestrator for match analysis.
 */
final class Scanner
{
    private const ALGORITHM_ONE_ID = 1;
    private const ALGORITHM_TWO_ID = 2;
    private const ALGORITHM_THREE_ID = 3;
    private const ALGORITHM_X_ID = 4;
    /** @var array<string,int> */
    private array $algorithmOneRejectSummary = [];
    private int $algorithmOneAcceptedSignals = 0;
    /**
     * @var array{
     *   analyzed:int,
     *   bet_true:int,
     *   low_probability:int,
     *   probability_sum:float,
     *   bet_true_probability_sum:float,
     *   bet_false_probability_sum:float
     * }
     */
    private array $algorithmXMonitoring = [
        'analyzed' => 0,
        'bet_true' => 0,
        'low_probability' => 0,
        'probability_sum' => 0.0,
        'bet_true_probability_sum' => 0.0,
        'bet_false_probability_sum' => 0.0,
    ];

    public function __construct(
        private DataExtractor $extractor,
        private ProbabilityCalculator $calculator,
        private MatchFilter $filter,
        private ResultFormatter $formatter,
        private AlgorithmOne $algorithmOne,
        private AlgorithmX $algorithmX,
        private ?HtMetricsCalculator $htCalculator = null,
    ) {
        $this->htCalculator = $htCalculator ?? new HtMetricsCalculator();
    }

    /**
     * @return array{
     *   total:int,
     *   analyzed:int,
     *   signals:int,
     *   results:array<int,array<string,mixed>>,
     *   algorithm_one_debug:array{accepted:int,rejected:array<string,int>}
     * }
     */
    public function scan(): array
    {
        $this->algorithmOneRejectSummary = [];
        $this->algorithmOneAcceptedSignals = 0;
        $this->algorithmXMonitoring = [
            'analyzed' => 0,
            'bet_true' => 0,
            'low_probability' => 0,
            'probability_sum' => 0.0,
            'bet_true_probability_sum' => 0.0,
            'bet_false_probability_sum' => 0.0,
        ];

        $batchSize = DataExtractor::resolveConfiguredBatchSize();
        $total = $this->extractor->countActiveMatches();
        $analyzed = 0;
        $signals = 0;
        $results = [];

        Logger::info('Scanner started', ['total_matches' => $total, 'batch_size' => $batchSize]);

        foreach ($this->extractor->getActiveMatchesGenerator($batchSize) as $match) {
            try {
                $matchResults = $this->scanMatch($match);
                if ($matchResults === []) {
                    continue;
                }

                $analyzed++;

                foreach ($matchResults as $result) {
                    $results[] = $result;

                    if (($result['decision']['bet'] ?? false) === true) {
                        $signals++;
                    }
                }
            } catch (\Throwable $e) {
                Logger::error('Failed to scan match', [
                    'match_id' => $match['id'] ?? null,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        Logger::info('Scanner completed', [
            'total' => $total,
            'analyzed' => $analyzed,
            'signals' => $signals,
        ]);

        Logger::info('Scanner algorithm 1 rejection summary', [
            'accepted' => $this->algorithmOneAcceptedSignals,
            'rejected' => $this->algorithmOneRejectSummary,
        ]);
        $this->logAlgorithmXMonitoringSummary();

        return [
            'total' => $total,
            'analyzed' => $analyzed,
            'signals' => $signals,
            'results' => $results,
            'algorithm_one_debug' => [
                'accepted' => $this->algorithmOneAcceptedSignals,
                'rejected' => $this->algorithmOneRejectSummary,
            ],
        ];
    }

    /**
     * @param array<string,mixed> $match
     * @return array<int,array<string,mixed>>
     */
    private function scanMatch(array $match): array
    {
        $base = $this->extractBaseMatchData($match);
        
        // Get algorithm version and dual-run settings
        $algorithmVersion = Config::getAlgorithmVersion();
        $isDualRun = Config::isDualRunEnabled();
        
        // Calculate weighted metrics for V2 if needed
        $weightedMetrics = null;
        if ($algorithmVersion === 2 || $isDualRun) {
            $sgiJson = json_decode($match['sgi_json'] ?? '{}', true);
            if (is_array($sgiJson)) {
                $htMetrics = $this->htCalculator->calculate(
                    $sgiJson,
                    $match['home'] ?? '',
                    $match['away'] ?? ''
                );
                $weightedMetrics = $htMetrics['debug']['algorithm1_v2']['form'] ?? null;
            }
        }
        
        // In dual-run mode, extract data separately for each version
        // Otherwise, use the configured version's extraction method
        if ($isDualRun) {
            // Extract data for both versions independently
            $liveDataLegacy = $this->extractor->extractLiveData($match);
            $formDataLegacy = $this->extractor->extractFormData($match);
            $liveDataV2 = $this->extractor->extractLiveDataV2($match);
            $formDataV2 = $this->extractor->extractFormDataV2($match, $weightedMetrics);
            
            // Use primary version data for main flow
            $liveData = $algorithmVersion === 2 ? $liveDataV2 : $liveDataLegacy;
            $formData = $algorithmVersion === 2 ? $formDataV2 : $formDataLegacy;
        } else {
            // Single version mode
            if ($algorithmVersion === 2) {
                $liveData = $this->extractor->extractLiveDataV2($match);
                $formData = $this->extractor->extractFormDataV2($match, $weightedMetrics);
            } else {
                $liveData = $this->extractor->extractLiveData($match);
                $formData = $this->extractor->extractFormData($match);
            }
        }

        if ($liveData['minute'] === 0) {
            return [];
        }

        if ($liveData['minute'] > 45 && trim($liveData['match_status']) !== 'Перерыв') {
            return [];
        }

        $h2hData = $this->extractor->extractH2hData($match);
        
        // Use new AlgorithmOne interface for Algorithm 1 analysis
        $algorithmOneResult = $this->algorithmOne->analyze([
            'form_data' => $formData,
            'h2h_data' => $h2hData,
            'live_data' => $liveData,
        ]);
        
        // Extract decision and confidence from AlgorithmOne result
        $algorithmOneDecision = [
            'bet' => $algorithmOneResult['bet'],
            'reason' => $algorithmOneResult['reason'],
        ];
        $algorithmOneDebug = is_array($algorithmOneResult['debug'] ?? null)
            ? $algorithmOneResult['debug']
            : [];
        
        // Build scores structure for compatibility with formatter and logging
        $scores = [
            'probability' => $algorithmOneResult['confidence'],
            'algorithm_version' => $algorithmVersion,
            'form_score' => $algorithmOneDebug['components']['form_score']
                ?? $algorithmOneDebug['components']['probability_breakdown']['form_score']
                ?? null,
            'h2h_score' => $algorithmOneDebug['components']['h2h_score']
                ?? $algorithmOneDebug['components']['probability_breakdown']['h2h_score']
                ?? null,
            'live_score' => $algorithmOneDebug['components']['live_score']
                ?? $algorithmOneDebug['components']['probability_breakdown']['live_score_adjusted']
                ?? null,
            'components' => $algorithmOneDebug['components'] ?? null,
            'debug_trace' => $algorithmOneDebug,
        ];
        
        // Handle dual-run data if available
        $legacyScores = null;
        $v2Scores = null;
        if (isset($algorithmOneResult['dual_run'])) {
            $dualRun = $algorithmOneResult['dual_run'];
            $legacyScores = ['probability' => $dualRun['legacy_probability']];
            $v2Scores = ['probability' => $dualRun['v2_probability']];
            $scores['dual_run'] = $dualRun;
        }
        
        $algorithmTwoData = $this->extractor->extractAlgorithmTwoData($match);
        $algorithmThreeData = $this->extractor->extractAlgorithmThreeData($match);
        $algorithmXData = $this->extractor->extractAlgorithmXData($match);
        
        $algorithmTwoDecision = $this->filter->shouldBetAlgorithmTwo($liveData, $algorithmTwoData);
        $algorithmThreeDecision = $this->filter->shouldBetAlgorithmThree($algorithmThreeData);
        
        // Analyze with AlgorithmX
        $algorithmXResult = $this->algorithmX->analyze(['live_data' => $algorithmXData]);
        $algorithmXDecision = [
            'bet' => $algorithmXResult['bet'],
            'reason' => $algorithmXResult['reason'],
        ];
        $this->collectAlgorithmXMonitoring($algorithmXResult, $algorithmXDecision);

        // Save Algorithm 1 debug payload to database
        $algorithmOneStoragePayload = ($algorithmVersion === 2 || isset($scores['dual_run']))
            ? [
                'algorithm_version' => $algorithmVersion,
                'gating_passed' => $algorithmOneDebug['gating_passed'] ?? false,
                'gating_reason' => $algorithmOneDebug['gating_reason'] ?? '',
                'decision_reason' => $algorithmOneDebug['decision_reason'] ?? $algorithmOneDecision['reason'],
                'probability' => $algorithmOneResult['confidence'],
                'components' => $algorithmOneDebug['components'] ?? [],
                'red_flag' => $algorithmOneDebug['red_flag'] ?? null,
                'red_flags' => $algorithmOneDebug['red_flags'] ?? [],
                'penalties' => $algorithmOneDebug['penalties'] ?? [],
                'gating_context' => $algorithmOneDebug['gating_context'] ?? [],
                'dual_run' => $scores['dual_run'] ?? null,
            ]
            : null;
        $this->extractor->updateAlgorithmData($base['match_id'], $algorithmVersion, $algorithmOneStoragePayload);

        if (!$formData['has_data'] || !$h2hData['has_data']) {
            Logger::info('Scanner algorithm 1 skipped because statistics are incomplete', [
                'match_id' => $base['match_id'],
                'home' => $base['home'],
                'away' => $base['away'],
                'has_form' => $formData['has_data'],
                'has_h2h' => $h2hData['has_data'],
                'reason' => $algorithmOneDecision['reason'],
                'algorithm_version' => $algorithmVersion,
                'gating_reason' => $algorithmOneDebug['gating_reason'] ?? '',
            ]);
        }
        
        // Log rejection reasons
        if (!$algorithmOneDecision['bet']) {
            $this->incrementAlgorithmOneRejectReason($algorithmOneDecision['reason']);

            Logger::info('Scanner algorithm 1 rejected signal', [
                'match_id' => $base['match_id'],
                'home' => $base['home'],
                'away' => $base['away'],
                'minute' => $liveData['minute'],
                'reason' => $algorithmOneDecision['reason'],
                'probability' => $scores['probability'],
                'algorithm_version' => $algorithmVersion,
                'gating_passed' => $algorithmOneDebug['gating_passed'] ?? false,
                'gating_reason' => $algorithmOneDebug['gating_reason'] ?? '',
                'red_flag' => $algorithmOneDebug['red_flag'] ?? null,
                'red_flags' => $algorithmOneDebug['red_flags'] ?? [],
                'penalties' => $algorithmOneDebug['penalties'] ?? [],
                'gating_context' => $algorithmOneDebug['gating_context'] ?? [],
                'components' => $algorithmOneDebug['components'] ?? [],
            ]);
        } else {
            $this->algorithmOneAcceptedSignals++;
        }

        if (!$algorithmTwoData['has_data']) {
            Logger::info('Scanner algorithm 2 skipped because data are incomplete', [
                'match_id' => $base['match_id'],
                'home' => $base['home'],
                'away' => $base['away'],
                'reason' => $algorithmTwoDecision['reason'],
            ]);
        }

        if (!$algorithmThreeData['has_data']) {
            Logger::info('Scanner algorithm 3 skipped because table data are incomplete', [
                'match_id' => $base['match_id'],
                'home' => $base['home'],
                'away' => $base['away'],
                'reason' => $algorithmThreeDecision['reason'],
            ]);
        }

        // Log AlgorithmX results
        if (!$algorithmXData['has_data']) {
            Logger::info('Scanner algorithm X skipped because live data are incomplete', [
                'match_id' => $base['match_id'],
                'home' => $base['home'],
                'away' => $base['away'],
                'minute' => $algorithmXData['minute'],
                'reason' => $algorithmXDecision['reason'],
            ]);
        } elseif (!$algorithmXDecision['bet']) {
            Logger::info('Scanner algorithm X rejected signal', [
                'match_id' => $base['match_id'],
                'home' => $base['home'],
                'away' => $base['away'],
                'minute' => $algorithmXData['minute'],
                'reason' => $algorithmXDecision['reason'],
                'probability' => $algorithmXResult['confidence'],
                'debug' => $algorithmXResult['debug'] ?? [],
            ]);
        } else {
            Logger::info('Scanner algorithm X accepted signal', [
                'match_id' => $base['match_id'],
                'home' => $base['home'],
                'away' => $base['away'],
                'minute' => $algorithmXData['minute'],
                'probability' => $algorithmXResult['confidence'],
                'debug' => $algorithmXResult['debug'] ?? [],
            ]);
        }

        return [
            $this->formatter->formatAlgorithmOne(
                $base,
                $liveData,
                $scores,
                $formData,
                $h2hData,
                $algorithmOneDecision,
                $legacyScores,
                $v2Scores
            ),
            $this->formatter->formatAlgorithmTwo($base, $liveData, $formData, $h2hData, $algorithmTwoData, $algorithmTwoDecision),
            $this->formatter->formatAlgorithmThree($base, $liveData, $formData, $h2hData, $algorithmThreeData, $algorithmThreeDecision),
            $this->formatter->formatAlgorithmX($base, $liveData, $algorithmXData, $algorithmXResult, $algorithmXDecision),
        ];
    }

    /**
     * Extract base match data.
     * 
     * @param array<string,mixed> $match
     * @return array{match_id:int,country:string,liga:string,home:string,away:string}
     */
    private function extractBaseMatchData(array $match): array
    {
        return [
            'match_id' => (int) ($match['id'] ?? 0),
            'country' => (string) ($match['country'] ?? ''),
            'liga' => (string) ($match['liga'] ?? ''),
            'home' => (string) ($match['home'] ?? ''),
            'away' => (string) ($match['away'] ?? ''),
        ];
    }

    private function incrementAlgorithmOneRejectReason(string $reason): void
    {
        $normalizedReason = trim($reason);
        if ($normalizedReason === '') {
            $normalizedReason = 'unknown';
        }

        $this->algorithmOneRejectSummary[$normalizedReason] = ($this->algorithmOneRejectSummary[$normalizedReason] ?? 0) + 1;
    }

    /**
     * @param array<string,mixed> $algorithmXResult
     * @param array{bet:bool,reason:string} $algorithmXDecision
     */
    private function collectAlgorithmXMonitoring(array $algorithmXResult, array $algorithmXDecision): void
    {
        $probability = (float) ($algorithmXResult['confidence'] ?? 0.0);
        $this->algorithmXMonitoring['analyzed']++;
        $this->algorithmXMonitoring['probability_sum'] += $probability;

        if ($probability < 0.10) {
            $this->algorithmXMonitoring['low_probability']++;
        }

        if ($algorithmXDecision['bet']) {
            $this->algorithmXMonitoring['bet_true']++;
            $this->algorithmXMonitoring['bet_true_probability_sum'] += $probability;
            return;
        }

        $this->algorithmXMonitoring['bet_false_probability_sum'] += $probability;
    }

    private function logAlgorithmXMonitoringSummary(): void
    {
        $analyzed = $this->algorithmXMonitoring['analyzed'];
        if ($analyzed === 0) {
            Logger::info('Scanner algorithm X monitoring summary', [
                'analyzed' => 0,
            ]);
            return;
        }

        $betTrue = $this->algorithmXMonitoring['bet_true'];
        $betFalse = $analyzed - $betTrue;
        $lowProbabilityRate = $this->algorithmXMonitoring['low_probability'] / $analyzed;
        $betTrueRate = $betTrue / $analyzed;
        $avgProbability = $this->algorithmXMonitoring['probability_sum'] / $analyzed;
        $avgBetTrueProbability = $betTrue > 0
            ? $this->algorithmXMonitoring['bet_true_probability_sum'] / $betTrue
            : 0.0;
        $avgBetFalseProbability = $betFalse > 0
            ? $this->algorithmXMonitoring['bet_false_probability_sum'] / $betFalse
            : 0.0;

        Logger::info('Scanner algorithm X monitoring summary', [
            'analyzed' => $analyzed,
            'bet_true' => $betTrue,
            'bet_true_rate' => round($betTrueRate, 4),
            'low_probability_rate' => round($lowProbabilityRate, 4),
            'avg_probability' => round($avgProbability, 4),
            'avg_probability_bet_true' => round($avgBetTrueProbability, 4),
            'avg_probability_bet_false' => round($avgBetFalseProbability, 4),
        ]);

        if ($lowProbabilityRate > 0.90) {
            Logger::warning('Scanner algorithm X alert: too many low probability matches', [
                'analyzed' => $analyzed,
                'low_probability_rate' => round($lowProbabilityRate, 4),
            ]);
        }

        if ($betTrueRate > 0.50) {
            Logger::warning('Scanner algorithm X alert: bet=true rate is unusually high', [
                'analyzed' => $analyzed,
                'bet_true_rate' => round($betTrueRate, 4),
            ]);
        }
    }
}
