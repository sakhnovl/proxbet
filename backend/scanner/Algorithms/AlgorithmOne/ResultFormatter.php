<?php

declare(strict_types=1);

namespace Proxbet\Scanner\Algorithms\AlgorithmOne;

/**
 * Formats Algorithm 1 results for output.
 * Isolated version containing only Algorithm 1 specific formatting logic.
 */
final class ResultFormatter
{
    private const ALGORITHM_ID = 1;
    private const ALGORITHM_NAME = 'Алгоритм 1';
    private const SIGNAL_TYPE = 'first_half_goal';

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
    public function format(
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
        
        $algorithmData = null;
        if ($algorithmVersion === 2) {
            $algorithmData = [
                'algorithm_version' => 2,
                'components' => $scores['components'] ?? null,
                'red_flag' => $scores['components']['red_flag'] ?? null,
            ];
            
            if ($legacyScores !== null && $v2Scores !== null) {
                $algorithmData['dual_run'] = [
                    'legacy_probability' => $legacyScores['probability'] ?? 0.0,
                    'v2_probability' => $v2Scores['probability'] ?? 0.0,
                    'probability_diff' => ($v2Scores['probability'] ?? 0.0) - ($legacyScores['probability'] ?? 0.0),
                ];
            }
        }
        
        return $this->buildResult(
            $base,
            $liveData,
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
                'algorithm_data' => $algorithmData,
            ]
        );
    }

    /**
     * Build result structure.
     * 
     * @param array{match_id:int,country:string,liga:string,home:string,away:string} $base
     * @param array<string,mixed> $liveData
     * @param array<string,mixed> $decision
     * @param array{probability:float|null,form_score:float|null,h2h_score:float|null,live_score:float|null,score_home:int,score_away:int,form_data:array<string,mixed>,h2h_data:array<string,mixed>,algorithm_data:array<string,mixed>|null} $payload
     * @return array<string,mixed>
     */
    private function buildResult(
        array $base,
        array $liveData,
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
            'algorithm_id' => self::ALGORITHM_ID,
            'algorithm_name' => self::ALGORITHM_NAME,
            'signal_type' => self::SIGNAL_TYPE,
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
