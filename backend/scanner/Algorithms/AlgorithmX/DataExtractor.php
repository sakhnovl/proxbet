<?php

declare(strict_types=1);

namespace Proxbet\Scanner\Algorithms\AlgorithmX;

/**
 * Extracts live data from match record for AlgorithmX.
 * 
 * Responsible for extracting and structuring live statistics needed
 * for goal probability calculation.
 */
final class DataExtractor
{
    /**
     * Extract live data from match record.
     * 
     * @param array<string,mixed> $match Match record from database
     * @return array{
     *   minute: int,
     *   score_home: int,
     *   score_away: int,
     *   dangerous_attacks_home: int,
     *   dangerous_attacks_away: int,
     *   shots_home: int,
     *   shots_away: int,
     *   shots_on_target_home: int,
     *   shots_on_target_away: int,
     *   corners_home: int,
     *   corners_away: int,
     *   match_status: string,
     *   has_data: bool
     * }
     */
    public function extract(array $match): array
    {
        $timeStr = (string) ($match['time'] ?? '00:00');
        $minute = $this->parseMinute($timeStr);

        $scoreHome = $this->getIntOrZero($match, 'live_ht_hscore');
        $scoreAway = $this->getIntOrZero($match, 'live_ht_ascore');

        $dangerousAttacksHome = $this->getIntOrZero($match, 'live_danger_att_home');
        $dangerousAttacksAway = $this->getIntOrZero($match, 'live_danger_att_away');

        $shotsOnTargetHome = $this->getIntOrZero($match, 'live_shots_on_target_home');
        $shotsOnTargetAway = $this->getIntOrZero($match, 'live_shots_on_target_away');
        $shotsOffTargetHome = $this->getIntOrZero($match, 'live_shots_off_target_home');
        $shotsOffTargetAway = $this->getIntOrZero($match, 'live_shots_off_target_away');

        $shotsHome = $shotsOnTargetHome + $shotsOffTargetHome;
        $shotsAway = $shotsOnTargetAway + $shotsOffTargetAway;

        $cornersHome = $this->getIntOrZero($match, 'live_corner_home');
        $cornersAway = $this->getIntOrZero($match, 'live_corner_away');

        $matchStatus = (string) ($match['match_status'] ?? '');

        $hasData = $minute > 0 && $this->hasRequiredStatistics($match);

        return [
            'minute' => $minute,
            'score_home' => $scoreHome,
            'score_away' => $scoreAway,
            'dangerous_attacks_home' => $dangerousAttacksHome,
            'dangerous_attacks_away' => $dangerousAttacksAway,
            'shots_home' => $shotsHome,
            'shots_away' => $shotsAway,
            'shots_on_target_home' => $shotsOnTargetHome,
            'shots_on_target_away' => $shotsOnTargetAway,
            'corners_home' => $cornersHome,
            'corners_away' => $cornersAway,
            'match_status' => $matchStatus,
            'has_data' => $hasData,
        ];
    }

    /**
     * Parse minute from time string "mm:ss".
     */
    private function parseMinute(string $time): int
    {
        $parts = explode(':', $time);
        if ($parts !== [] && is_numeric($parts[0])) {
            return max(0, (int) $parts[0]);
        }
        return 0;
    }

    /**
     * Get integer value or zero if not present/invalid.
     * 
     * @param array<string,mixed> $data
     */
    private function getIntOrZero(array $data, string $key): int
    {
        if (!array_key_exists($key, $data)) {
            return 0;
        }

        $val = $data[$key];
        if ($val === null) {
            return 0;
        }

        if (is_int($val)) {
            return $val;
        }

        if (is_numeric($val)) {
            return (int) $val;
        }

        return 0;
    }

    /**
     * @param array<string,mixed> $match
     */
    private function hasRequiredStatistics(array $match): bool
    {
        $requiredKeys = [
            'live_ht_hscore',
            'live_ht_ascore',
            'live_danger_att_home',
            'live_danger_att_away',
            'live_shots_on_target_home',
            'live_shots_on_target_away',
            'live_shots_off_target_home',
            'live_shots_off_target_away',
            'live_corner_home',
            'live_corner_away',
            'match_status',
        ];

        foreach ($requiredKeys as $key) {
            if (!array_key_exists($key, $match)) {
                return false;
            }
        }

        return true;
    }
}
