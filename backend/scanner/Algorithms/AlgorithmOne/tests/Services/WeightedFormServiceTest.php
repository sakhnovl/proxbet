<?php

declare(strict_types=1);

namespace Proxbet\Scanner\Algorithms\AlgorithmOne\Tests\Services;

use PHPUnit\Framework\TestCase;
use Proxbet\Scanner\Algorithms\AlgorithmOne\Services\WeightedFormService;
use Proxbet\Statistic\HtMetricsCalculator;

final class WeightedFormServiceTest extends TestCase
{
    private function createMockCalculator(array $returnData): HtMetricsCalculator
    {
        // @phpstan-ignore-next-line PHPUnit mock type is resolved at runtime
        $mock = $this->createMock(HtMetricsCalculator::class);
        $mock->method('calculate')->willReturn($returnData);
        return $mock;
    }

    public function testGetWeightedFormWithValidData(): void
    {
        $calculatorReturn = [
            'metrics' => [],
            'debug' => [
                'algorithm1_v2' => [
                    'form' => [
                        'home' => ['attack' => 0.8, 'defense' => 0.3],
                        'away' => ['attack' => 0.6, 'defense' => 0.4],
                        'weighted_score' => 0.65,
                    ],
                ],
            ],
        ];

        $calculator = $this->createMockCalculator($calculatorReturn);
        $service = new WeightedFormService($calculator);

        $result = $service->getWeightedForm([], 'Home Team', 'Away Team');

        $this->assertTrue($result['has_data']);
        $this->assertNotNull($result['home']);
        $this->assertNotNull($result['away']);
        $this->assertNotNull($result['score']);
        $this->assertSame(0.8, $result['home']['attack']);
        $this->assertSame(0.3, $result['home']['defense']);
        $this->assertSame(0.6, $result['away']['attack']);
        $this->assertSame(0.4, $result['away']['defense']);
        $this->assertSame(0.65, $result['score']);
    }

    public function testGetWeightedFormWithNoData(): void
    {
        $calculatorReturn = [
            'metrics' => [],
            'debug' => [],
        ];

        $calculator = $this->createMockCalculator($calculatorReturn);
        $service = new WeightedFormService($calculator);

        $result = $service->getWeightedForm([], 'Home Team', 'Away Team');

        $this->assertFalse($result['has_data']);
        $this->assertNull($result['home']);
        $this->assertNull($result['away']);
        $this->assertNull($result['score']);
    }

    public function testGetWeightedFormWithIncompleteData(): void
    {
        $calculatorReturn = [
            'metrics' => [],
            'debug' => [
                'algorithm1_v2' => [
                    'form' => [
                        'home' => ['attack' => 0.8], // Missing defense
                        'away' => ['attack' => 0.6, 'defense' => 0.4],
                        'weighted_score' => 0.65,
                    ],
                ],
            ],
        ];

        $calculator = $this->createMockCalculator($calculatorReturn);
        $service = new WeightedFormService($calculator);

        $result = $service->getWeightedForm([], 'Home Team', 'Away Team');

        $this->assertFalse($result['has_data']);
        $this->assertNull($result['home']);
        $this->assertNull($result['away']);
    }

    public function testCalculateScore(): void
    {
        $calculator = $this->createMockCalculator([]);
        $service = new WeightedFormService($calculator);

        $homeMetrics = ['attack' => 1.0, 'defense' => 0.5];
        $awayMetrics = ['attack' => 0.8, 'defense' => 0.6];

        $score = $service->calculateScore($homeMetrics, $awayMetrics);

        // Formula: (1.0*0.6 + 0.6*0.4 + 0.8*0.6 + 0.5*0.4) / 2
        // = (0.6 + 0.24 + 0.48 + 0.2) / 2
        // = 1.52 / 2 = 0.76
        $this->assertEqualsWithDelta(0.76, $score, 0.001);
    }

    public function testCalculateScoreWithZeroValues(): void
    {
        $calculator = $this->createMockCalculator([]);
        $service = new WeightedFormService($calculator);

        $homeMetrics = ['attack' => 0.0, 'defense' => 0.0];
        $awayMetrics = ['attack' => 0.0, 'defense' => 0.0];

        $score = $service->calculateScore($homeMetrics, $awayMetrics);

        $this->assertSame(0.0, $score);
    }

