<?php
declare(strict_types=1);

namespace Proxbet\Scanner\Algorithms\AlgorithmOne\Tests\Calculators\V2;

use PHPUnit\Framework\TestCase;
use Proxbet\Scanner\Algorithms\AlgorithmOne\Calculators\V2\TrendCalculator;

final class TrendCalculatorTest extends TestCase
{
    private TrendCalculator $calculator;

    protected function setUp(): void
    {
        $this->calculator = new TrendCalculator();
    }

    public function testReturnsZeroWhenNoTrendData(): void
    {
        $liveData = [
            'has_trend_data' => false,
        ];

        $result = $this->calculator->calculate($liveData);

        $this->assertSame(0.0, $result);
    }

    public function testReturnsZeroWhenWindowSecondsIsZero(): void
    {
        $liveData = [
            'has_trend_data' => true,
            'trend_window_seconds' => 0,
        ];

        $result = $this->calculator->calculate($liveData);

        $this->assertSame(0.0, $result);
    }

    public function testCalculatesVelocityApproach(): void
    {
        $liveData = [
            'has_trend_data' => true,
            'trend_window_seconds' => 300, // 5 minutes
            'trend_shots_total_delta' => 10,
            'trend_dangerous_attacks_delta' => 25,
            'trend_xg_delta' => 0.5,
        ];

        $result = $this->calculator->calculate($liveData);

        // Window: 5 minutes
        // Shots velocity: 10/5 = 2.0 per minute
        // Danger velocity: 25/5 = 5.0 per minute
        // xG velocity: 0.5/5 = 0.1 per minute
        // Score: min(2.0/5, 1) * 0.3 + min(5.0/10, 1) * 0.5 + min(0.1/0.2, 1) * 0.2
        //      = 0.4 * 0.3 + 0.5 * 0.5 + 0.5 * 0.2
        //      = 0.12 + 0.25 + 0.1 = 0.47
        $this->assertEqualsWithDelta(0.47, $result, 0.01);
    }

    public function testHighAccelerationScenario(): void
    {
        $liveData = [
            'has_trend_data' => true,
            'trend_window_seconds' => 180, // 3 minutes
            'trend_shots_total_delta' => 18,
            'trend_dangerous_attacks_delta' => 35,
            'trend_xg_delta' => 0.8,
        ];

        $result = $this->calculator->calculate($liveData);

        // Window: 3 minutes
        // Shots velocity: 18/3 = 6.0 per minute -> min(6/5, 1) = 1.0
        // Danger velocity: 35/3 = 11.67 per minute -> min(11.67/10, 1) = 1.0
        // xG velocity: 0.8/3 = 0.267 per minute -> min(0.267/0.2, 1) = 1.0
        // Score: 1.0 * 0.3 + 1.0 * 0.5 + 1.0 * 0.2 = 1.0
        $this->assertSame(1.0, $result);
    }

    public function testLowAccelerationScenario(): void
    {
        $liveData = [
            'has_trend_data' => true,
            'trend_window_seconds' => 600, // 10 minutes
            'trend_shots_total_delta' => 5,
            'trend_dangerous_attacks_delta' => 10,
            'trend_xg_delta' => 0.2,
        ];

        $result = $this->calculator->calculate($liveData);

        // Window: 10 minutes
        // Shots velocity: 5/10 = 0.5 per minute -> min(0.5/5, 1) = 0.1
        // Danger velocity: 10/10 = 1.0 per minute -> min(1.0/10, 1) = 0.1
        // xG velocity: 0.2/10 = 0.02 per minute -> min(0.02/0.2, 1) = 0.1
        // Score: 0.1 * 0.3 + 0.1 * 0.5 + 0.1 * 0.2 = 0.1
        $this->assertEqualsWithDelta(0.1, $result, 0.01);
    }

    public function testHandlesNegativeDelta(): void
    {
        $liveData = [
            'has_trend_data' => true,
            'trend_window_seconds' => 300,
            'trend_shots_total_delta' => -5,
            'trend_dangerous_attacks_delta' => -10,
            'trend_xg_delta' => -0.3,
        ];

        $result = $this->calculator->calculate($liveData);

        // Negative velocities should result in 0.0 after clamping
        $this->assertSame(0.0, $result);
    }

    public function testHandlesMissingDeltas(): void
    {
        $liveData = [
            'has_trend_data' => true,
            'trend_window_seconds' => 300,
        ];

        $result = $this->calculator->calculate($liveData);

        // All deltas default to 0, result should be 0.0
        $this->assertSame(0.0, $result);
    }
}
