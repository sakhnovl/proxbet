<?php

declare(strict_types=1);

namespace Proxbet\Scanner;

use Proxbet\Line\Logger;
use Proxbet\Statistic\HtMetricsCalculator;

/**
 * Main scanner orchestrator for match analysis.
 */
final class Scanner
{
    private const ALGORITHM_ONE_ID = 1;
    private const ALGORITHM_TWO_ID = 2;
    private const ALGORITHM_THREE_ID = 3;

    public function __construct(
        private DataExtractor $extractor,
        private ProbabilityCalculator $calculator,
        private MatchFilter $filter,
        private ResultFormatter $formatter,
        private ?HtMetricsCalculator $htCalculator = null,
    ) {
        $this->htCalculator = $htCalculator ?? new HtMetricsCalculator();
    }

    /**
     * @return array{total:int,analyzed:int,signals:int,results:array<int,array<string,mixed>>}
     */
    public function scan(): array
    {
        $matches = $this->extractor->getActiveMatches();
        $total = count($matches);
        $analyzed = 0;
        $signals = 0;
        $results = [];

        Logger::info('Scanner started', ['total_matches' => $total]);

        foreach ($matches as $match) {
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

        return [
            'total' => $total,
            'analyzed' => $analyzed,
            'signals' => $signals,
            'results' => $results,
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
        
        // Calculate scores based on version and dual-run mode
        $legacyScores = null;
        $v2Scores = null;
        
        if ($isDualRun) {
            // Calculate both versions with their respective data
            $legacyScores = $this->calculator->calculateLegacy($formDataLegacy, $h2hData, $liveDataLegacy);
            $v2Scores = $this->calculator->calculateV2($formDataV2, $h2hData, $liveDataV2);
            
            // Use the configured version as primary
            $scores = $algorithmVersion === 2 ? $v2Scores : $legacyScores;
        } else {
            // Calculate only the configured version
            $scores = $this->calculator->calculateAll($formData, $h2hData, $liveData);
        }
        
        $algorithmTwoData = $this->extractor->extractAlgorithmTwoData($match);
        $algorithmThreeData = $this->extractor->extractAlgorithmThreeData($match);

        // Determine Algorithm 1 decision based on version
        $algorithmOneDecision = $this->determineAlgorithmOneDecision(
            $scores,
            $liveData,
            $formData,
            $h2hData
        );
        
        $algorithmTwoDecision = $this->filter->shouldBetAlgorithmTwo($liveData, $algorithmTwoData);
        $algorithmThreeDecision = $this->filter->shouldBetAlgorithmThree($algorithmThreeData);

        // Save algorithm version and components to database for Algorithm 1
        $algorithmVersion = $scores['algorithm_version'] ?? 1;
        $components = ($algorithmVersion === 2 && isset($scores['components'])) ? $scores['components'] : null;
        $this->extractor->updateAlgorithmData($base['match_id'], $algorithmVersion, $components);

        if (!$formData['has_data'] || !$h2hData['has_data']) {
            Logger::info('Scanner algorithm 1 skipped because statistics are incomplete', [
                'match_id' => $base['match_id'],
                'home' => $base['home'],
                'away' => $base['away'],
                'has_form' => $formData['has_data'],
                'has_h2h' => $h2hData['has_data'],
                'reason' => $algorithmOneDecision['reason'],
                'algorithm_version' => $scores['algorithm_version'] ?? 1,
            ]);
        }
        
        // Log v2 rejection reasons with detailed components
        if (isset($scores['algorithm_version']) && $scores['algorithm_version'] === 2) {
            if (!($algorithmOneDecision['bet'] ?? false)) {
                $components = $scores['components'] ?? [];
                Logger::info('Scanner algorithm 1 v2 rejected signal', [
                    'match_id' => $base['match_id'],
                    'home' => $base['home'],
                    'away' => $base['away'],
                    'minute' => $liveData['minute'],
                    'reason' => $algorithmOneDecision['reason'] ?? 'unknown',
                    'probability' => $scores['probability'] ?? 0.0,
                    'components' => [
                        'pdi' => $components['pdi'] ?? null,
                        'shot_quality' => $components['shot_quality'] ?? null,
                        'trend_acceleration' => $components['trend_acceleration'] ?? null,
                        'time_pressure' => $components['time_pressure'] ?? null,
                        'xg_pressure' => $components['xg_pressure'] ?? null,
                        'card_factor' => $components['card_factor'] ?? null,
                        'league_factor' => $components['league_factor'] ?? null,
                        'red_flag' => $components['red_flag'] ?? null,
                    ],
                    'form_score' => $scores['form_score'] ?? null,
                    'h2h_score' => $scores['h2h_score'] ?? null,
                    'live_score' => $scores['live_score'] ?? null,
                ]);
            }
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
        ];
    }

    /**
     * Determine Algorithm 1 decision based on version.
     * 
     * @param array<string,mixed> $scores
     * @param array<string,mixed> $liveData
     * @param array{home_goals:int,away_goals:int,has_data:bool} $formData
     * @param array{home_goals:int,away_goals:int,has_data:bool} $h2hData
     * @return array{bet:bool,reason:string}
     */
    private function determineAlgorithmOneDecision(
        array $scores,
        array $liveData,
        array $formData,
        array $h2hData
    ): array {
        $algorithmVersion = $scores['algorithm_version'] ?? 1;
        
        // For v2, use the decision from ProbabilityCalculator
        if ($algorithmVersion === 2 && isset($scores['decision'])) {
            return $scores['decision'];
        }
        
        // For legacy, use MatchFilter
        return $this->filter->shouldBetAlgorithmOne(
            $liveData,
            $scores['probability'],
            $formData,
            $h2hData
        );
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
}
