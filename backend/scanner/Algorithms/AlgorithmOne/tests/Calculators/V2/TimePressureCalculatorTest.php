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
        
        // (20-15)/15 = 0.333, 0.333^1.5 = 0.192
        $this->assertEqualsWithDelta(0.192, $result, 0.01);
    }

    public function testNonLinearGrowthAtMinute25(): void
    {
        $result = $this->calculator->calculate(25);
        
        // (25-15)/15 = 0.667, 0.667^1.5 = 0.544
        $this->assertEqualsWithDelta(0.544, $result, 0.01);
    }

    public function testNonLinearGrowthAtMinute28(): void
    {
        $result = $this->calculator->calculate(28);
        
        // (28-15)/15 = 0.867, 0.867^1.5 = 0.807
        $this->assertEqualsWithDelta(0.807, $result, 0.01);
    }

    public function testGrowthIsNonLinear(): void
    {
        $result20 = $this->calculator->calculate(20);
        $result25 = $this->calculator->calculate(25);
        
        // Non-linear growth means the increase from 20 to 25
        // should be different than linear progression
        $linearIncrease = 0.333; // (25-20)/(30-15)
        $actualIncrease = $result25 - $result20;
        
        // Actual increase should be greater due to power function
        $this->assertGreaterThan($linearIncrease, $actualIncrease);
    }
}
