<?php

declare(strict_types=1);

namespace Proxbet\Scanner\Algorithms\AlgorithmOne\Tests\Calculators;

use PHPUnit\Framework\TestCase;
use Proxbet\Scanner\Algorithms\AlgorithmOne\Calculators\FormScoreCalculator;

final class FormScoreCalculatorTest extends TestCase
{
    private FormScoreCalculator $calculator;

    protected function setUp(): void
    {
        $this->calculator = new FormScoreCalculator();
    }

    public function testReturnsZeroWhenNoData(): void
    {
        $formData = [
            'home_goals' => 0,
            'away_goals' => 0,
            'has_data' => false,
        ];

        $result = $this->calculator->calculate($formData);

        $this->assertSame(0.0, $result);
    }

    public function testCalculatesLegacyFormula(): void
    {
        $formData = [
            'home_goals' => 4,
            'away_goals' => 2,
            'has_data' => true,
        ];

        // Expected: (4/5 + 2/5) / 2 = (0.8 + 0.4) / 2 = 0.6
        $result = $this->calculator->calculate($formData);

        $this->assertEqualsWithDelta(0.6, $result, 0.0001);
    }

    public function testCalculatesWithHighGoals(): void
    {
        $formData = [
            'home_goals' => 5,
            'away_goals' => 5,
            'has_data' => true,
        ];

        // Expected: (5/5 + 5/5) / 2 = (1.0 + 1.0) / 2 = 1.0
        $result = $this->calculator->calculate($formData);

        $this->assertSame(1.0, $result);
    }

    public function testCalculatesWithLowGoals(): void
    {
        $formData = [
            'home_goals' => 1,
            'away_goals' => 0,
            'has_data' => true,
        ];

        // Expected: (1/5 + 0/5) / 2 = (0.2 + 0.0) / 2 = 0.1
        $result = $this->calculator->calculate($formData);

        $this->assertSame(0.1, $result);
    }

    public function testUsesWeightedScoreWhenAvailable(): void
    {
        $formData = [
            'home_goals' => 4,
            'away_goals' => 2,
            'has_data' => true,
            'weighted' => [
                'score' => 0.75,
                'home' => ['attack' => 0.8, 'defense' => 0.3],
                'away' => ['attack' => 0.6, 'defense' => 0.4],
            ],
        ];

        $result = $this->calculator->calculate($formData);

        $this->assertSame(0.75, $result);
    }

    public function testClampsWeightedScoreAboveOne(): void
    {
        $formData = [
            'home_goals' => 5,
            'away_goals' => 5,
            'has_data' => true,
            'weighted' => [
                'score' => 1.5, // Above 1.0
            ],
        ];

        $result = $this->calculator->calculate($formData);

        $this->assertSame(1.0, $result);
    }

    public function testClampsWeightedScoreBelowZero(): void
    {
        $formData = [
            'home_goals' => 0,
            'away_goals' => 0,
            'has_data' => true,
            'weighted' => [
                'score' => -0.5, // Below 0.0
            ],
        ];

        $result = $this->calculator->calculate($formData);

        $this->assertSame(0.0, $result);
    }

    public function testFallsBackToLegacyWhenWeightedScoreMissing(): void
    {
        $formData = [
            'home_goals' => 3,
            'away_goals' => 3,
            'has_data' => true,
            'weighted' => [
                'home' => ['attack' => 0.8, 'defense' => 0.3],
                'away' => ['attack' => 0.6, 'defense' => 0.4],
                // No 'score' key
            ],
        ];

        // Expected: (3/5 + 3/5) / 2 = 0.6
        $result = $this->calculator->calculate($formData);

        $this->assertSame(0.6, $result);
    }
}
