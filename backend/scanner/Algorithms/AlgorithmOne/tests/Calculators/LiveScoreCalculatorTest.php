<?php

declare(strict_types=1);

namespace Proxbet\Scanner\Algorithms\AlgorithmOne\Tests\Calculators;

use PHPUnit\Framework\TestCase;
use Proxbet\Scanner\Algorithms\AlgorithmOne\Calculators\LiveScoreCalculator;

final class LiveScoreCalculatorTest extends TestCase
{
    private LiveScoreCalculator $calculator;

    protected function setUp(): void
    {
        $this->calculator = new LiveScoreCalculator();
    }

    private function buildBasicLiveData(array $overrides = []): array
    {
        return array_merge([
            'minute' => 20,
            'shots_total' => 8,
            'shots_on_target' => 4,
            'dangerous_attacks' => 28,
            'corners' => 4,
            'shots_on_target_home' => 2,
            'shots_on_target_away' => 2,
            'shots_off_target_home' => 2,
            'shots_off_target_away' => 2,
            'dangerous_attacks_home' => 14,
            'dangerous_attacks_away' => 14,
            'corners_home' => 2,
            'corners_away' => 2,
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
            'time_str' => '20:00',
            'match_status' => 'HT',
        ], $overrides);
    }

    public function testCalculatesWithBasicMetrics(): void
    {
        $liveData = $this->buildBasicLiveData();

        $result = $this->calculator->calculate($liveData);

        $this->assertIsFloat($result);
        $this->assertGreaterThanOrEqual(0.0, $result);
        $this->assertLessThanOrEqual(1.0, $result);
    }

    public function testReturnsZeroWithNoActivity(): void
    {
        $liveData = $this->buildBasicLiveData([
            'shots_total' => 0,
            'shots_on_target' => 0,
            'dangerous_attacks' => 0,
            'corners' => 0,
            'shots_on_target_home' => 0,
            'shots_on_target_away' => 0,
            'shots_off_target_home' => 0,
            'shots_off_target_away' => 0,
            'dangerous_attacks_home' => 0,
            'dangerous_attacks_away' => 0,
            'corners_home' => 0,
            'corners_away' => 0,
        ]);

        $result = $this->calculator->calculate($liveData);

        $this->assertSame(0.0, $result);
    }

    public function testIncludesXgScoreWhenAvailable(): void
    {
        $liveDataWithoutXg = $this->buildBasicLiveData();
        $liveDataWithXg = $this->buildBasicLiveData([
            'xg_home' => 0.8,
            'xg_away' => 0.6,
        ]);

        $resultWithoutXg = $this->calculator->calculate($liveDataWithoutXg);
        $resultWithXg = $this->calculator->calculate($liveDataWithXg);

        // With xG should generally be different (higher in most cases)
        $this->assertNotEquals($resultWithoutXg, $resultWithXg);
    }

    public function testIncludesDisciplineScoreWhenAvailable(): void
    {
        $liveDataWithoutCards = $this->buildBasicLiveData();
        $liveDataWithCards = $this->buildBasicLiveData([
            'yellow_cards_home' => 2,
            'yellow_cards_away' => 1,
        ]);

        $resultWithoutCards = $this->calculator->calculate($liveDataWithoutCards);
        $resultWithCards = $this->calculator->calculate($liveDataWithCards);

        // With cards should be different
        $this->assertNotEquals($resultWithoutCards, $resultWithCards);
    }

    public function testIncludesTrendScoreWhenAvailable(): void
    {
        $liveDataWithoutTrend = $this->buildBasicLiveData();
        $liveDataWithTrend = $this->buildBasicLiveData([
            'has_trend_data' => true,
            'trend_window_seconds' => 300,
            'trend_shots_total_delta' => 4,
            'trend_shots_on_target_delta' => 2,
            'trend_dangerous_attacks_delta' => 10,
            'trend_xg_delta' => 0.3,
        ]);

        $resultWithoutTrend = $this->calculator->calculate($liveDataWithoutTrend);
        $resultWithTrend = $this->calculator->calculate($liveDataWithTrend);

        // With trend should be different
        $this->assertNotEquals($resultWithoutTrend, $resultWithTrend);
    }

    public function testHighActivityProducesHighScore(): void
    {
        $liveData = $this->buildBasicLiveData([
            'shots_total' => 16,
            'shots_on_target' => 8,
            'dangerous_attacks' => 56,
            'corners' => 8,
            'shots_on_target_home' => 4,
            'shots_on_target_away' => 4,
            'shots_off_target_home' => 4,
            'shots_off_target_away' => 4,
            'dangerous_attacks_home' => 28,
            'dangerous_attacks_away' => 28,
            'corners_home' => 4,
            'corners_away' => 4,
            'xg_home' => 1.2,
            'xg_away' => 1.0,
        ]);

        $result = $this->calculator->calculate($liveData);

        $this->assertGreaterThan(0.7, $result);
    }

    public function testDominanceScoreCalculation(): void
    {
        // Test balanced game
        $balancedData = $this->buildBasicLiveData([
            'shots_on_target_home' => 3,
            'shots_on_target_away' => 3,
            'dangerous_attacks_home' => 15,
            'dangerous_attacks_away' => 15,
        ]);

        // Test dominated game
        $dominatedData = $this->buildBasicLiveData([
            'shots_on_target_home' => 6,
            'shots_on_target_away' => 0,
            'dangerous_attacks_home' => 25,
            'dangerous_attacks_away' => 3,
        ]);

        $balancedResult = $this->calculator->calculate($balancedData);
        $dominatedResult = $this->calculator->calculate($dominatedData);

        // Both should produce valid scores
        $this->assertGreaterThanOrEqual(0.0, $balancedResult);
        $this->assertGreaterThanOrEqual(0.0, $dominatedResult);
    }

    public function testResultIsRoundedToFourDecimals(): void
    {
        $liveData = $this->buildBasicLiveData();

        $result = $this->calculator->calculate($liveData);

        // Check that result has at most 4 decimal places
        $rounded = round($result, 4);
        $this->assertSame($rounded, $result);
    }
}
