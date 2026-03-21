<?php

declare(strict_types=1);

namespace Proxbet\Scanner\Algorithms\AlgorithmOne;

/**
 * Extracts and structures data from match records for Algorithm 1 analysis.
 * Isolated version containing only Algorithm 1 specific extraction logic.
 */
final class DataExtractor
{
    /**
     * Extract form data from match record (legacy).
     *
     * @param array<string,mixed> $match
     * @return array{home_goals:int,away_goals:int,has_data:bool}
     */
    public function extractFormData(array $match): array
    {
        $homeGoals = $this->getIntOrNull($match, 'ht_match_goals_1');
        $awayGoals = $this->getIntOrNull($match, 'ht_match_goals_2');

        $hasData = $homeGoals !== null && $awayGoals !== null;

        return [
            'home_goals' => $homeGoals ?? 0,
            'away_goals' => $awayGoals ?? 0,
            'has_data' => $hasData,
        ];
    }

    /**
     * Extract form data for v2 with weighted components.
     *
     * @param array<string,mixed> $match
     * @param array<string,mixed>|null $weightedMetrics Weighted form metrics from WeightedFormService
     * @return array{
     *   home_goals:int,
     *   away_goals:int,
     *   has_data:bool,
     *   weighted:array{
     *     home:array{attack:float,defense:float},
     *     away:array{attack:float,defense:float},
     *     score:float
     *   }|null
     * }
     */
    public function extractFormDataV2(array $match, ?array $weightedMetrics = null): array
    {
        $homeGoals = $this->getIntOrNull($match, 'ht_match_goals_1');
        $awayGoals = $this->getIntOrNull($match, 'ht_match_goals_2');

        $hasData = $homeGoals !== null && $awayGoals !== null;

        $weighted = null;
        if ($weightedMetrics !== null && isset($weightedMetrics['weighted_form'])) {
            $weighted = $weightedMetrics['weighted_form'];
        }

        return [
            'home_goals' => $homeGoals ?? 0,
            'away_goals' => $awayGoals ?? 0,
            'has_data' => $hasData,
            'weighted' => $weighted,
        ];
    }

    /**
     * Extract H2H data from match record.
     *
     * @param array<string,mixed> $match
     * @return array{home_goals:int,away_goals:int,has_data:bool}
     */
    public function extractH2hData(array $match): array
    {
        $homeGoals = $this->getIntOrNull($match, 'h2h_ht_match_goals_1');
        $awayGoals = $this->getIntOrNull($match, 'h2h_ht_match_goals_2');

        $hasData = $homeGoals !== null && $awayGoals !== null;

        return [
            'home_goals' => $homeGoals ?? 0,
            'away_goals' => $awayGoals ?? 0,
            'has_data' => $hasData,
        ];
    }

