<?php

declare(strict_types=1);

namespace Proxbet\Scanner;

use PDO;

/**
 * Extracts and structures data from database for scanner analysis.
 */
final class DataExtractor
{
    public function __construct(private PDO $db)
    {
    }

    /**
     * Get all active live matches.
     *
     * @return array<int,array<string,mixed>>
     */
    public function getActiveMatches(): array
    {
        $sql = 'SELECT * FROM `matches` 
                WHERE `time` IS NOT NULL 
                AND `time` != "" 
                AND `match_status` IS NOT NULL
                ORDER BY `id` ASC';

        $stmt = $this->db->query($sql);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return is_array($rows) ? $rows : [];
    }

    /**
     * Extract form data from match record.
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
     * Extract data for algorithm 2.
     *
     * @param array<string,mixed> $match
     * @return array{
     *   home_win_odd:float,
     *   over_25_odd:float|null,
     *   total_line:float|null,
     *   over_25_odd_check_skipped:bool,
     *   home_first_half_goals_in_last_5:int,
     *   h2h_first_half_goals_in_last_5:int,
     *   has_data:bool
     * }
     */
    public function extractAlgorithmTwoData(array $match): array
    {
        $homeWinOdd = $this->getFloatOrNull($match, 'home_cf');
        $over25Odd = null;
        $over25OddCheckSkipped = false;

        $totalLine = $this->getFloatOrNull($match, 'total_line');
        if ($totalLine !== null && abs($totalLine - 2.5) < 0.001) {
            $over25Odd = $this->getFloatOrNull($match, 'total_line_tb');
        } elseif ($totalLine !== null && $totalLine > 2.5) {
            $over25OddCheckSkipped = true;
        }

        $homeFirstHalfGoals = $this->getIntOrNull($match, 'ht_match_goals_1');
        $h2hFirstHalfGoals = $this->extractH2hAnyFirstHalfGoalMatches($match);

        $hasData = $homeWinOdd !== null
            && ($over25Odd !== null || $over25OddCheckSkipped)
            && $homeFirstHalfGoals !== null
            && $h2hFirstHalfGoals !== null;

        return [
            'home_win_odd' => $homeWinOdd ?? 0.0,
            'over_25_odd' => $over25Odd,
            'total_line' => $totalLine,
            'over_25_odd_check_skipped' => $over25OddCheckSkipped,
            'home_first_half_goals_in_last_5' => $homeFirstHalfGoals ?? 0,
            'h2h_first_half_goals_in_last_5' => $h2hFirstHalfGoals ?? 0,
            'has_data' => $hasData,
        ];
    }

    /**
     * Extract live statistics from match record.
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
     * Extract data for algorithm 3.
     *
     * @param array<string,mixed> $match
     * @return array{
     *   table_games_1:int,
     *   table_goals_1:int,
     *   table_missed_1:int,
     *   table_games_2:int,
     *   table_goals_2:int,
     *   table_missed_2:int,
     *   live_hscore:int,
     *   live_ascore:int,
     *   match_status:string,
     *   home:string,
     *   away:string,
     *   has_data:bool
     * }
     */
    public function extractAlgorithmThreeData(array $match): array
    {
        $tableGamesOne = $this->getIntOrNull($match, 'table_games_1');
        $tableGoalsOne = $this->getIntOrNull($match, 'table_goals_1');
        $tableMissedOne = $this->getIntOrNull($match, 'table_missed_1');
        $tableGamesTwo = $this->getIntOrNull($match, 'table_games_2');
        $tableGoalsTwo = $this->getIntOrNull($match, 'table_goals_2');
        $tableMissedTwo = $this->getIntOrNull($match, 'table_missed_2');

        $hasData = $tableGamesOne !== null
            && $tableGoalsOne !== null
            && $tableMissedOne !== null
            && $tableGamesTwo !== null
            && $tableGoalsTwo !== null
            && $tableMissedTwo !== null;

        return [
            'table_games_1' => $tableGamesOne ?? 0,
            'table_goals_1' => $tableGoalsOne ?? 0,
            'table_missed_1' => $tableMissedOne ?? 0,
            'table_games_2' => $tableGamesTwo ?? 0,
            'table_goals_2' => $tableGoalsTwo ?? 0,
            'table_missed_2' => $tableMissedTwo ?? 0,
            'live_hscore' => $this->getIntOrZero($match, 'live_hscore'),
            'live_ascore' => $this->getIntOrZero($match, 'live_ascore'),
            'match_status' => (string) ($match['match_status'] ?? ''),
            'home' => (string) ($match['home'] ?? ''),
            'away' => (string) ($match['away'] ?? ''),
            'has_data' => $hasData,
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
    private function getFloatOrZero(array $data, string $key): float
    {
        return $this->getFloatOrNull($data, $key) ?? 0.0;
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

    /**
     * Count H2H matches from the last 5 where any team scored in the first half.
     *
     * @param array<string,mixed> $match
     */
    private function extractH2hAnyFirstHalfGoalMatches(array $match): ?int
    {
        $sgiJson = $match['sgi_json'] ?? null;
        if (!is_string($sgiJson) || trim($sgiJson) === '') {
            return null;
        }

        $decoded = json_decode($sgiJson, true);
        if (!is_array($decoded)) {
            return null;
        }

        $h2hList = $decoded['G'] ?? (($decoded['Q']['G'] ?? null));
        if (!is_array($h2hList)) {
            return null;
        }

        $count = 0;
        $considered = 0;

        foreach (array_slice(array_values($h2hList), 0, 5) as $h2hMatch) {
            if (!is_array($h2hMatch)) {
                continue;
            }

            $firstHalf = $h2hMatch['P'][0] ?? null;
            if (!is_array($firstHalf)) {
                continue;
            }

            $homeGoals = $firstHalf['H'] ?? null;
            $awayGoals = $firstHalf['A'] ?? null;
            if (!is_numeric($homeGoals) || !is_numeric($awayGoals)) {
                continue;
            }

            $considered++;
            if (((int) $homeGoals + (int) $awayGoals) > 0) {
                $count++;
            }
        }

        if ($considered === 0) {
            return null;
        }

        return $count;
    }
}
