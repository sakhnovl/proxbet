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
     * Extract live statistics from match record.
     *
     * @param array<string,mixed> $match
     * @return array{minute:int,shots_total:int,shots_on_target:int,dangerous_attacks:int,corners:int,ht_hscore:int,ht_ascore:int,time_str:string}
     */
    public function extractLiveData(array $match): array
    {
        $timeStr = (string) ($match['time'] ?? '00:00');
        $minute = $this->parseMinute($timeStr);

        $shotsOnTargetHome = $this->getFloatOrZero($match, 'live_shots_on_target_home');
        $shotsOnTargetAway = $this->getFloatOrZero($match, 'live_shots_on_target_away');
        $shotsOffTargetHome = $this->getFloatOrZero($match, 'live_shots_off_target_home');
        $shotsOffTargetAway = $this->getFloatOrZero($match, 'live_shots_off_target_away');

        $shotsTotal = (int) ($shotsOnTargetHome + $shotsOnTargetAway + $shotsOffTargetHome + $shotsOffTargetAway);
        $shotsOnTarget = (int) ($shotsOnTargetHome + $shotsOnTargetAway);

        $dangerAttHome = $this->getFloatOrZero($match, 'live_danger_att_home');
        $dangerAttAway = $this->getFloatOrZero($match, 'live_danger_att_away');
        $dangerousAttacks = (int) ($dangerAttHome + $dangerAttAway);

        $cornerHome = $this->getFloatOrZero($match, 'live_corner_home');
        $cornerAway = $this->getFloatOrZero($match, 'live_corner_away');
        $corners = (int) ($cornerHome + $cornerAway);

        $htHscore = $this->getIntOrZero($match, 'live_ht_hscore');
        $htAscore = $this->getIntOrZero($match, 'live_ht_ascore');

        return [
            'minute' => $minute,
            'shots_total' => $shotsTotal,
            'shots_on_target' => $shotsOnTarget,
            'dangerous_attacks' => $dangerousAttacks,
            'corners' => $corners,
            'ht_hscore' => $htHscore,
            'ht_ascore' => $htAscore,
            'time_str' => $timeStr,
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
        if (!array_key_exists($key, $data)) {
            return 0.0;
        }

        $val = $data[$key];
        if ($val === null) {
            return 0.0;
        }

        if (is_numeric($val)) {
            return (float) $val;
        }

        return 0.0;
    }
}
