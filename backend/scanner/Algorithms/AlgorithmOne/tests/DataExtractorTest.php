<?php

declare(strict_types=1);

namespace Proxbet\Scanner\Algorithms\AlgorithmOne\Tests;

use PHPUnit\Framework\TestCase;
use Proxbet\Scanner\Algorithms\AlgorithmOne\DataExtractor;

final class DataExtractorTest extends TestCase
{
    private DataExtractor $extractor;

    protected function setUp(): void
    {
        $this->extractor = new DataExtractor();
    }

    public function testExtractFormDataWithValidData(): void
    {
        $match = [
            'ht_match_goals_1' => 4,
            'ht_match_goals_2' => 3,
        ];

        $result = $this->extractor->extractFormData($match);

        $this->assertSame(4, $result['home_goals']);
        $this->assertSame(3, $result['away_goals']);
        $this->assertTrue($result['has_data']);
    }

    public function testExtractFormDataWithMissingData(): void
    {
        $match = [
            'ht_match_goals_1' => null,
            'ht_match_goals_2' => 3,
        ];

        $result = $this->extractor->extractFormData($match);

        $this->assertSame(0, $result['home_goals']);
        $this->assertSame(3, $result['away_goals']);
        $this->assertFalse($result['has_data']);
    }

    public function testExtractFormDataV2WithWeightedMetrics(): void
    {
        $match = [
            'ht_match_goals_1' => 4,
            'ht_match_goals_2' => 3,
        ];

        $weightedMetrics = [
            'weighted_form' => [
                'home' => ['attack' => 0.8, 'defense' => 0.3],
                'away' => ['attack' => 0.6, 'defense' => 0.4],
                'score' => 0.75,
            ],
        ];

        $result = $this->extractor->extractFormDataV2($match, $weightedMetrics);

        $this->assertSame(4, $result['home_goals']);
        $this->assertSame(3, $result['away_goals']);
        $this->assertTrue($result['has_data']);
        $this->assertNotNull($result['weighted']);
        $this->assertSame(0.75, $result['weighted']['score']);
        $this->assertSame(0.8, $result['weighted']['home']['attack']);
    }

    public function testExtractFormDataV2WithoutWeightedMetrics(): void
    {
        $match = [
            'ht_match_goals_1' => 4,
            'ht_match_goals_2' => 3,
        ];

        $result = $this->extractor->extractFormDataV2($match, null);

        $this->assertSame(4, $result['home_goals']);
        $this->assertSame(3, $result['away_goals']);
        $this->assertTrue($result['has_data']);
        $this->assertNull($result['weighted']);
    }

    public function testExtractH2hDataWithValidData(): void
    {
        $match = [
            'h2h_ht_match_goals_1' => 2,
            'h2h_ht_match_goals_2' => 2,
        ];

        $result = $this->extractor->extractH2hData($match);

        $this->assertSame(2, $result['home_goals']);
        $this->assertSame(2, $result['away_goals']);
        $this->assertTrue($result['has_data']);
    }

    public function testExtractH2hDataWithMissingData(): void
    {
        $match = [];

        $result = $this->extractor->extractH2hData($match);

        $this->assertSame(0, $result['home_goals']);
        $this->assertSame(0, $result['away_goals']);
        $this->assertFalse($result['has_data']);
    }

    public function testExtractLiveDataWithCompleteData(): void
    {
        $match = [
            'time' => '22:30',
            'live_shots_on_target_home' => 5,
            'live_shots_on_target_away' => 3,
            'live_shots_off_target_home' => 2,
            'live_shots_off_target_away' => 1,
            'live_danger_att_home' => 25,
            'live_danger_att_away' => 20,
            'live_corner_home' => 4,
            'live_corner_away' => 3,
            'live_ht_hscore' => 0,
            'live_ht_ascore' => 0,
            'live_hscore' => 0,
            'live_ascore' => 0,
            'live_xg_home' => 1.5,
            'live_xg_away' => 1.2,
            'live_yellow_cards_home' => 1,
            'live_yellow_cards_away' => 2,
            'live_trend_shots_total_delta' => 3,
            'live_trend_shots_on_target_delta' => 2,
            'live_trend_danger_attacks_delta' => 5,
            'live_trend_xg_delta' => 0.3,
            'live_trend_window_seconds' => 300,
            'live_trend_has_data' => 1,
            'match_status' => '1H',
        ];

        $result = $this->extractor->extractLiveData($match);

        $this->assertSame(22, $result['minute']);
        $this->assertSame(11, $result['shots_total']);
        $this->assertSame(8, $result['shots_on_target']);
        $this->assertSame(45, $result['dangerous_attacks']);
        $this->assertSame(7, $result['corners']);
        $this->assertSame(5, $result['shots_on_target_home']);
        $this->assertSame(3, $result['shots_on_target_away']);
        $this->assertSame(1.5, $result['xg_home']);
        $this->assertSame(1.2, $result['xg_away']);
        $this->assertSame(1, $result['yellow_cards_home']);
        $this->assertSame(2, $result['yellow_cards_away']);
        $this->assertTrue($result['has_trend_data']);
        $this->assertSame('22:30', $result['time_str']);
        $this->assertSame('1H', $result['match_status']);
    }