    /**
     * Extract live statistics from match record (legacy).
     *
     * @param array<string,mixed> $match
     * @return array{
     *   minute:int,
     *   shots_total:int,
     *   shots_on_target:int,
     *   dangerous_attacks:int,
     *   corners:int,
     *   shots_on_target_home:int,
     *   shots_on_target_away:int,
     *   shots_off_target_home:int,
     *   shots_off_target_away:int,
     *   dangerous_attacks_home:int,
     *   dangerous_attacks_away:int,
     *   corners_home:int,
     *   corners_away:int,
     *   xg_home:?float,
     *   xg_away:?float,
     *   yellow_cards_home:?int,
     *   yellow_cards_away:?int,
     *   trend_shots_total_delta:?int,
     *   trend_shots_on_target_delta:?int,
     *   trend_dangerous_attacks_delta:?int,
     *   trend_xg_delta:?float,
     *   trend_window_seconds:?int,
     *   has_trend_data:bool,
     *   ht_hscore:int,
     *   ht_ascore:int,
     *   live_hscore:int,
     *   live_ascore:int,
     *   time_str:string,
     *   match_status:string
     * }
     */
    public function extractLiveData(array $match): array
    {
        $timeStr = (string) ($match['time'] ?? '00:00');
        $minute = $this->parseMinute($timeStr);

        $shotsOnTargetHome = $this->getIntOrZero($match, 'live_shots_on_target_home');
        $shotsOnTargetAway = $this->getIntOrZero($match, 'live_shots_on_target_away');
        $shotsOffTargetHome = $this->getIntOrZero($match, 'live_shots_off_target_home');
        $shotsOffTargetAway = $this->getIntOrZero($match, 'live_shots_off_target_away');

        $shotsTotal = $shotsOnTargetHome + $shotsOnTargetAway + $shotsOffTargetHome + $shotsOffTargetAway;
        $shotsOnTarget = $shotsOnTargetHome + $shotsOnTargetAway;

        $dangerAttHome = $this->getIntOrZero($match, 'live_danger_att_home');
        $dangerAttAway = $this->getIntOrZero($match, 'live_danger_att_away');
        $dangerousAttacks = $dangerAttHome + $dangerAttAway;

        $cornerHome = $this->getIntOrZero($match, 'live_corner_home');
        $cornerAway = $this->getIntOrZero($match, 'live_corner_away');
        $corners = $cornerHome + $cornerAway;

        $htHscore = $this->getIntOrZero($match, 'live_ht_hscore');
        $htAscore = $this->getIntOrZero($match, 'live_ht_ascore');
        $liveHscore = $this->getIntOrZero($match, 'live_hscore');
        $liveAscore = $this->getIntOrZero($match, 'live_ascore');

        return [
            'minute' => $minute,
            'shots_total' => $shotsTotal,
            'shots_on_target' => $shotsOnTarget,
            'dangerous_attacks' => $dangerousAttacks,
            'corners' => $corners,
            'shots_on_target_home' => $shotsOnTargetHome,
            'shots_on_target_away' => $shotsOnTargetAway,
            'shots_off_target_home' => $shotsOffTargetHome,
            'shots_off_target_away' => $shotsOffTargetAway,
            'dangerous_attacks_home' => $dangerAttHome,
            'dangerous_attacks_away' => $dangerAttAway,
            'corners_home' => $cornerHome,
            'corners_away' => $cornerAway,
            'xg_home' => $this->getFloatOrNull($match, 'live_xg_home'),
            'xg_away' => $this->getFloatOrNull($match, 'live_xg_away'),
            'yellow_cards_home' => $this->getIntOrNull($match, 'live_yellow_cards_home'),
            'yellow_cards_away' => $this->getIntOrNull($match, 'live_yellow_cards_away'),
            'trend_shots_total_delta' => $this->getIntOrNull($match, 'live_trend_shots_total_delta'),
            'trend_shots_on_target_delta' => $this->getIntOrNull($match, 'live_trend_shots_on_target_delta'),
            'trend_dangerous_attacks_delta' => $this->getIntOrNull($match, 'live_trend_danger_attacks_delta'),
            'trend_xg_delta' => $this->getFloatOrNull($match, 'live_trend_xg_delta'),
            'trend_window_seconds' => $this->getIntOrNull($match, 'live_trend_window_seconds'),
            'has_trend_data' => $this->getIntOrZero($match, 'live_trend_has_data') === 1,
            'ht_hscore' => $htHscore,
            'ht_ascore' => $htAscore,
            'live_hscore' => $liveHscore,
            'live_ascore' => $liveAscore,
            'time_str' => $timeStr,
            'match_status' => (string) ($match['match_status'] ?? ''),
        ];
    }

