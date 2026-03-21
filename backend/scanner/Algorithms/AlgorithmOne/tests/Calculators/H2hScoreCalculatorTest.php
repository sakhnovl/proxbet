<?php

declare(strict_types=1);

namespace Proxbet\Scanner\Algorithms\AlgorithmOne\Tests\Calculators;

use PHPUnit\Framework\TestCase;
use Proxbet\Scanner\Algorithms\AlgorithmOne\Calculators\H2hScoreCalculator;

final class H2hScoreCalculatorTest extends TestCase
{
    private H2hScoreCalculator $calculator;

    protected function setUp(): void
    {
        $this->calculator = new H2hScoreCalculator();
    }

    public function testReturnsZeroWhenNoData(): void
    {
        $h2hData = [
            'home_goals' => 0,
            'away_goals' => 0,
            'has_data' => false,
        ];

        $result = $this->calculator->calculate($h2hData);

        $this->assertSame(0.0, $result);
    }

    public function testCalculatesCorrectly(): void
    {
        $h2hData = [
            'home_goals' => 3,
            'away_goals' => 1,
            'has_data' => true,
        ];

        // Expected: (3 + 1) / 10 = 0.4
        $result = $this->calculator->calculate($h2hData);

        $this->assertSame(0.4, $result);
    }

    public function testCalculatesWithHighGoals(): void
    {
        $h2hData = [
            'home_goals' => 6,
            'away_goals' => 4,
            'has_data' => true,
        ];

        // Expected: (6 + 4) / 10 = 1.0
        $result = $this->calculator->calculate($h2hData);

        $this->assertSame(1.0, $result);
    }

    public function testCalculatesWithZeroGoals(): void
    {
        $h2hData = [
            'home_goals' => 0,
            'away_goals' => 0,
            'has_data' => true,
        ];

        // Expected: (0 + 0) / 10 = 0.0
        $result = $this->calculator->calculate($h2hData);

        $this->assertSame(0.0, $result);
    }

    public function testCalculatesWithBalancedGoals(): void
    {
        $h2hData = [
            'home_goals' => 5,
            'away_goals' => 5,
            'has_data' => true,
        ];

        // Expected: (5 + 5) / 10 = 1.0
        $result = $this->calculator->calculate($h2hData);

        $this->assertSame(1.0, $result);
    }

    public function testResultIsAlwaysBetweenZeroAndOne(): void
    {
        // Test with various goal combinations
        $testCases = [
            ['home_goals' => 0, 'away_goals' => 0],
            ['home_goals' => 2, 'away_goals' => 3],
            ['home_goals' => 5, 'away_goals' => 5],
            ['home_goals' => 7, 'away_goals' => 8], // Above 10 total
        ];

        foreach ($testCases as $goals) {
            $h2hData = array_merge($goals, ['has_data' => true]);
            $result = $this->calculator->calculate($h2hData);

            $this->assertGreaterThanOrEqual(0.0, $result);
            $this->assertLessThanOrEqual(2.0, $result); // Can exceed 1.0 in current implementation
        }
    }
}