    public function testExtractLiveDataV2WithTableAvg(): void
    {
        $match = [
            'time' => '28:15',
            'live_shots_on_target_home' => 6,
            'live_shots_on_target_away' => 4,
            'live_shots_off_target_home' => 3,
            'live_shots_off_target_away' => 2,
            'live_danger_att_home' => 30,
            'live_danger_att_away' => 25,
            'live_corner_home' => 5,
            'live_corner_away' => 4,
            'live_ht_hscore' => 0,
            'live_ht_ascore' => 0,
            'live_hscore' => 0,
            'live_ascore' => 0,
            'live_xg_home' => 1.8,
            'live_xg_away' => 1.5,
            'live_yellow_cards_home' => 2,
            'live_yellow_cards_away' => 1,
            'table_avg' => 2.8,
            'live_trend_has_data' => 0,
            'match_status' => '1H',
        ];

        $result = $this->extractor->extractLiveDataV2($match);

        $this->assertSame(28, $result['minute']);
        $this->assertSame(15, $result['shots_total']);
        $this->assertSame(10, $result['shots_on_target']);
        $this->assertSame(55, $result['dangerous_attacks']);
        $this->assertSame(3.3, $result['xg_total']);
        $this->assertSame(2, $result['yellow_cards_home']);
        $this->assertSame(1, $result['yellow_cards_away']);
        $this->assertSame(2.8, $result['table_avg']);
        $this->assertFalse($result['has_trend_data']);
    }

    public function testExtractLiveDataV2WithoutTableAvg(): void
    {
        $match = [
            'time' => '20:00',
            'live_shots_on_target_home' => 3,
            'live_shots_on_target_away' => 2,
            'live_shots_off_target_home' => 1,
            'live_shots_off_target_away' => 1,
            'live_danger_att_home' => 20,
            'live_danger_att_away' => 15,
            'live_corner_home' => 2,
            'live_corner_away' => 2,
            'live_ht_hscore' => 0,
            'live_ht_ascore' => 0,
            'live_hscore' => 0,
            'live_ascore' => 0,
            'live_trend_has_data' => 0,
            'match_status' => '1H',
        ];

        $result = $this->extractor->extractLiveDataV2($match);

        $this->assertSame(20, $result['minute']);
        $this->assertNull($result['table_avg']);
        $this->assertNull($result['xg_home']);
        $this->assertNull($result['xg_away']);
        $this->assertSame(0.0, $result['xg_total']);
    }

    public function testParseMinuteFromTimeString(): void
    {
        $testCases = [
            ['time' => '15:30', 'expected' => 15],
            ['time' => '22:45', 'expected' => 22],
            ['time' => '30:00', 'expected' => 30],
            ['time' => '5:15', 'expected' => 5],
            ['time' => '00:00', 'expected' => 0],
            ['time' => 'invalid', 'expected' => 0],
            ['time' => '', 'expected' => 0],
        ];

        foreach ($testCases as $testCase) {
            $match = ['time' => $testCase['time']];
            $result = $this->extractor->extractLiveData($match);
            $this->assertSame(
                $testCase['expected'],
                $result['minute'],
                "Failed for time: {$testCase['time']}"
            );
        }
    }

    public function testExtractLiveDataWithMissingFields(): void
    {
        $match = [
            'time' => '20:00',
            'match_status' => '1H',
        ];

        $result = $this->extractor->extractLiveData($match);

        $this->assertSame(20, $result['minute']);
        $this->assertSame(0, $result['shots_total']);
        $this->assertSame(0, $result['shots_on_target']);
        $this->assertSame(0, $result['dangerous_attacks']);
        $this->assertSame(0, $result['corners']);
        $this->assertNull($result['xg_home']);
        $this->assertNull($result['xg_away']);
        $this->assertNull($result['yellow_cards_home']);
        $this->assertNull($result['yellow_cards_away']);
        $this->assertFalse($result['has_trend_data']);
    }

    public function testExtractLiveDataV2WithMissingFields(): void
    {
        $match = [
            'time' => '25:00',
            'match_status' => '1H',
        ];

        $result = $this->extractor->extractLiveDataV2($match);

        $this->assertSame(25, $result['minute']);
        $this->assertSame(0, $result['shots_total']);
        $this->assertSame(0, $result['yellow_cards_home']);
        $this->assertSame(0, $result['yellow_cards_away']);
        $this->assertSame(0.0, $result['xg_total']);
        $this->assertNull($result['table_avg']);
    }
}
