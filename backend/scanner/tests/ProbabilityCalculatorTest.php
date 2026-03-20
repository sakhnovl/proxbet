<?php

declare(strict_types=1);

namespace Proxbet\Scanner\Tests;

use PHPUnit\Framework\TestCase;
use Proxbet\Scanner\ProbabilityCalculator;

final class ProbabilityCalculatorTest extends TestCase
{
    private ProbabilityCalculator $calculator;

    protected function setUp(): void
    {
        $this->calculator = new ProbabilityCalculator();
    }

    public function testCalculateFormScoreReturnsZeroWhenFormIsMissing(): void
    {
        $score = $this->calculator->calculateFormScore([
            'home_goals' => 4,
            'away_goals' => 3,
            'has_data' => false,
        ]);

        $this->assertSame(0.0, $score);
    }

    public function testCalculateLiveScoreReturnsHighestTierForHighActivity(): void
    {
        $score = $this->calculator->calculateLiveScore([
            'minute' => 22,
            'shots_total' => 7,
            'shots_on_target' => 2,
            'dangerous_attacks' => 23,
            'corners' => 4,
            'shots_on_target_home' => 2,
            'shots_on_target_away' => 0,
            'shots_off_target_home' => 3,
            'shots_off_target_away' => 2,
            'dangerous_attacks_home' => 16,
            'dangerous_attacks_away' => 7,
            'corners_home' => 3,
            'corners_away' => 1,
            'xg_home' => 0.72,
            'xg_away' => 0.18,
            'yellow_cards_home' => 1,
            'yellow_cards_away' => 1,
            'trend_shots_total_delta' => 3,
            'trend_shots_on_target_delta' => 1,
            'trend_dangerous_attacks_delta' => 7,
            'trend_xg_delta' => 0.18,
            'trend_window_seconds' => 300,
            'has_trend_data' => true,
            'ht_hscore' => 0,
            'ht_ascore' => 0,
            'live_hscore' => 0,
            'live_ascore' => 0,
            'time_str' => '22:10',
            'match_status' => '1st Half',
        ]);

        $this->assertGreaterThan(0.7, $score);
    }

    public function testCalculateAllUsesWeightedFormula(): void
    {
        $result = $this->calculator->calculateAll(
            [
                'home_goals' => 4,
                'away_goals' => 2,
                'has_data' => true,
            ],
            [
                'home_goals' => 3,
                'away_goals' => 1,
                'has_data' => true,
            ],
            [
                'minute' => 18,
                'shots_total' => 5,
                'shots_on_target' => 2,
                'dangerous_attacks' => 16,
                'corners' => 2,
                'shots_on_target_home' => 2,
                'shots_on_target_away' => 0,
                'shots_off_target_home' => 2,
                'shots_off_target_away' => 1,
                'dangerous_attacks_home' => 11,
                'dangerous_attacks_away' => 5,
                'corners_home' => 2,
                'corners_away' => 0,
                'xg_home' => 0.48,
                'xg_away' => 0.10,
                'yellow_cards_home' => null,
                'yellow_cards_away' => null,
                'trend_shots_total_delta' => 2,
                'trend_shots_on_target_delta' => 1,
                'trend_dangerous_attacks_delta' => 5,
                'trend_xg_delta' => 0.12,
                'trend_window_seconds' => 240,
                'has_trend_data' => true,
                'ht_hscore' => 0,
                'ht_ascore' => 0,
                'live_hscore' => 0,
                'live_ascore' => 0,
                'time_str' => '18:00',
                'match_status' => '1st Half',
            ]
        );

        $this->assertSame(0.6, $result['form_score']);
        $this->assertSame(0.4, $result['h2h_score']);
        $this->assertGreaterThan(0.5, $result['live_score']);
        $this->assertEqualsWithDelta(0.5576, $result['probability'], 0.02);
    }

    public function testCalculateLiveScoreStillWorksWhenXgIsMissing(): void
    {
        $score = $this->calculator->calculateLiveScore([
            'minute' => 24,
            'shots_total' => 6,
            'shots_on_target' => 3,
            'dangerous_attacks' => 21,
            'corners' => 3,
            'shots_on_target_home' => 3,
            'shots_on_target_away' => 0,
            'shots_off_target_home' => 2,
            'shots_off_target_away' => 1,
            'dangerous_attacks_home' => 15,
            'dangerous_attacks_away' => 6,
            'corners_home' => 2,
            'corners_away' => 1,
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
            'time_str' => '24:00',
            'match_status' => '1st Half',
        ]);

        $this->assertGreaterThan(0.55, $score);
    }

    public function testCalculateLiveScoreBenefitsFromPositiveTrend(): void
    {
        $base = [
            'minute' => 26,
            'shots_total' => 6,
            'shots_on_target' => 2,
            'dangerous_attacks' => 20,
            'corners' => 2,
            'shots_on_target_home' => 2,
            'shots_on_target_away' => 0,
            'shots_off_target_home' => 2,
            'shots_off_target_away' => 2,
            'dangerous_attacks_home' => 13,
            'dangerous_attacks_away' => 7,
            'corners_home' => 2,
            'corners_away' => 0,
            'xg_home' => 0.35,
            'xg_away' => 0.08,
            'yellow_cards_home' => null,
            'yellow_cards_away' => null,
            'ht_hscore' => 0,
            'ht_ascore' => 0,
            'live_hscore' => 0,
            'live_ascore' => 0,
            'time_str' => '26:00',
            'match_status' => '1st Half',
        ];

        $withoutTrend = $this->calculator->calculateLiveScore($base + [
            'trend_shots_total_delta' => null,
            'trend_shots_on_target_delta' => null,
            'trend_dangerous_attacks_delta' => null,
            'trend_xg_delta' => null,
            'trend_window_seconds' => null,
            'has_trend_data' => false,
        ]);
        $withTrend = $this->calculator->calculateLiveScore($base + [
            'trend_shots_total_delta' => 3,
            'trend_shots_on_target_delta' => 1,
            'trend_dangerous_attacks_delta' => 8,
            'trend_xg_delta' => 0.15,
            'trend_window_seconds' => 300,
            'has_trend_data' => true,
        ]);

        $this->assertGreaterThan($withoutTrend, $withTrend);
    }
}
