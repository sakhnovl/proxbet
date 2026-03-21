<?php
declare(strict_types=1);

namespace Proxbet\Scanner\Algorithms\AlgorithmOne\Tests\Calculators\V2;

use PHPUnit\Framework\TestCase;
use Proxbet\Scanner\Algorithms\AlgorithmOne\Calculators\V2\TimePressureCalculator;

final class TimePressureCalculatorTest extends TestCase
{
    private TimePressureCalculator $calculator;

    protected function setUp(): void
    {
        $this->calculator = new TimePressureCalculator();
    }

    public function testReturnsZeroForMinuteLessThan15(): void
    {
        $this->assertSame(0.0, $this->calculator->calculate(10));
        $this->assertSame(0.0, $this->calculator->calculate(14));
        $this->assertSame(0.0, $this->calculator->calculate(0));
    }

    public function testReturnsZeroForMinuteGreaterThan30(): void
    {
        $this->assertSame(0.0, $this->calculator->calculate(31));
        $this->assertSame(0.0, $this->calculator->calculate(45));
    }

    public function testReturnsZeroAtMinute15(): void
    {
        $result = $this->calculator->calculate(15);
        
        // (15-15)/15 = 0, 0^1.5 = 0
        $this->assertSame(0.0, $result);
    }

    public function testReturnsOneAtMinute30(): void
    {
        $result = $this->calculator->calculate(30);
        
        // (30-15)/15 = 1, 1^1.5 = 1
        $this->assertSame(1.0, $result);
    }

    public function testNonLinearGrowthAtMinute20(): void
    {
        $result = $this->calculator->calculate(20);

        $this->assertEqualsWithDelta(0.32, $result, 0.01);
    }

    public function testNonLinearGrowthAtMinute25(): void
    {
        $result = $this->calculator->calculate(25);

        $this->assertEqualsWithDelta(0.63, $result, 0.02);
    }

    public function testNonLinearGrowthAtMinute28(): void
    {
        $result = $this->calculator->calculate(28);

        $this->assertEqualsWithDelta(0.85, $result, 0.02);
    }

    public function testEarlyWindowIsNoLongerTooFlat(): void
    {
        $result16 = $this->calculator->calculate(16);
        $result18 = $this->calculator->calculate(18);
        $result20 = $this->calculator->calculate(20);

        $this->assertGreaterThan(0.0, $result16);
        $this->assertGreaterThan($result16, $result18);
        $this->assertGreaterThan($result18, $result20);
    }
}
