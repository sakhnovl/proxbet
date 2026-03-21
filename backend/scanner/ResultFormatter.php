<?php

declare(strict_types=1);

namespace Proxbet\Scanner;

/**
 * Formats scanner results for output.
 * Separates formatting logic from analysis logic (SRP).
 * 
 * @deprecated This class is deprecated and will be removed in a future version.
 *             Use Proxbet\Scanner\Algorithms\AlgorithmOne\ResultFormatter for Algorithm 1 result formatting.
 *             
 * Migration path:
 * - Algorithm 1: Use AlgorithmOne\ResultFormatter::format()
 * - Algorithm 2/3: Continue using this class for now
 */
final class ResultFormatter
{
    private const ALGORITHM_ONE_ID = 1;
    private const ALGORITHM_TWO_ID = 2;
    private const ALGORITHM_THREE_ID = 3;

    /**
     * Build Algorithm 1 result structure.
     * 
     * @param array{match_id:int,country:string,liga:string,home:string,away:string} $base
     * @param array<string,mixed> $liveData
     * @param array<string,mixed> $scores
     * @param array{home_goals:int,away_goals:int,has_data:bool} $formData
     * @param array{home_goals:int,away_goals:int,has_data:bool} $h2hData
     * @param array{bet:bool,reason:string} $decision
     * @param array<string,mixed>|null $legacyScores
     * @param array<string,mixed>|null $v2Scores
     * @return array<string,mixed>
     */
    public function formatAlgorithmOne(
        array $base,
        array $liveData,
        array $scores,
        array $formData,
        array $h2hData,
        array $decision,
        ?array $legacyScores = null,
        ?array $v2Scores = null
    ): array {
        $algorithmVersion = $scores['algorithm_version'] ?? 1;
        
        $hasDualRun = isset($scores['dual_run']) && is_array($scores['dual_run']);
        $algorithmData = null;
        if ($algorithmVersion === 2 || $hasDualRun) {
            $debugTrace = is_array($scores['debug_trace'] ?? null) ? $scores['debug_trace'] : [];

            $algorithmData = [
                'algorithm_version' => $algorithmVersion,
                'components' => $scores['components'] ?? null,
                'red_flag' => $debugTrace['red_flag'] ?? ($scores['components']['red_flag'] ?? null),
                'gating_passed' => $debugTrace['gating_passed'] ?? false,
                'gating_reason' => $debugTrace['gating_reason'] ?? '',
                'decision_reason' => $debugTrace['decision_reason'] ?? $decision['reason'],
                'probability' => $debugTrace['probability'] ?? $scores['probability'],
                'debug_trace' => $debugTrace,
            ];
            
            if ($legacyScores !== null && $v2Scores !== null) {
                $algorithmData['dual_run'] = [
                    'legacy_probability' => $legacyScores['probability'] ?? 0.0,
                    'v2_probability' => $v2Scores['probability'] ?? 0.0,
                    'probability_diff' => ($v2Scores['probability'] ?? 0.0) - ($legacyScores['probability'] ?? 0.0),
                ];
            }

            if ($hasDualRun) {
                $algorithmData['dual_run'] = $scores['dual_run'];
            }
        }
        
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
                'form_score' => $scores['form_score'] ?? null,
                'h2h_score' => $scores['h2h_score'] ?? null,
                'live_score' => $scores['live_score'] ?? null,
                'form_data' => [
                    'home_goals' => $formData['home_goals'],
                    'away_goals' => $formData['away_goals'],
                ],
                'h2h_data' => [
                    'home_goals' => $h2hData['home_goals'],
                    'away_goals' => $h2hData['away_goals'],
                ],
                'algorithm_data' => $algorithmData,
            ]
        );
    }

    /**
     * Build Algorithm 2 result structure.
     * 
     * @param array{match_id:int,country:string,liga:string,home:string,away:string} $base
     * @param array<string,mixed> $liveData
     * @param array{home_goals:int,away_goals:int,has_data:bool} $formData
     * @param array{home_goals:int,away_goals:int,has_data:bool} $h2hData
     * @param array<string,mixed> $algorithmTwoData
     * @param array{bet:bool,reason:string} $decision
     * @return array<string,mixed>
     */
    public function formatAlgorithmTwo(
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
     * Build Algorithm 3 result structure.
     * 
     * @param array{match_id:int,country:string,liga:string,home:string,away:string} $base
     * @param array<string,mixed> $liveData
     * @param array{home_goals:int,away_goals:int,has_data:bool} $formData
     * @param array{home_goals:int,away_goals:int,has_data:bool} $h2hData
     * @param array<string,mixed> $algorithmThreeData
     * @param array<string,mixed> $decision
     * @return array<string,mixed>
     */
    public function formatAlgorithmThree(
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
     * Build common result structure for all algorithms.
     * 
     * @param array{match_id:int,country:string,liga:string,home:string,away:string} $base
     * @param array<string,mixed> $liveData
     * @param int $algorithmId
     * @param string $algorithmName
     * @param string $signalType
     * @param array<string,mixed> $decision
     * @param array{probability:float|null,form_score:float|null,h2h_score:float|null,live_score:float|null,score_home:int,score_away:int,form_data:array<string,mixed>,h2h_data:array<string,mixed>,algorithm_data:array<string,mixed>|null} $payload
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

    /**
     * Calculate ratio for algorithm 3.
     */
    private function calculateRatio(int $value, int $games): float
    {
        if ($games <= 0) {
            return 0.0;
        }

        return ($value / 2) / $games;
    }
}