    public function testCalculateScoreWithHighValues(): void
    {
        $calculator = $this->createMockCalculator([]);
        $service = new WeightedFormService($calculator);

        $homeMetrics = ['attack' => 2.0, 'defense' => 1.5];
        $awayMetrics = ['attack' => 1.8, 'defense' => 1.2];

        $score = $service->calculateScore($homeMetrics, $awayMetrics);

        // Formula: (2.0*0.6 + 1.2*0.4 + 1.8*0.6 + 1.5*0.4) / 2
        // = (1.2 + 0.48 + 1.08 + 0.6) / 2
        // = 3.36 / 2 = 1.68
        $this->assertEqualsWithDelta(1.68, $score, 0.001);
    }

    public function testNormalizeScoreInRange(): void
    {
        $calculator = $this->createMockCalculator([]);
        $service = new WeightedFormService($calculator);

        $this->assertSame(0.0, $service->normalizeScore(0.0));
        $this->assertSame(0.5, $service->normalizeScore(1.0));
        $this->assertSame(1.0, $service->normalizeScore(2.0));
    }

    public function testNormalizeScoreClampLower(): void
    {
        $calculator = $this->createMockCalculator([]);
        $service = new WeightedFormService($calculator);

        $this->assertSame(0.0, $service->normalizeScore(-0.5));
        $this->assertSame(0.0, $service->normalizeScore(-1.0));
    }

    public function testNormalizeScoreClampUpper(): void
    {
        $calculator = $this->createMockCalculator([]);
        $service = new WeightedFormService($calculator);

        $this->assertSame(1.0, $service->normalizeScore(2.5));
        $this->assertSame(1.0, $service->normalizeScore(3.0));
        $this->assertSame(1.0, $service->normalizeScore(10.0));
    }

    public function testHasValidDataWithCompleteData(): void
    {
        $calculator = $this->createMockCalculator([]);
        $service = new WeightedFormService($calculator);

        $weightedData = [
            'has_data' => true,
            'home' => ['attack' => 0.8, 'defense' => 0.3],
            'away' => ['attack' => 0.6, 'defense' => 0.4],
            'score' => 0.65,
        ];

        $this->assertTrue($service->hasValidData($weightedData));
    }

    public function testHasValidDataWithMissingFlag(): void
    {
        $calculator = $this->createMockCalculator([]);
        $service = new WeightedFormService($calculator);

        $weightedData = [
            'has_data' => false,
            'home' => ['attack' => 0.8, 'defense' => 0.3],
            'away' => ['attack' => 0.6, 'defense' => 0.4],
            'score' => 0.65,
        ];

        $this->assertFalse($service->hasValidData($weightedData));
    }

    public function testHasValidDataWithNullHome(): void
    {
        $calculator = $this->createMockCalculator([]);
        $service = new WeightedFormService($calculator);

        $weightedData = [
            'has_data' => true,
            'home' => null,
            'away' => ['attack' => 0.6, 'defense' => 0.4],
            'score' => 0.65,
        ];

        $this->assertFalse($service->hasValidData($weightedData));
    }

    public function testHasValidDataWithNullAway(): void
    {
        $calculator = $this->createMockCalculator([]);
        $service = new WeightedFormService($calculator);

        $weightedData = [
            'has_data' => true,
            'home' => ['attack' => 0.8, 'defense' => 0.3],
            'away' => null,
            'score' => 0.65,
        ];

        $this->assertFalse($service->hasValidData($weightedData));
    }

    public function testHasValidDataWithNullScore(): void
    {
        $calculator = $this->createMockCalculator([]);
        $service = new WeightedFormService($calculator);

        $weightedData = [
            'has_data' => true,
            'home' => ['attack' => 0.8, 'defense' => 0.3],
            'away' => ['attack' => 0.6, 'defense' => 0.4],
            'score' => null,
        ];

        $this->assertFalse($service->hasValidData($weightedData));
    }

    public function testHasValidDataWithEmptyArray(): void
    {
        $calculator = $this->createMockCalculator([]);
        $service = new WeightedFormService($calculator);

        $this->assertFalse($service->hasValidData([]));
    }
}
