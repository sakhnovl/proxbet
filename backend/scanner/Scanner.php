<?php

declare(strict_types=1);

namespace Proxbet\Scanner;

use Proxbet\Line\Logger;

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
    ) {
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
        $liveData = $this->extractor->extractLiveData($match);

        if ($liveData['minute'] === 0) {
            return [];
        }

        if ($liveData['minute'] > 45 && trim($liveData['match_status']) !== 'Перерыв') {
            return [];
        }

        $formData = $this->extractor->extractFormData($match);
        $h2hData = $this->extractor->extractH2hData($match);
        $scores = $this->calculator->calculateAll($formData, $h2hData, $liveData);
        $algorithmTwoData = $this->extractor->extractAlgorithmTwoData($match);
        $algorithmThreeData = $this->extractor->extractAlgorithmThreeData($match);

        $algorithmOneDecision = $this->filter->shouldBetAlgorithmOne(
            $liveData,
            $scores['probability'],
            $formData,
            $h2hData
        );
        $algorithmTwoDecision = $this->filter->shouldBetAlgorithmTwo($liveData, $algorithmTwoData);
        $algorithmThreeDecision = $this->filter->shouldBetAlgorithmThree($algorithmThreeData);

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

        if (!$algorithmThreeData['has_data']) {
            Logger::info('Scanner algorithm 3 skipped because table data are incomplete', [
                'match_id' => $base['match_id'],
                'home' => $base['home'],
                'away' => $base['away'],
                'reason' => $algorithmThreeDecision['reason'],
            ]);
        }

        return [
            $this->buildAlgorithmOneResult($base, $liveData, $scores, $formData, $h2hData, $algorithmOneDecision),
            $this->buildAlgorithmTwoResult($base, $liveData, $formData, $h2hData, $algorithmTwoData, $algorithmTwoDecision),
            $this->buildAlgorithmThreeResult($base, $liveData, $formData, $h2hData, $algorithmThreeData, $algorithmThreeDecision),
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
     * @param array<string,mixed> $liveData
     * @param array<string,mixed> $scores
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
            'first_half_goal',
            $decision,
            [
                'score_home' => $liveData['ht_hscore'],
                'score_away' => $liveData['ht_ascore'],
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
     * @param array<string,mixed> $liveData
     * @param array{home_goals:int,away_goals:int,has_data:bool} $formData
     * @param array{home_goals:int,away_goals:int,has_data:bool} $h2hData
     * @param array<string,mixed> $algorithmTwoData
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
            'first_half_goal',
            $decision,
            [
                'score_home' => $liveData['ht_hscore'],
                'score_away' => $liveData['ht_ascore'],
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
     * @param array<string,mixed> $liveData
     * @param array{home_goals:int,away_goals:int,has_data:bool} $formData
     * @param array{home_goals:int,away_goals:int,has_data:bool} $h2hData
     * @param array<string,mixed> $algorithmThreeData
     * @param array<string,mixed> $decision
     * @return array<string,mixed>
     */
    private function buildAlgorithmThreeResult(
        array $base,
        array $liveData,
        array $formData,
        array $h2hData,
        array $algorithmThreeData,
        array $decision
    ): array {
        $algorithmThreePayload = [
            'selected_team_side' => $decision['selected_team_side'] ?? null,
            'selected_team_name' => $decision['selected_team_name'] ?? null,
            'selected_team_goals_current' => $decision['selected_team_goals_current'] ?? null,
            'selected_team_target_bet' => $decision['selected_team_target_bet'] ?? null,
            'triggered_rule' => $decision['triggered_rule'] ?? null,
            'triggered_rule_label' => $decision['triggered_rule_label'] ?? null,
            'home_attack_ratio' => $decision['home_attack_ratio'] ?? $this->calculateRatio(
                (int) ($algorithmThreeData['table_goals_1'] ?? 0),
                (int) ($algorithmThreeData['table_games_1'] ?? 0)
            ),
            'away_defense_ratio' => $decision['away_defense_ratio'] ?? $this->calculateRatio(
                (int) ($algorithmThreeData['table_missed_2'] ?? 0),
                (int) ($algorithmThreeData['table_games_2'] ?? 0)
            ),
            'away_attack_ratio' => $decision['away_attack_ratio'] ?? $this->calculateRatio(
                (int) ($algorithmThreeData['table_goals_2'] ?? 0),
                (int) ($algorithmThreeData['table_games_2'] ?? 0)
            ),
            'home_defense_ratio' => $decision['home_defense_ratio'] ?? $this->calculateRatio(
                (int) ($algorithmThreeData['table_missed_1'] ?? 0),
                (int) ($algorithmThreeData['table_games_1'] ?? 0)
            ),
            'table_games_1' => $algorithmThreeData['table_games_1'],
            'table_goals_1' => $algorithmThreeData['table_goals_1'],
            'table_missed_1' => $algorithmThreeData['table_missed_1'],
            'table_games_2' => $algorithmThreeData['table_games_2'],
            'table_goals_2' => $algorithmThreeData['table_goals_2'],
            'table_missed_2' => $algorithmThreeData['table_missed_2'],
            'match_status' => $algorithmThreeData['match_status'],
        ];

        return $this->buildCommonResult(
            $base,
            $liveData,
            self::ALGORITHM_THREE_ID,
            'Алгоритм 3',
            'team_total',
            $decision,
            [
                'score_home' => $liveData['live_hscore'],
                'score_away' => $liveData['live_ascore'],
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
                'algorithm_data' => $algorithmThreePayload,
            ]
        );
    }

    /**
     * @param array{match_id:int,country:string,liga:string,home:string,away:string} $base
     * @param array<string,mixed> $liveData
     * @param array{probability:float|null,form_score:float|null,h2h_score:float|null,live_score:float|null,score_home:int,score_away:int,form_data:array<string,mixed>,h2h_data:array<string,mixed>,algorithm_data:array<string,mixed>|null} $payload
     * @param array<string,mixed> $decision
     * @return array<string,mixed>
     */
    private function buildCommonResult(
        array $base,
        array $liveData,
        int $algorithmId,
        string $algorithmName,
        string $signalType,
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
            'match_status' => $liveData['match_status'],
            'score_home' => $payload['score_home'],
            'score_away' => $payload['score_away'],
            'algorithm_id' => $algorithmId,
            'algorithm_name' => $algorithmName,
            'signal_type' => $signalType,
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

    private function calculateRatio(int $value, int $games): float
    {
        if ($games <= 0) {
            return 0.0;
        }

        return ($value / 2) / $games;
    }
}
