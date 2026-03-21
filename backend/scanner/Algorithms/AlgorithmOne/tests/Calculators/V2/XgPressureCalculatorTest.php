<?php
declare(strict_types=1);

namespace Proxbet\Scanner\Algorithms\AlgorithmOne\Tests\Calculators\V2;

use PHPUnit\Framework\TestCase;
use Proxbet\Scanner\Algorithms\AlgorithmOne\Calculators\V2\XgPressureCalculator;

final class XgPressureCalculatorTest extends TestCase
{
    private XgPressureCalculator $calculator;

    protected function setUp(): void
    {
        $this->calculator = new XgPressureCalculator();
    }

    public function testFallsBackWhenXgIsNull(): void
    {
        $liveData = [
            'xg_home' => null,
            'xg_away' => 1.0,
            'shots_total' => 9,
            'shots_on_target' => 4,
            'dangerous_attacks' => 32,
            'corners' => 3,
        ];

        $result = $this->calculator->calculate($liveData);

        $this->assertGreaterThan(0.0, $result);
    }

    public function testReturnsZeroWhenXgIsMissing(): void
    {
        $liveData = [];

        $result = $this->calculator->calculate($liveData);

        $this->assertSame(0.0, $result);
    }

    public function testCalculatesNormalizedXg(): void
    {
        $liveData = [
            'xg_home' => 0.8,
            'xg_away' => 0.7,
        ];

        $result = $this->calculator->calculate($liveData);

        // Total: 1.5, normalized: 1.5/1.5 = 1.0
        $this->assertSame(1.0, $result);
    }

    public function testClampsToMaximum1(): void
    {
        $liveData = [
            'xg_home' => 2.0,
            'xg_away' => 1.5,
        ];

        $result = $this->calculator->calculate($liveData);

        // Total: 3.5, normalized: 3.5/1.5 = 2.33, clamped to 1.0
        $this->assertSame(1.0, $result);
    }

    public function testLowXgScenario(): void
    {
        $liveData = [
            'xg_home' => 0.3,
            'xg_away' => 0.2,
        ];

        $result = $this->calculator->calculate($liveData);

        // Total: 0.5, normalized: 0.5/1.5 = 0.333
        $this->assertEqualsWithDelta(0.333, $result, 0.01);
    }

    public function testMediumXgScenario(): void
    {
        $liveData = [
            'xg_home' => 0.6,
            'xg_away' => 0.6,
        ];

        $result = $this->calculator->calculate($liveData);

        // Total: 1.2, normalized: 1.2/1.5 = 0.8
        $this->assertEqualsWithDelta(0.8, $result, 0.0001);
    }

    public function testBuildsFallbackFromLiveSignalsWhenXgMissing(): void
    {
        $liveData = [
            'shots_total' => 11,
            'shots_on_target' => 5,
            'dangerous_attacks' => 40,
            'corners' => 4,
            'trend_dangerous_attacks_delta' => 12,
            'trend_shots_total_delta' => 6,
            'trend_shots_on_target_delta' => 2,
        ];

        $result = $this->calculator->calculate($liveData);

        $this->assertGreaterThan(0.45, $result);
        $this->assertLessThan(1.0, $result);
    }
}
