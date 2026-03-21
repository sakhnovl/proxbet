<?php
declare(strict_types=1);

namespace Proxbet\Scanner\Algorithms\AlgorithmOne\Tests\Calculators\V2;

use PHPUnit\Framework\TestCase;
use Proxbet\Scanner\Algorithms\AlgorithmOne\Calculators\V2\LeagueFactorCalculator;

final class LeagueFactorCalculatorTest extends TestCase
{
    private LeagueFactorCalculator $calculator;

    protected function setUp(): void
    {
        $this->calculator = new LeagueFactorCalculator();
    }

    public function testReturnsOneWhenTableAvgIsNull(): void
    {
        $liveData = [
            'table_avg' => null,
        ];

        $result = $this->calculator->calculate($liveData);

        $this->assertSame(1.0, $result);
    }

    public function testReturnsOneWhenTableAvgIsMissing(): void
    {
        $liveData = [];

        $result = $this->calculator->calculate($liveData);

        $this->assertSame(1.0, $result);
    }

    public function testCalculatesFactorForAverageLeague(): void
    {
        $liveData = [
            'table_avg' => 2.5,
        ];

        $result = $this->calculator->calculate($liveData);

        // 2.5 / 2.5 = 1.0
        $this->assertSame(1.0, $result);
    }

    public function testCalculatesFactorForHighScoringLeague(): void
    {
        $liveData = [
            'table_avg' => 3.2,
        ];

        $result = $this->calculator->calculate($liveData);

        // 3.2 / 2.5 = 1.28
        $this->assertEqualsWithDelta(1.28, $result, 0.01);
    }

    public function testCalculatesFactorForLowScoringLeague(): void
    {
        $liveData = [
            'table_avg' => 2.0,
        ];

        $result = $this->calculator->calculate($liveData);

        // 2.0 / 2.5 = 0.8
        $this->assertSame(0.8, $result);
    }

    public function testClampsToMinimum0_7(): void
    {
        $liveData = [
            'table_avg' => 1.0,
        ];

        $result = $this->calculator->calculate($liveData);

        // 1.0 / 2.5 = 0.4, but clamped to 0.7
        $this->assertSame(0.7, $result);
    }

    public function testClampsToMaximum1_3(): void
    {
        $liveData = [
            'table_avg' => 4.0,
        ];

        $result = $this->calculator->calculate($liveData);

        // 4.0 / 2.5 = 1.6, but clamped to 1.3
        $this->assertSame(1.3, $result);
    }

    public function testHandlesVeryLowTableAvg(): void
    {
        $liveData = [
            'table_avg' => 0.5,
        ];

        $result = $this->calculator->calculate($liveData);

        // 0.5 / 2.5 = 0.2, clamped to 0.7
        $this->assertSame(0.7, $result);
    }

    public function testHandlesVeryHighTableAvg(): void
    {
        $liveData = [
            'table_avg' => 5.0,
        ];

        $result = $this->calculator->calculate($liveData);

        // 5.0 / 2.5 = 2.0, clamped to 1.3
        $this->assertSame(1.3, $result);
    }
}
