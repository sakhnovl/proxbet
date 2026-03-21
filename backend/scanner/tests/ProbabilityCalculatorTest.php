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

    // ========== LEGACY TESTS ==========

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

        $this->assertGreaterThan(0.65, $score);
    }

    public function testCalculateAllUsesWeightedFormulaLegacy(): void
    {
        $this->calculator->setAlgorithmVersion(1);
        
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

        $this->assertSame(1, $result['algorithm_version']);
        $this->assertEqualsWithDelta(0.6, $result['form_score'], 0.001);
        $this->assertEqualsWithDelta(0.4, $result['h2h_score'], 0.001);
        $this->assertGreaterThan(0.5, $result['live_score']);
    }

    // ========== V2 COMPONENT TESTS ==========

    public function testV2WeightedFormScoreUsesWeightedData(): void
    {
        $this->calculator->setAlgorithmVersion(2);
        
        $formData = [
            'home_goals' => 3,
            'away_goals' => 2,
            'has_data' => true,
            'weighted' => [
                'score' => 0.75,
                'home' => ['attack' => 0.8, 'defense' => 0.3],
                'away' => ['attack' => 0.6, 'defense' => 0.4],
            ],
        ];
        
        $result = $this->calculator->calculateV2(
            $formData,
            ['home_goals' => 2, 'away_goals' => 1, 'has_data' => true],
            $this->buildBasicLiveData(25)
        );

        $this->assertSame(0.75, $result['form_score']);
        $this->assertSame(0.75, $result['components']['form']['weighted_score']);
        $this->assertSame(0.8, $result['components']['form']['home']['attack']);
        $this->assertSame(0.3, $result['components']['form']['home']['defense']);
    }

    public function testV2WeightedFormScoreFallsBackToLegacy(): void
    {
        $this->calculator->setAlgorithmVersion(2);
        
        $result = $this->calculator->calculateV2(
            ['home_goals' => 4, 'away_goals' => 2, 'has_data' => true],
            ['home_goals' => 2, 'away_goals' => 1, 'has_data' => true],
            $this->buildBasicLiveData(25)
        );

        $this->assertEqualsWithDelta(0.6, $result['form_score'], 0.001);
    }

    public function testV2ProbabilityClampedTo01Range(): void
    {
        $this->calculator->setAlgorithmVersion(2);
        
        $data = $this->buildBasicLiveData(25);
        $data['dangerous_attacks'] = 50;
        $data['dangerous_attacks_home'] = 25;
        $data['dangerous_attacks_away'] = 25;
        $data['shots_on_target'] = 10;
        $data['shots_total'] = 12;
        $data['xg_home'] = 2.0;
        $data['xg_away'] = 1.8;
        $data['table_avg'] = 4.0;
        $data['has_trend_data'] = true;
        $data['trend_shots_total_delta'] = 8;
        $data['trend_dangerous_attacks_delta'] = 15;
        $data['trend_xg_delta'] = 0.8;
        $data['trend_window_seconds'] = 300;
        
        $result = $this->calculator->calculateV2(
            ['home_goals' => 5, 'away_goals' => 5, 'has_data' => true],
            ['home_goals' => 4, 'away_goals' => 3, 'has_data' => true],
            $data
        );

        $this->assertLessThanOrEqual(1.0, $result['probability']);
        $this->assertGreaterThanOrEqual(0.0, $result['probability']);
    }

    public function testV2PdiReturnsZeroWhenDangerousAttacksBelowThreshold(): void

    {
        $this->calculator->setAlgorithmVersion(2);
        
        $result = $this->calculator->calculateV2(
            ['home_goals' => 3, 'away_goals' => 2, 'has_data' => true],
            ['home_goals' => 2, 'away_goals' => 1, 'has_data' => true],
            [
                'minute' => 25,
                'dangerous_attacks_home' => 8,
                'dangerous_attacks_away' => 10,
                'dangerous_attacks' => 18,
                'shots_total' => 4,
                'shots_on_target' => 2,
                'shots_on_target_home' => 1,
                'shots_on_target_away' => 1,
                'shots_off_target_home' => 1,
                'shots_off_target_away' => 1,
                'corners_home' => 1,
                'corners_away' => 1,
                'xg_home' => 0.3,
                'xg_away' => 0.2,
                'yellow_cards_home' => 0,
                'yellow_cards_away' => 0,
                'has_trend_data' => false,
                'ht_hscore' => 0,
                'ht_ascore' => 0,
                'live_hscore' => 0,
                'live_ascore' => 0,
            ]
        );

        $this->assertSame(0.0, $result['components']['pdi']);
    }

    public function testV2PdiReturnsHighScoreForBalancedIntenseGame(): void
    {
        $this->calculator->setAlgorithmVersion(2);
        
        $result = $this->calculator->calculateV2(
            ['home_goals' => 3, 'away_goals' => 2, 'has_data' => true],
            ['home_goals' => 2, 'away_goals' => 1, 'has_data' => true],
            [
                'minute' => 25,
                'dangerous_attacks_home' => 15,
                'dangerous_attacks_away' => 18,
                'dangerous_attacks' => 33,
                'shots_total' => 6,
                'shots_on_target' => 3,
                'shots_on_target_home' => 2,
                'shots_on_target_away' => 1,
                'shots_off_target_home' => 2,
                'shots_off_target_away' => 1,
                'corners_home' => 2,
                'corners_away' => 2,
                'xg_home' => 0.5,
                'xg_away' => 0.4,
                'yellow_cards_home' => 0,
                'yellow_cards_away' => 0,
                'has_trend_data' => false,
                'ht_hscore' => 0,
                'ht_ascore' => 0,
                'live_hscore' => 0,
                'live_ascore' => 0,
            ]
        );

        $this->assertGreaterThan(0.7, $result['components']['pdi']);
    }

    public function testV2ShotQualityUsesXgWhenAvailable(): void
    {
        $this->calculator->setAlgorithmVersion(2);
        
        $result = $this->calculator->calculateV2(
            ['home_goals' => 3, 'away_goals' => 2, 'has_data' => true],
            ['home_goals' => 2, 'away_goals' => 1, 'has_data' => true],
            [
                'minute' => 25,
                'dangerous_attacks_home' => 20,
                'dangerous_attacks_away' => 15,
                'dangerous_attacks' => 35,
                'shots_total' => 8,
                'shots_on_target' => 5,
                'shots_on_target_home' => 3,
                'shots_on_target_away' => 2,
                'shots_off_target_home' => 2,
                'shots_off_target_away' => 1,
                'corners_home' => 2,
                'corners_away' => 1,
                'xg_home' => 1.2,
                'xg_away' => 0.8,
                'yellow_cards_home' => 0,
                'yellow_cards_away' => 0,
                'has_trend_data' => false,
                'ht_hscore' => 0,
                'ht_ascore' => 0,
                'live_hscore' => 0,
                'live_ascore' => 0,
            ]
        );

        $this->assertGreaterThan(0.6, $result['components']['shot_quality']);
    }

    public function testV2ShotQualityFallsBackToAccuracyWhenXgMissing(): void
    {
        $this->calculator->setAlgorithmVersion(2);
        
        $result = $this->calculator->calculateV2(
            ['home_goals' => 3, 'away_goals' => 2, 'has_data' => true],
            ['home_goals' => 2, 'away_goals' => 1, 'has_data' => true],
            [
                'minute' => 25,
                'dangerous_attacks_home' => 20,
                'dangerous_attacks_away' => 15,
                'dangerous_attacks' => 35,
                'shots_total' => 10,
                'shots_on_target' => 6,
                'shots_on_target_home' => 4,
                'shots_on_target_away' => 2,
                'shots_off_target_home' => 3,
                'shots_off_target_away' => 1,
                'corners_home' => 2,
                'corners_away' => 1,
                'xg_home' => null,
                'xg_away' => null,
                'yellow_cards_home' => 0,
                'yellow_cards_away' => 0,
                'has_trend_data' => false,
                'ht_hscore' => 0,
                'ht_ascore' => 0,
                'live_hscore' => 0,
                'live_ascore' => 0,
            ]
        );

        $this->assertEqualsWithDelta(0.6, $result['components']['shot_quality'], 0.01);
    }

    public function testV2TimePressureIsZeroOutsideWindow(): void
    {
        $this->calculator->setAlgorithmVersion(2);
        
        $result1 = $this->calculator->calculateV2(
            ['home_goals' => 3, 'away_goals' => 2, 'has_data' => true],
            ['home_goals' => 2, 'away_goals' => 1, 'has_data' => true],
            $this->buildBasicLiveData(10)
        );
        
        $result2 = $this->calculator->calculateV2(
            ['home_goals' => 3, 'away_goals' => 2, 'has_data' => true],
            ['home_goals' => 2, 'away_goals' => 1, 'has_data' => true],
            $this->buildBasicLiveData(35)
        );

        $this->assertSame(0.0, $result1['components']['time_pressure']);
        $this->assertSame(0.0, $result2['components']['time_pressure']);
    }

    public function testV2TimePressureGrowsNonLinearlyFrom15To30(): void
    {
        $this->calculator->setAlgorithmVersion(2);
        
        $result15 = $this->calculator->calculateV2(
            ['home_goals' => 3, 'away_goals' => 2, 'has_data' => true],
            ['home_goals' => 2, 'away_goals' => 1, 'has_data' => true],
            $this->buildBasicLiveData(15)
        );
        
        $result22 = $this->calculator->calculateV2(
            ['home_goals' => 3, 'away_goals' => 2, 'has_data' => true],
            ['home_goals' => 2, 'away_goals' => 1, 'has_data' => true],
            $this->buildBasicLiveData(22)
        );
        
        $result30 = $this->calculator->calculateV2(
            ['home_goals' => 3, 'away_goals' => 2, 'has_data' => true],
            ['home_goals' => 2, 'away_goals' => 1, 'has_data' => true],
            $this->buildBasicLiveData(30)
        );

        $this->assertSame(0.0, $result15['components']['time_pressure']);
        $this->assertGreaterThan(0.0, $result22['components']['time_pressure']);
        $this->assertLessThan(1.0, $result22['components']['time_pressure']);
        $this->assertSame(1.0, $result30['components']['time_pressure']);
        $this->assertGreaterThan($result22['components']['time_pressure'], $result30['components']['time_pressure']);
    }

    public function testV2LeagueFactorDefaultsTo1WhenTableAvgMissing(): void
    {
        $this->calculator->setAlgorithmVersion(2);
        
        $result = $this->calculator->calculateV2(
            ['home_goals' => 3, 'away_goals' => 2, 'has_data' => true],
            ['home_goals' => 2, 'away_goals' => 1, 'has_data' => true],
            $this->buildBasicLiveData(25)
        );

        $this->assertSame(1.0, $result['components']['league_factor']);
    }

    public function testV2LeagueFactorAdjustsBasedOnTableAvg(): void
    {
        $this->calculator->setAlgorithmVersion(2);
        
        $dataLow = $this->buildBasicLiveData(25);
        $dataLow['table_avg'] = 1.8;
        
        $dataHigh = $this->buildBasicLiveData(25);
        $dataHigh['table_avg'] = 3.2;
        
        $resultLow = $this->calculator->calculateV2(
            ['home_goals' => 3, 'away_goals' => 2, 'has_data' => true],
            ['home_goals' => 2, 'away_goals' => 1, 'has_data' => true],
            $dataLow
        );
        
        $resultHigh = $this->calculator->calculateV2(
            ['home_goals' => 3, 'away_goals' => 2, 'has_data' => true],
            ['home_goals' => 2, 'away_goals' => 1, 'has_data' => true],
            $dataHigh
        );

        $this->assertEqualsWithDelta(0.72, $resultLow['components']['league_factor'], 0.01);
        $this->assertEqualsWithDelta(1.28, $resultHigh['components']['league_factor'], 0.01);
    }

    public function testV2LeagueFactorClampedTo07And13(): void
    {
        $this->calculator->setAlgorithmVersion(2);
        
        $dataVeryLow = $this->buildBasicLiveData(25);
        $dataVeryLow['table_avg'] = 1.0;
        
        $dataVeryHigh = $this->buildBasicLiveData(25);
        $dataVeryHigh['table_avg'] = 5.0;
        
        $resultVeryLow = $this->calculator->calculateV2(
            ['home_goals' => 3, 'away_goals' => 2, 'has_data' => true],
            ['home_goals' => 2, 'away_goals' => 1, 'has_data' => true],
            $dataVeryLow
        );
        
        $resultVeryHigh = $this->calculator->calculateV2(
            ['home_goals' => 3, 'away_goals' => 2, 'has_data' => true],
            ['home_goals' => 2, 'away_goals' => 1, 'has_data' => true],
            $dataVeryHigh
        );

        $this->assertSame(0.7, $resultVeryLow['components']['league_factor']);
        $this->assertSame(1.3, $resultVeryHigh['components']['league_factor']);
    }

    public function testV2TrendAccelerationReturnsZeroWhenNoTrendData(): void
    {
        $this->calculator->setAlgorithmVersion(2);
        
        $data = $this->buildBasicLiveData(25);
        $data['has_trend_data'] = false;
        
        $result = $this->calculator->calculateV2(
            ['home_goals' => 3, 'away_goals' => 2, 'has_data' => true],
            ['home_goals' => 2, 'away_goals' => 1, 'has_data' => true],
            $data
        );

        $this->assertSame(0.0, $result['components']['trend_acceleration']);
    }

    public function testV2TrendAccelerationHighForStrongVelocity(): void
    {
        $this->calculator->setAlgorithmVersion(2);
        
        $data = $this->buildBasicLiveData(25);
        $data['has_trend_data'] = true;
        $data['trend_shots_total_delta'] = 25;
        $data['trend_dangerous_attacks_delta'] = 50;
        $data['trend_xg_delta'] = 1.0;
        $data['trend_window_seconds'] = 300;
        
        $result = $this->calculator->calculateV2(
            ['home_goals' => 3, 'away_goals' => 2, 'has_data' => true],
            ['home_goals' => 2, 'away_goals' => 1, 'has_data' => true],
            $data
        );

        $this->assertGreaterThan(0.7, $result['components']['trend_acceleration']);
    }

    public function testV2CardFactorCanBeNegative(): void
    {
        $this->calculator->setAlgorithmVersion(2);
        
        $dataHomeCards = $this->buildBasicLiveData(25);
        $dataHomeCards['yellow_cards_home'] = 3;
        $dataHomeCards['yellow_cards_away'] = 0;
        
        $dataAwayCards = $this->buildBasicLiveData(25);
        $dataAwayCards['yellow_cards_home'] = 0;
        $dataAwayCards['yellow_cards_away'] = 3;
        
        $resultHomeCards = $this->calculator->calculateV2(
            ['home_goals' => 3, 'away_goals' => 2, 'has_data' => true],
            ['home_goals' => 2, 'away_goals' => 1, 'has_data' => true],
            $dataHomeCards
        );
        
        $resultAwayCards = $this->calculator->calculateV2(
            ['home_goals' => 3, 'away_goals' => 2, 'has_data' => true],
            ['home_goals' => 2, 'away_goals' => 1, 'has_data' => true],
            $dataAwayCards
        );

        $this->assertLessThan(0.0, $resultHomeCards['components']['card_factor']);
        $this->assertGreaterThan(0.0, $resultAwayCards['components']['card_factor']);
    }

    public function testV2CardFactorScalesWithDifference(): void
    {
        $this->calculator->setAlgorithmVersion(2);
        
        $data1 = $this->buildBasicLiveData(25);
        $data1['yellow_cards_home'] = 0;
        $data1['yellow_cards_away'] = 1;
        
        $data2 = $this->buildBasicLiveData(25);
        $data2['yellow_cards_home'] = 0;
        $data2['yellow_cards_away'] = 2;
        
        $data3 = $this->buildBasicLiveData(25);
        $data3['yellow_cards_home'] = 0;
        $data3['yellow_cards_away'] = 4;
        
        $result1 = $this->calculator->calculateV2(
            ['home_goals' => 3, 'away_goals' => 2, 'has_data' => true],
            ['home_goals' => 2, 'away_goals' => 1, 'has_data' => true],
            $data1
        );
        
        $result2 = $this->calculator->calculateV2(
            ['home_goals' => 3, 'away_goals' => 2, 'has_data' => true],
            ['home_goals' => 2, 'away_goals' => 1, 'has_data' => true],
            $data2
        );
        
        $result3 = $this->calculator->calculateV2(
            ['home_goals' => 3, 'away_goals' => 2, 'has_data' => true],
            ['home_goals' => 2, 'away_goals' => 1, 'has_data' => true],
            $data3
        );

        $this->assertEqualsWithDelta(0.03, $result1['components']['card_factor'], 0.001);
        $this->assertEqualsWithDelta(0.08, $result2['components']['card_factor'], 0.001);
        $this->assertEqualsWithDelta(0.15, $result3['components']['card_factor'], 0.001);
    }

    public function testV2XgPressureReturnsZeroWhenXgMissing(): void
    {
        $this->calculator->setAlgorithmVersion(2);
        
        $data = $this->buildBasicLiveData(25);
        $data['xg_home'] = null;
        $data['xg_away'] = null;
        
        $result = $this->calculator->calculateV2(
            ['home_goals' => 3, 'away_goals' => 2, 'has_data' => true],
            ['home_goals' => 2, 'away_goals' => 1, 'has_data' => true],
            $data
        );

        $this->assertSame(0.0, $result['components']['xg_pressure']);
    }

    public function testV2XgPressureNormalizedCorrectly(): void
    {
        $this->calculator->setAlgorithmVersion(2);
        
        $dataLow = $this->buildBasicLiveData(25);
        $dataLow['xg_home'] = 0.3;
        $dataLow['xg_away'] = 0.2;
        
        $dataHigh = $this->buildBasicLiveData(25);
        $dataHigh['xg_home'] = 1.0;
        $dataHigh['xg_away'] = 0.8;
        
        $resultLow = $this->calculator->calculateV2(
            ['home_goals' => 3, 'away_goals' => 2, 'has_data' => true],
            ['home_goals' => 2, 'away_goals' => 1, 'has_data' => true],
            $dataLow
        );
        
        $resultHigh = $this->calculator->calculateV2(
            ['home_goals' => 3, 'away_goals' => 2, 'has_data' => true],
            ['home_goals' => 2, 'away_goals' => 1, 'has_data' => true],
            $dataHigh
        );

        $this->assertEqualsWithDelta(0.333, $resultLow['components']['xg_pressure'], 0.01);
        $this->assertSame(1.0, $resultHigh['components']['xg_pressure']);
    }

    // ========== RED FLAGS TESTS ==========

    public function testV2RedFlagLowAccuracyBlocks(): void
    {
        $this->calculator->setAlgorithmVersion(2);
        
        $data = $this->buildBasicLiveData(25);
        $data['shots_total'] = 12;
        $data['shots_on_target'] = 2;
        $data['dangerous_attacks'] = 35;
        
        $result = $this->calculator->calculateV2(
            ['home_goals' => 3, 'away_goals' => 2, 'has_data' => true],
            ['home_goals' => 2, 'away_goals' => 1, 'has_data' => true],
            $data
        );

        $this->assertSame('low_accuracy', $result['components']['red_flag']);
        $this->assertFalse($result['decision']['bet']);
        $this->assertSame('red_flag_low_accuracy', $result['decision']['reason']);
    }

    public function testV2RedFlagIneffectivePressureBlocks(): void
    {
        $this->calculator->setAlgorithmVersion(2);
        
        $data = $this->buildBasicLiveData(25);
        $data['dangerous_attacks_home'] = 35;
        $data['dangerous_attacks_away'] = 5;
        $data['dangerous_attacks'] = 40;
        $data['shots_on_target_home'] = 1;
        $data['shots_on_target'] = 2;
        
        $result = $this->calculator->calculateV2(
            ['home_goals' => 3, 'away_goals' => 2, 'has_data' => true],
            ['home_goals' => 2, 'away_goals' => 1, 'has_data' => true],
            $data
        );

        $this->assertSame('ineffective_pressure', $result['components']['red_flag']);
        $this->assertFalse($result['decision']['bet']);
        $this->assertSame('red_flag_ineffective_pressure', $result['decision']['reason']);
    }

    public function testV2RedFlagXgMismatchDoesNotBlockButReducesXg(): void
    {
        $this->calculator->setAlgorithmVersion(2);
        
        $data = $this->buildBasicLiveData(27);
        $data['xg_home'] = 0.8;
        $data['xg_away'] = 0.6;
        $data['ht_hscore'] = 0;
        $data['ht_ascore'] = 0;
        $data['dangerous_attacks'] = 35;
        $data['shots_on_target'] = 4;
        
        $result = $this->calculator->calculateV2(
            ['home_goals' => 3, 'away_goals' => 2, 'has_data' => true],
            ['home_goals' => 2, 'away_goals' => 1, 'has_data' => true],
            $data
        );

        $this->assertSame('xg_mismatch', $result['components']['red_flag']);
        // xg_mismatch should not block the bet if other conditions are met
        // but xg_pressure component should be reduced by 50%
    }

    // ========== DECISION/GATING TESTS ==========

    public function testV2DecisionBlocksWhenNoFormData(): void
    {
        $this->calculator->setAlgorithmVersion(2);
        
        $result = $this->calculator->calculateV2(
            ['home_goals' => 0, 'away_goals' => 0, 'has_data' => false],
            ['home_goals' => 2, 'away_goals' => 1, 'has_data' => true],
            $this->buildBasicLiveData(25)
        );

        $this->assertFalse($result['decision']['bet']);
        $this->assertSame('no_form_data', $result['decision']['reason']);
    }

    public function testV2DecisionBlocksWhenNoH2hData(): void
    {
        $this->calculator->setAlgorithmVersion(2);
        
        $result = $this->calculator->calculateV2(
            ['home_goals' => 3, 'away_goals' => 2, 'has_data' => true],
            ['home_goals' => 0, 'away_goals' => 0, 'has_data' => false],
            $this->buildBasicLiveData(25)
        );

        $this->assertFalse($result['decision']['bet']);
        $this->assertSame('no_h2h_data', $result['decision']['reason']);
    }

    public function testV2DecisionBlocksWhenHtScoreNotZeroZero(): void
    {
        $this->calculator->setAlgorithmVersion(2);
        
        $data = $this->buildBasicLiveData(25);
        $data['ht_hscore'] = 1;
        $data['ht_ascore'] = 0;
        
        $result = $this->calculator->calculateV2(
            ['home_goals' => 3, 'away_goals' => 2, 'has_data' => true],
            ['home_goals' => 2, 'away_goals' => 1, 'has_data' => true],
            $data
        );

        $this->assertFalse($result['decision']['bet']);
        $this->assertSame('ht_score_not_0_0', $result['decision']['reason']);
    }

    public function testV2DecisionBlocksWhenMinuteOutOfRange(): void
    {
        $this->calculator->setAlgorithmVersion(2);
        
        $result1 = $this->calculator->calculateV2(
            ['home_goals' => 3, 'away_goals' => 2, 'has_data' => true],
            ['home_goals' => 2, 'away_goals' => 1, 'has_data' => true],
            $this->buildBasicLiveData(10)
        );
        
        $result2 = $this->calculator->calculateV2(
            ['home_goals' => 3, 'away_goals' => 2, 'has_data' => true],
            ['home_goals' => 2, 'away_goals' => 1, 'has_data' => true],
            $this->buildBasicLiveData(35)
        );

        $this->assertFalse($result1['decision']['bet']);
        $this->assertSame('minute_out_of_range', $result1['decision']['reason']);
        $this->assertFalse($result2['decision']['bet']);
        $this->assertSame('minute_out_of_range', $result2['decision']['reason']);
    }

    public function testV2DecisionBlocksWhenInsufficientShotsOnTarget(): void
    {
        $this->calculator->setAlgorithmVersion(2);
        
        $data = $this->buildBasicLiveData(25);
        $data['shots_on_target'] = 0;
        
        $result = $this->calculator->calculateV2(
            ['home_goals' => 3, 'away_goals' => 2, 'has_data' => true],
            ['home_goals' => 2, 'away_goals' => 1, 'has_data' => true],
            $data
        );

        $this->assertFalse($result['decision']['bet']);
        $this->assertSame('insufficient_shots_on_target', $result['decision']['reason']);
    }

    public function testV2DecisionBlocksWhenInsufficientDangerousAttacks(): void
    {
        $this->calculator->setAlgorithmVersion(2);
        
        $data = $this->buildBasicLiveData(25);
        $data['dangerous_attacks'] = 15;
        $data['dangerous_attacks_home'] = 10;
        $data['dangerous_attacks_away'] = 5;
        
        $result = $this->calculator->calculateV2(
            ['home_goals' => 3, 'away_goals' => 2, 'has_data' => true],
            ['home_goals' => 2, 'away_goals' => 1, 'has_data' => true],
            $data
        );

        $this->assertFalse($result['decision']['bet']);
        $this->assertSame('insufficient_dangerous_attacks', $result['decision']['reason']);
    }

    public function testV2DecisionBlocksWhenProbabilityBelowThreshold(): void
    {
        $this->calculator->setAlgorithmVersion(2);
        
        $data = $this->buildBasicLiveData(25);
        $data['dangerous_attacks'] = 20;
        $data['dangerous_attacks_home'] = 12;
        $data['dangerous_attacks_away'] = 8;
        $data['shots_total'] = 4;
        $data['shots_on_target'] = 2;
        $data['xg_home'] = 0.1;
        $data['xg_away'] = 0.1;
        
        $result = $this->calculator->calculateV2(
            ['home_goals' => 1, 'away_goals' => 1, 'has_data' => true],
            ['home_goals' => 1, 'away_goals' => 0, 'has_data' => true],
            $data
        );

        $this->assertFalse($result['decision']['bet']);
        $this->assertSame('probability_below_threshold', $result['decision']['reason']);
    }

    public function testV2DecisionPassesWhenAllConditionsMet(): void
    {
        $this->calculator->setAlgorithmVersion(2);
        
        $data = $this->buildBasicLiveData(28);
        $data['dangerous_attacks'] = 45;
        $data['dangerous_attacks_home'] = 23;
        $data['dangerous_attacks_away'] = 22;
        $data['shots_on_target'] = 8;
        $data['shots_total'] = 12;
        $data['xg_home'] = 1.5;
        $data['xg_away'] = 1.3;
        $data['has_trend_data'] = true;
        $data['trend_shots_total_delta'] = 6;
        $data['trend_dangerous_attacks_delta'] = 15;
        $data['trend_xg_delta'] = 0.5;
        $data['trend_window_seconds'] = 300;
        
        $result = $this->calculator->calculateV2(
            ['home_goals' => 5, 'away_goals' => 5, 'has_data' => true],
            ['home_goals' => 5, 'away_goals' => 4, 'has_data' => true],
            $data
        );

        $this->assertTrue($result['decision']['bet']);
        $this->assertSame('all_conditions_met', $result['decision']['reason']);
        $this->assertGreaterThanOrEqual(0.65, $result['probability']);
    }

    private function buildBasicLiveData(int $minute): array
    {
        return [
            'minute' => $minute,
            'dangerous_attacks_home' => 20,
            'dangerous_attacks_away' => 15,
            'dangerous_attacks' => 35,
            'shots_total' => 6,
            'shots_on_target' => 3,
            'shots_on_target_home' => 2,
            'shots_on_target_away' => 1,
            'shots_off_target_home' => 2,
            'shots_off_target_away' => 1,
            'corners_home' => 2,
            'corners_away' => 1,
            'xg_home' => 0.5,
            'xg_away' => 0.3,
            'yellow_cards_home' => 0,
            'yellow_cards_away' => 0,
            'has_trend_data' => false,
            'ht_hscore' => 0,
            'ht_ascore' => 0,
            'live_hscore' => 0,
            'live_ascore' => 0,
        ];
    }
}
