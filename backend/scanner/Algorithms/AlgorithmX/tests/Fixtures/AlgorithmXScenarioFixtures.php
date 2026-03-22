<?php

declare(strict_types=1);

namespace Proxbet\Scanner\Algorithms\AlgorithmX\Tests\Fixtures;

final class AlgorithmXScenarioFixtures
{
    /**
     * @return array<string,mixed>
     */
    public static function highActivity(): array
    {
        return [
            'time' => '18:00',
            'live_ht_hscore' => 0,
            'live_ht_ascore' => 0,
            'live_danger_att_home' => 40,
            'live_danger_att_away' => 35,
            'live_shots_on_target_home' => 10,
            'live_shots_on_target_away' => 8,
            'live_shots_off_target_home' => 12,
            'live_shots_off_target_away' => 10,
            'live_corner_home' => 7,
            'live_corner_away' => 6,
            'match_status' => 'In Play',
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public static function lowActivity(): array
    {
        return [
            'time' => '30:00',
            'live_ht_hscore' => 0,
            'live_ht_ascore' => 0,
            'live_danger_att_home' => 5,
            'live_danger_att_away' => 4,
            'live_shots_on_target_home' => 1,
            'live_shots_on_target_away' => 1,
            'live_shots_off_target_home' => 2,
            'live_shots_off_target_away' => 1,
            'live_corner_home' => 1,
            'live_corner_away' => 1,
            'match_status' => 'In Play',
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public static function dryPeriod(): array
    {
        return [
            'time' => '35:00',
            'live_ht_hscore' => 0,
            'live_ht_ascore' => 0,
            'live_danger_att_home' => 20,
            'live_danger_att_away' => 15,
            'live_shots_on_target_home' => 3,
            'live_shots_on_target_away' => 2,
            'live_shots_off_target_home' => 5,
            'live_shots_off_target_away' => 4,
            'live_corner_home' => 4,
            'live_corner_away' => 2,
            'match_status' => 'In Play',
        ];
    }
}
