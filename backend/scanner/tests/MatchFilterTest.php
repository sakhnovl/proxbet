<?php

declare(strict_types=1);

namespace Proxbet\Scanner\Tests;

use PHPUnit\Framework\TestCase;
use Proxbet\Scanner\MatchFilter;

final class MatchFilterTest extends TestCase
{
    public function testAlgorithmOneApprovesMatchWhenCoreSignalsAreStrong(): void
    {
        $filter = new MatchFilter();

        $decision = $filter->shouldBetAlgorithmOne(
            $this->buildLiveData(minute: 21, shotsOnTarget: 3, dangerousAttacks: 24),
            0.74,
            ['home_goals' => 4, 'away_goals' => 3, 'has_data' => true],
            ['home_goals' => 2, 'away_goals' => 2, 'has_data' => true]
        );

        $this->assertTrue($decision['bet']);
    }

    public function testAlgorithmOneRejectsMatchWhenProbabilityIsBelowThreshold(): void
    {
        $filter = new MatchFilter();

        $decision = $filter->shouldBetAlgorithmOne(
            $this->buildLiveData(minute: 20, shotsOnTarget: 2, dangerousAttacks: 22),
            0.64,
            ['home_goals' => 4, 'away_goals' => 3, 'has_data' => true],
            ['home_goals' => 2, 'away_goals' => 2, 'has_data' => true]
        );

        $this->assertFalse($decision['bet']);
        $this->assertStringContainsString('РІРµСЂРѕСЏС‚РЅРѕСЃС‚СЊ', $decision['reason']);
    }

    public function testAlgorithmTwoSkipsOver25OddCheckWhenTotalLineIsAboveTwoPointFive(): void
    {
        $filter = new MatchFilter();

        $decision = $filter->shouldBetAlgorithmTwo(
            $this->buildLiveData(minute: 18),
            [
                'home_win_odd' => 1.40,
                'over_25_odd' => null,
                'total_line' => 3.0,
                'over_25_odd_check_skipped' => true,
                'home_first_half_goals_in_last_5' => 4,
                'h2h_first_half_goals_in_last_5' => 3,
                'has_data' => true,
            ]
        );

        $this->assertTrue($decision['bet']);
    }

    public function testAlgorithmTwoRejectsWhenHomeOddIsTooHigh(): void
    {
        $filter = new MatchFilter();

        $decision = $filter->shouldBetAlgorithmTwo(
            $this->buildLiveData(minute: 19),
            [
                'home_win_odd' => 1.65,
                'over_25_odd' => 1.40,
                'total_line' => 2.5,
                'over_25_odd_check_skipped' => false,
                'home_first_half_goals_in_last_5' => 4,
                'h2h_first_half_goals_in_last_5' => 4,
                'has_data' => true,
            ]
        );

        $this->assertFalse($decision['bet']);
    }

    public function testAlgorithmThreeSelectsHomeTeamDuringHalfTimeWhenRatiosMatch(): void
    {
        $filter = new MatchFilter();

        $decision = $filter->shouldBetAlgorithmThree([
            'table_games_1' => 12,
            'table_goals_1' => 40,
            'table_missed_1' => 12,
            'table_games_2' => 12,
            'table_goals_2' => 14,
            'table_missed_2' => 38,
            'live_hscore' => 0,
            'live_ascore' => 0,
            'match_status' => 'РџРµСЂРµСЂС‹РІ',
            'home' => 'Home FC',
            'away' => 'Away FC',
            'has_data' => true,
        ]);

        $this->assertTrue($decision['bet']);
        $this->assertSame('home', $decision['selected_team_side']);
        $this->assertSame('Home FC', $decision['selected_team_name']);
        $this->assertSame('team_1_attack_vs_team_2_missed', $decision['triggered_rule']);
    }

    public function testAlgorithmThreeRejectsWhenSelectedTeamAlreadyScored(): void
    {
        $filter = new MatchFilter();

        $decision = $filter->shouldBetAlgorithmThree([
            'table_games_1' => 12,
            'table_goals_1' => 40,
            'table_missed_1' => 12,
            'table_games_2' => 12,
            'table_goals_2' => 14,
            'table_missed_2' => 38,
            'live_hscore' => 1,
            'live_ascore' => 0,
            'match_status' => 'РџРµСЂРµСЂС‹РІ',
            'home' => 'Home FC',
            'away' => 'Away FC',
            'has_data' => true,
        ]);

        $this->assertFalse($decision['bet']);
        $this->assertSame('home', $decision['selected_team_side']);
    }

    /**
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
    private function buildLiveData(
        int $minute,
        int $shotsOnTarget = 2,
        int $dangerousAttacks = 20
    ): array {
        return [
            'minute' => $minute,
            'shots_total' => 6,
            'shots_on_target' => $shotsOnTarget,
            'dangerous_attacks' => $dangerousAttacks,
            'corners' => 3,
            'shots_on_target_home' => $shotsOnTarget,
            'shots_on_target_away' => 0,
            'shots_off_target_home' => 2,
            'shots_off_target_away' => 1,
            'dangerous_attacks_home' => $dangerousAttacks,
            'dangerous_attacks_away' => 0,
            'corners_home' => 3,
            'corners_away' => 0,
            'xg_home' => null,
            'xg_away' => null,
            'yellow_cards_home' => null,
            'yellow_cards_away' => null,
            'trend_shots_total_delta' => null,
            'trend_shots_on_target_delta' => null,
            'trend_dangerous_attacks_delta' => null,
            'trend_xg_delta' => null,
            'trend_window_seconds' => null,
            'has_trend_data' => false,
            'ht_hscore' => 0,
            'ht_ascore' => 0,
            'live_hscore' => 0,
            'live_ascore' => 0,
            'time_str' => sprintf('%02d:00', $minute),
            'match_status' => '1st Half',
        ];
    }
}