    /**
     * Extract live statistics for v2 with table_avg and full payload.
     *
     * @param array<string,mixed> $match
     * @return array{
     *   minute:int,
     *   shots_total:int,
     *   shots_on_target:int,
     *   dangerous_attacks:int,
     *   corners:int,
     *   shots_on_target_home:int,
     *   shots_on_target_away:int,
     *   shots_off_target_home:int,
     *   shots_off_target_away:int,
     *   dangerous_attacks_home:int,
     *   dangerous_attacks_away:int,
     *   corners_home:int,
     *   corners_away:int,
     *   xg_home:?float,
     *   xg_away:?float,
     *   xg_total:float,
     *   yellow_cards_home:int,
     *   yellow_cards_away:int,
     *   trend_shots_total_delta:?int,
     *   trend_shots_on_target_delta:?int,
     *   trend_dangerous_attacks_delta:?int,
     *   trend_xg_delta:?float,
     *   trend_window_seconds:?int,
     *   has_trend_data:bool,
     *   ht_hscore:int,
     *   ht_ascore:int,
     *   live_hscore:int,
     *   live_ascore:int,
     *   time_str:string,
     *   match_status:string,
     *   table_avg:?float
     * }
     */
    public function extractLiveDataV2(array $match): array
    {
        $timeStr = (string) ($match['time'] ?? '00:00');
        $minute = $this->parseMinute($timeStr);

        $shotsOnTargetHome = $this->getIntOrZero($match, 'live_shots_on_target_home');
        $shotsOnTargetAway = $this->getIntOrZero($match, 'live_shots_on_target_away');
        $shotsOffTargetHome = $this->getIntOrZero($match, 'live_shots_off_target_home');
        $shotsOffTargetAway = $this->getIntOrZero($match, 'live_shots_off_target_away');

        $shotsTotal = $shotsOnTargetHome + $shotsOnTargetAway + $shotsOffTargetHome + $shotsOffTargetAway;
        $shotsOnTarget = $shotsOnTargetHome + $shotsOnTargetAway;

        $dangerAttHome = $this->getIntOrZero($match, 'live_danger_att_home');
        $dangerAttAway = $this->getIntOrZero($match, 'live_danger_att_away');
        $dangerousAttacks = $dangerAttHome + $dangerAttAway;

        $cornerHome = $this->getIntOrZero($match, 'live_corner_home');
        $cornerAway = $this->getIntOrZero($match, 'live_corner_away');
        $corners = $cornerHome + $cornerAway;

        $htHscore = $this->getIntOrZero($match, 'live_ht_hscore');
        $htAscore = $this->getIntOrZero($match, 'live_ht_ascore');
        $liveHscore = $this->getIntOrZero($match, 'live_hscore');
        $liveAscore = $this->getIntOrZero($match, 'live_ascore');

        $xgHome = $this->getFloatOrNull($match, 'live_xg_home');
        $xgAway = $this->getFloatOrNull($match, 'live_xg_away');
        $xgTotal = ($xgHome ?? 0.0) + ($xgAway ?? 0.0);

        $yellowCardsHome = $this->getIntOrNull($match, 'live_yellow_cards_home') ?? 0;
        $yellowCardsAway = $this->getIntOrNull($match, 'live_yellow_cards_away') ?? 0;

        $tableAvg = $this->getFloatOrNull($match, 'table_avg');

        return [
            'minute' => $minute,
            'shots_total' => $shotsTotal,
            'shots_on_target' => $shotsOnTarget,
            'dangerous_attacks' => $dangerousAttacks,
            'corners' => $corners,
            'shots_on_target_home' => $shotsOnTargetHome,
            'shots_on_target_away' => $shotsOnTargetAway,
            'shots_off_target_home' => $shotsOffTargetHome,
            'shots_off_target_away' => $shotsOffTargetAway,
            'dangerous_attacks_home' => $dangerAttHome,
            'dangerous_attacks_away' => $dangerAttAway,
            'corners_home' => $cornerHome,
            'corners_away' => $cornerAway,
            'xg_home' => $xgHome,
            'xg_away' => $xgAway,
            'xg_total' => $xgTotal,
            'yellow_cards_home' => $yellowCardsHome,
            'yellow_cards_away' => $yellowCardsAway,
            'trend_shots_total_delta' => $this->getIntOrNull($match, 'live_trend_shots_total_delta'),
            'trend_shots_on_target_delta' => $this->getIntOrNull($match, 'live_trend_shots_on_target_delta'),
            'trend_dangerous_attacks_delta' => $this->getIntOrNull($match, 'live_trend_danger_attacks_delta'),
            'trend_xg_delta' => $this->getFloatOrNull($match, 'live_trend_xg_delta'),
            'trend_window_seconds' => $this->getIntOrNull($match, 'live_trend_window_seconds'),
            'has_trend_data' => $this->getIntOrZero($match, 'live_trend_has_data') === 1,
            'ht_hscore' => $htHscore,
            'ht_ascore' => $htAscore,
            'live_hscore' => $liveHscore,
            'live_ascore' => $liveAscore,
            'time_str' => $timeStr,
            'match_status' => (string) ($match['match_status'] ?? ''),
            'table_avg' => $tableAvg,
        ];
    }

    /**
     * Parse minute from time string "mm:ss".
     */
    private function parseMinute(string $time): int
    {
        $parts = explode(':', $time);
        if (count($parts) >= 1 && is_numeric($parts[0])) {
            return max(0, (int) $parts[0]);
        }
        return 0;
    }

    /**
     * @param array<string,mixed> $data
     */
    private function getIntOrNull(array $data, string $key): ?int
    {
        if (!array_key_exists($key, $data)) {
            return null;
        }

        $val = $data[$key];
        if ($val === null) {
            return null;
        }

        if (is_int($val)) {
            return $val;
        }

        if (is_numeric($val)) {
            return (int) $val;
        }

        return null;
    }

    /**
     * @param array<string,mixed> $data
     */
    private function getIntOrZero(array $data, string $key): int
    {
        return $this->getIntOrNull($data, $key) ?? 0;
    }

    /**
     * @param array<string,mixed> $data
     */
    private function getFloatOrNull(array $data, string $key): ?float
    {
        if (!array_key_exists($key, $data)) {
            return null;
        }

        $val = $data[$key];
        if ($val === null) {
            return null;
        }

        if (is_numeric($val)) {
            return (float) $val;
        }

        return null;
    }
}
