<?php

declare(strict_types=1);

namespace Proxbet\Scanner;

use Proxbet\Line\Logger;

/**
 * Main scanner orchestrator for first half goal probability analysis.
 */
final class Scanner
{
    private const ALGORITHM_ONE_ID = 1;
    private const ALGORITHM_TWO_ID = 2;

    public function __construct(
        private DataExtractor $extractor,
        private ProbabilityCalculator $calculator,
        private MatchFilter $filter,
    ) {
    }

    /**
     * Scan all active matches and return analysis results.
     *
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
     * Scan a single match and return analysis results for all algorithms.
     *
     * @param array<string,mixed> $match
     * @return array<int,array<string,mixed>>
     */
    private function scanMatch(array $match): array
    {
        $base = $this->extractBaseMatchData($match);
        $liveData = $this->extractor->extractLiveData($match);

        if ($liveData['minute'] === 0 || $liveData['minute'] > 45) {
            return [];
        }

        $formData = $this->extractor->extractFormData($match);
        $h2hData = $this->extractor->extractH2hData($match);
        $scores = $this->calculator->calculateAll($formData, $h2hData, $liveData);
        $algorithmTwoData = $this->extractor->extractAlgorithmTwoData($match);

        $algorithmOneDecision = $this->filter->shouldBetAlgorithmOne(
            $liveData,
            $scores['probability'],
            $formData,
            $h2hData
        );
        $algorithmTwoDecision = $this->filter->shouldBetAlgorithmTwo($liveData, $algorithmTwoData);

        if (!$formData['has_data'] || !$h2hData['has_data']) {
            Logger::info('Scanner algorithm 1 skipped because statistics are incomplete', [
                'match_id' => $base['match_id'],
                'home' => $base['home'],
                'away' => $base['away'],
                'has_form' => $formData['has_data'],
                'has_h2h' => $h2hData['has_data'],
                'reason' => $algorithmOneDecision['reason'],
            ]);
        }

        if (!$algorithmTwoData['has_data']) {
            Logger::info('Scanner algorithm 2 skipped because data are incomplete', [
                'match_id' => $base['match_id'],
                'home' => $base['home'],
                'away' => $base['away'],
                'reason' => $algorithmTwoDecision['reason'],
            ]);
        }

        return [
            $this->buildAlgorithmOneResult($base, $liveData, $scores, $formData, $h2hData, $algorithmOneDecision),
            $this->buildAlgorithmTwoResult($base, $liveData, $formData, $h2hData, $algorithmTwoData, $algorithmTwoDecision),
        ];
    }

    /**
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

    /**
     * @param array{match_id:int,country:string,liga:string,home:string,away:string} $base
     * @param array{minute:int,shots_total:int,shots_on_target:int,dangerous_attacks:int,corners:int,ht_hscore:int,ht_ascore:int,time_str:string} $liveData
     * @param array{form_score:float,h2h_score:float,live_score:float,probability:float} $scores
     * @param array{home_goals:int,away_goals:int,has_data:bool} $formData
     * @param array{home_goals:int,away_goals:int,has_data:bool} $h2hData
     * @param array{bet:bool,reason:string} $decision
     * @return array<string,mixed>
     */
    private function buildAlgorithmOneResult(
        array $base,
        array $liveData,
        array $scores,
        array $formData,
        array $h2hData,
        array $decision
    ): array {
        return $this->buildCommonResult(
            $base,
            $liveData,
            self::ALGORITHM_ONE_ID,
            'Алгоритм 1',
            $decision,
            [
                'probability' => $scores['probability'],
                'form_score' => $scores['form_score'],
                'h2h_score' => $scores['h2h_score'],
                'live_score' => $scores['live_score'],
                'form_data' => [
                    'home_goals' => $formData['home_goals'],
                    'away_goals' => $formData['away_goals'],
                ],
                'h2h_data' => [
                    'home_goals' => $h2hData['home_goals'],
                    'away_goals' => $h2hData['away_goals'],
                ],
                'algorithm_data' => null,
            ]
        );
    }

    /**
     * @param array{match_id:int,country:string,liga:string,home:string,away:string} $base
     * @param array{minute:int,shots_total:int,shots_on_target:int,dangerous_attacks:int,corners:int,ht_hscore:int,ht_ascore:int,time_str:string} $liveData
     * @param array{home_goals:int,away_goals:int,has_data:bool} $formData
     * @param array{home_goals:int,away_goals:int,has_data:bool} $h2hData
     * @param array{
     *   home_win_odd:float,
     *   over_25_odd:float|null,
     *   total_line:float|null,
     *   over_25_odd_check_skipped:bool,
     *   home_first_half_goals_in_last_5:int,
     *   h2h_first_half_goals_in_last_5:int,
     *   has_data:bool
     * } $algorithmTwoData
     * @param array{bet:bool,reason:string} $decision
     * @return array<string,mixed>
     */
    private function buildAlgorithmTwoResult(
        array $base,
        array $liveData,
        array $formData,
        array $h2hData,
        array $algorithmTwoData,
        array $decision
    ): array {
        return $this->buildCommonResult(
            $base,
            $liveData,
            self::ALGORITHM_TWO_ID,
            'Алгоритм 2',
            $decision,
            [
                'probability' => null,
                'form_score' => null,
                'h2h_score' => null,
                'live_score' => null,
                'form_data' => [
                    'home_goals' => $formData['home_goals'],
                    'away_goals' => $formData['away_goals'],
                ],
                'h2h_data' => [
                    'home_goals' => $h2hData['home_goals'],
                    'away_goals' => $h2hData['away_goals'],
                ],
                'algorithm_data' => $algorithmTwoData,
            ]
        );
    }

    /**
     * @param array{match_id:int,country:string,liga:string,home:string,away:string} $base
     * @param array{minute:int,shots_total:int,shots_on_target:int,dangerous_attacks:int,corners:int,ht_hscore:int,ht_ascore:int,time_str:string} $liveData
     * @param array{bet:bool,reason:string} $decision
     * @param array{
     *   probability:float|null,
     *   form_score:float|null,
     *   h2h_score:float|null,
     *   live_score:float|null,
     *   form_data:array{home_goals:int,away_goals:int},
     *   h2h_data:array{home_goals:int,away_goals:int},
     *   algorithm_data:array<string,mixed>|null
     * } $payload
     * @return array<string,mixed>
     */
    private function buildCommonResult(
        array $base,
        array $liveData,
        int $algorithmId,
        string $algorithmName,
        array $decision,
        array $payload
    ): array {
        return [
            'match_id' => $base['match_id'],
            'country' => $base['country'],
            'liga' => $base['liga'],
            'home' => $base['home'],
            'away' => $base['away'],
            'minute' => $liveData['minute'],
            'time' => $liveData['time_str'],
            'score_home' => $liveData['ht_hscore'],
            'score_away' => $liveData['ht_ascore'],
            'algorithm_id' => $algorithmId,
            'algorithm_name' => $algorithmName,
            'signal_type' => 'first_half_goal',
            'probability' => $payload['probability'],
            'form_score' => $payload['form_score'],
            'h2h_score' => $payload['h2h_score'],
            'live_score' => $payload['live_score'],
            'decision' => $decision,
            'stats' => [
                'shots_total' => $liveData['shots_total'],
                'shots_on_target' => $liveData['shots_on_target'],
                'dangerous_attacks' => $liveData['dangerous_attacks'],
                'corners' => $liveData['corners'],
            ],
            'form_data' => $payload['form_data'],
            'h2h_data' => $payload['h2h_data'],
            'algorithm_data' => $payload['algorithm_data'],
        ];
    }
}
