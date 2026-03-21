<?php

declare(strict_types=1);

namespace Proxbet\Scanner\Tests;

use PHPUnit\Framework\TestCase;
use Proxbet\Scanner\ProbabilityCalculator;

final class ProbabilityCalculatorV2Test extends TestCase
{
    private ProbabilityCalculator $calculator;

    protected function setUp(): void
    {
        $this->calculator = new ProbabilityCalculator();
        $this->calculator->setAlgorithmVersion(2);
    }

    public function testCalculateV2ReturnsCorrectStructure(): void
    {
        $result = $this->calculator->calculateAll(
            [
                'home_goals' => 4,
                'away_goals' => 3,
                'has_data' => true,
            ],
            [
                'home_goals' => 2,
                'away_goals' => 2,
                'has_data' => true,
            ],
            [
                'minute' => 25,
                'shots_total' => 8,
                'shots_on_target' => 4,
                'dangerous_attacks' => 33,
                'dangerous_attacks_home' => 18,
                'dangerous_attacks_away' => 15,
                'shots_on_target_home' => 2,
                'shots_on_target_away' => 2,
                'shots_off_target_home' => 2,
                'shots_off_target_away' => 2,
                'corners' => 4,
                'corners_home' => 2,
                'corners_away' => 2,
                'xg_home' => 0.8,
                'xg_away' => 0.6,
                'xg_total' => 1.4,
                'yellow_cards_home' => 1,
                'yellow_cards_away' => 2,
                'trend_shots_total_delta' => 4,
                'trend_shots_on_target_delta' => 2,
                'trend_dangerous_attacks_delta' => 8,
                'trend_xg_delta' => 0.3,
                'trend_window_seconds' => 300,
                'has_trend_data' => true,
                'ht_hscore' => 0,
                'ht_ascore' => 0,
                'live_hscore' => 0,
                'live_ascore' => 0,
                'time_str' => '25:00',
                'match_status' => '1st Half',
                'table_avg' => 2.8,
            ]
        );

        $this->assertSame(2, $result['algorithm_version']);
        $this->assertArrayHasKey('components', $result);
        $this->assertArrayHasKey('decision', $result);
        $this->assertArrayHasKey('form_score', $result);
        $this->assertArrayHasKey('h2h_score', $result);
        $this->assertArrayHasKey('live_score', $result);
        $this->assertArrayHasKey('probability', $result);
    }

    public function testPdiCalculationForBalancedGame(): void
    {
        $result = $this->calculator->calculateAll(
            ['home_goals' => 3, 'away_goals' => 3, 'has_data' => true],
            ['home_goals' => 2, 'away_goals' => 2, 'has_data' => true],
            [
                'minute' => 25,
                'shots_total' => 6,
                'shots_on_target' => 3,
                'dangerous_attacks' => 40,
                'dangerous_attacks_home' => 20,
                'dangerous_attacks_away' => 20,
                'shots_on_target_home' => 2,
                'shots_on_target_away' => 1,
                'shots_off_target_home' => 2,
                'shots_off_target_away' => 1,
                'corners' => 3,
                'corners_home' => 2,
                'corners_away' => 1,
                'xg_home' => 0.5,
                'xg_away' => 0.5,
                'xg_total' => 1.0,
                'yellow_cards_home' => 0,
                'yellow_cards_away' => 0,
                'trend_shots_total_delta' => 2,
                'trend_shots_on_target_delta' => 1,
                'trend_dangerous_attacks_delta' => 5,
                'trend_xg_delta' => 0.2,
                'trend_window_seconds' => 300,
                'has_trend_data' => true,
                'ht_hscore' => 0,
                'ht_ascore' => 0,
                'live_hscore' => 0,
                'live_ascore' => 0,
                'time_str' => '25:00',
                'match_status' => '1st Half',
                'table_avg' => 2.5,
            ]
        );

        // Balanced game with 40 total dangerous attacks should have high PDI
        $this->assertGreaterThan(0.8, $result['components']['pdi']);
    }

    public function testRedFlagLowAccuracyBlocksBet(): void
    {
        $result = $this->calculator->calculateAll(
            ['home_goals' => 4, 'away_goals' => 3, 'has_data' => true],
            ['home_goals' => 2, 'away_goals' => 2, 'has_data' => true],
            [
                'minute' => 25,
                'shots_total' => 20,
                'shots_on_target' => 2,
                'dangerous_attacks' => 35,
                'dangerous_attacks_home' => 20,
                'dangerous_attacks_away' => 15,
                'shots_on_target_home' => 1,
                'shots_on_target_away' => 1,
                'shots_off_target_home' => 10,
                'shots_off_target_away' => 8,
                'corners' => 5,
                'corners_home' => 3,
                'corners_away' => 2,
                'xg_home' => 0.3,
                'xg_away' => 0.2,
                'xg_total' => 0.5,
                'yellow_cards_home' => 1,
                'yellow_cards_away' => 1,
                'trend_shots_total_delta' => 5,
                'trend_shots_on_target_delta' => 1,
                'trend_dangerous_attacks_delta' => 8,
                'trend_xg_delta' => 0.15,
                'trend_window_seconds' => 300,
                'has_trend_data' => true,
                'ht_hscore' => 0,
                'ht_ascore' => 0,
                'live_hscore' => 0,
                'live_ascore' => 0,
                'time_str' => '25:00',
                'match_status' => '1st Half',
                'table_avg' => 2.5,
            ]
        );

        $this->assertSame('low_accuracy', $result['components']['red_flag']);
        $this->assertFalse($result['decision']['bet']);
        $this->assertSame('red_flag_low_accuracy', $result['decision']['reason']);
    }

