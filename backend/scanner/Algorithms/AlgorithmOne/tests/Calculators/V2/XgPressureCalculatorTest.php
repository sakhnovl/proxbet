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

    public function testReturnsZeroWhenXgIsNull(): void
    {
        $liveData = [
            'xg_home' => null,
            'xg_away' => 1.0,
        ];

        $result = $this->calculator->calculate($liveData);

        $this->assertSame(0.0, $result);
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
}