    public function testRedFlagIneffectivePressureBlocksBet(): void
    {
        $result = $this->calculator->calculateAll(
            ['home_goals' => 4, 'away_goals' => 3, 'has_data' => true],
            ['home_goals' => 2, 'away_goals' => 2, 'has_data' => true],
            [
                'minute' => 25,
                'shots_total' => 5,
                'shots_on_target' => 2,
                'dangerous_attacks' => 35,
                'dangerous_attacks_home' => 32,
                'dangerous_attacks_away' => 3,
                'shots_on_target_home' => 1,
                'shots_on_target_away' => 1,
                'shots_off_target_home' => 2,
                'shots_off_target_away' => 1,
                'corners' => 4,
                'corners_home' => 3,
                'corners_away' => 1,
                'xg_home' => 0.4,
                'xg_away' => 0.1,
                'xg_total' => 0.5,
                'yellow_cards_home' => 1,
                'yellow_cards_away' => 1,
                'trend_shots_total_delta' => 2,
                'trend_shots_on_target_delta' => 1,
                'trend_dangerous_attacks_delta' => 8,
                'trend_xg_delta' => 0.15,
                'trend_window_seconds' => 300,
                'has_trend_data' => true,
                'ht_hscore' => 0,
                'ht_ascore' => 0,
                'live_hscore' => 0,
                'live_ascore' => 0,
                'time_str' => '25:00',
                'match_status' => '1st Half',
                'table_avg' => 2.5,
            ]
        );

        $this->assertSame('ineffective_pressure', $result['components']['red_flag']);
        $this->assertFalse($result['decision']['bet']);
        $this->assertSame('red_flag_ineffective_pressure', $result['decision']['reason']);
    }

    public function testTimePressureIncreasesWithMinute(): void
    {
        $result15 = $this->calculator->calculateAll(
            ['home_goals' => 3, 'away_goals' => 3, 'has_data' => true],
            ['home_goals' => 2, 'away_goals' => 2, 'has_data' => true],
            $this->buildLiveData(15)
        );

        $result25 = $this->calculator->calculateAll(
            ['home_goals' => 3, 'away_goals' => 3, 'has_data' => true],
            ['home_goals' => 2, 'away_goals' => 2, 'has_data' => true],
            $this->buildLiveData(25)
        );

        $result30 = $this->calculator->calculateAll(
            ['home_goals' => 3, 'away_goals' => 3, 'has_data' => true],
            ['home_goals' => 2, 'away_goals' => 2, 'has_data' => true],
            $this->buildLiveData(30)
        );

        // Time pressure should increase from minute 15 to 30
        $this->assertLessThan($result30['components']['time_pressure'], $result25['components']['time_pressure']);
        $this->assertLessThan($result25['components']['time_pressure'], $result15['components']['time_pressure']);
        $this->assertSame(0.0, $result15['components']['time_pressure']);
        $this->assertSame(1.0, $result30['components']['time_pressure']);
    }

    public function testProbabilityStaysWithinBounds(): void
    {
        $result = $this->calculator->calculateAll(
            ['home_goals' => 5, 'away_goals' => 5, 'has_data' => true],
            ['home_goals' => 5, 'away_goals' => 5, 'has_data' => true],
            [
                'minute' => 28,
                'shots_total' => 15,
                'shots_on_target' => 8,
                'dangerous_attacks' => 50,
                'dangerous_attacks_home' => 25,
                'dangerous_attacks_away' => 25,
                'shots_on_target_home' => 4,
                'shots_on_target_away' => 4,
                'shots_off_target_home' => 4,
                'shots_off_target_away' => 3,
                'corners' => 8,
                'corners_home' => 4,
                'corners_away' => 4,
                'xg_home' => 1.5,
                'xg_away' => 1.5,
                'xg_total' => 3.0,
                'yellow_cards_home' => 2,
                'yellow_cards_away' => 2,
                'trend_shots_total_delta' => 6,
                'trend_shots_on_target_delta' => 3,
                'trend_dangerous_attacks_delta' => 15,
                'trend_xg_delta' => 0.5,
                'trend_window_seconds' => 300,
                'has_trend_data' => true,
                'ht_hscore' => 0,
                'ht_ascore' => 0,
                'live_hscore' => 0,
                'live_ascore' => 0,
                'time_str' => '28:00',
                'match_status' => '1st Half',
                'table_avg' => 3.5,
            ]
        );

        $this->assertGreaterThanOrEqual(0.0, $result['probability']);
        $this->assertLessThanOrEqual(1.0, $result['probability']);
    }

    private function buildLiveData(int $minute): array
    {
        return [
            'minute' => $minute,
            'shots_total' => 8,
            'shots_on_target' => 4,
            'dangerous_attacks' => 30,
            'dangerous_attacks_home' => 16,
            'dangerous_attacks_away' => 14,
            'shots_on_target_home' => 2,
            'shots_on_target_away' => 2,
            'shots_off_target_home' => 2,
            'shots_off_target_away' => 2,
            'corners' => 4,
            'corners_home' => 2,
            'corners_away' => 2,
            'xg_home' => 0.6,
            'xg_away' => 0.5,
            'xg_total' => 1.1,
            'yellow_cards_home' => 1,
            'yellow_cards_away' => 1,
            'trend_shots_total_delta' => 3,
            'trend_shots_on_target_delta' => 2,
            'trend_dangerous_attacks_delta' => 7,
            'trend_xg_delta' => 0.25,
            'trend_window_seconds' => 300,
            'has_trend_data' => true,
            'ht_hscore' => 0,
            'ht_ascore' => 0,
            'live_hscore' => 0,
            'live_ascore' => 0,
            'time_str' => sprintf('%02d:00', $minute),
            'match_status' => '1st Half',
            'table_avg' => 2.5,
        ];
    }
}
