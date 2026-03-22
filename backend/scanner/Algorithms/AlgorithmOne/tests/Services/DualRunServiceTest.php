<?php

declare(strict_types=1);

namespace Proxbet\Scanner\Algorithms\AlgorithmOne\Tests\Services;

use PHPUnit\Framework\TestCase;
use Proxbet\Scanner\Algorithms\AlgorithmOne\Services\DualRunService;
use Proxbet\Scanner\Algorithms\AlgorithmOne\Calculators\ProbabilityCalculator;
use Proxbet\Scanner\Algorithms\AlgorithmOne\Calculators\V2\ProbabilityCalculatorV2;
use Proxbet\Scanner\Algorithms\AlgorithmOne\Filters\LegacyFilter;
use Psr\Log\LoggerInterface;

final class DualRunServiceTest extends TestCase
{
    private function createMockLegacyCalculator(float $probability): ProbabilityCalculator
    {
        $mock = $this->createMock(ProbabilityCalculator::class);
        $mock->method('calculate')->willReturn($probability);
        return $mock;
    }

    private function createMockV2Calculator(array $result): ProbabilityCalculatorV2
    {
        $mock = $this->createMock(ProbabilityCalculatorV2::class);
        $mock->method('calculate')->willReturn($result);
        return $mock;
    }

    private function createMockLegacyFilter(bool $shouldBet, ?string $reason = null): LegacyFilter
    {
        $mock = $this->createMock(LegacyFilter::class);
        $mock->method('shouldBet')->willReturn([
            'bet' => $shouldBet,
            'reason' => $reason,
        ]);
        return $mock;
    }

    public function testRunBothWithMatchingDecisions(): void
    {
        $legacyCalc = $this->createMockLegacyCalculator(0.65);
        $v2Calc = $this->createMockV2Calculator([
            'probability' => 0.68,
            'decision' => ['bet' => true, 'reason' => null],
            'components' => [],
        ]);
        $filter = $this->createMockLegacyFilter(true);

        $service = new DualRunService($legacyCalc, $v2Calc, $filter);

        $result = $service->runBoth(
            ['form_score' => 0.7],
            ['h2h_score' => 0.5],
            ['live_score' => 0.6],
            25
        );

        $this->assertArrayHasKey('legacy', $result);
        $this->assertArrayHasKey('v2', $result);
        $this->assertArrayHasKey('comparison', $result);

        $this->assertSame(0.65, $result['legacy']['probability']);
        $this->assertTrue($result['legacy']['decision']['bet']);

        $this->assertSame(0.68, $result['v2']['probability']);
        $this->assertTrue($result['v2']['decision']['bet']);

        $this->assertTrue($result['comparison']['decision_match']);
        $this->assertEqualsWithDelta(0.03, $result['comparison']['probability_diff'], 0.001);
    }

    public function testRunBothWithDifferentDecisions(): void
    {
        $legacyCalc = $this->createMockLegacyCalculator(0.50);
        $v2Calc = $this->createMockV2Calculator([
            'probability' => 0.65,
            'decision' => ['bet' => true, 'reason' => null],
            'components' => [],
        ]);
        $filter = $this->createMockLegacyFilter(false, 'probability_too_low');

        $service = new DualRunService($legacyCalc, $v2Calc, $filter);

        $result = $service->runBoth([], [], [], 25);

        $this->assertFalse($result['legacy']['decision']['bet']);
        $this->assertTrue($result['v2']['decision']['bet']);
        $this->assertFalse($result['comparison']['decision_match']);
        $this->assertSame('high', $result['comparison']['divergence_level']);
    }

    public function testRunBothWithNoDivergence(): void
    {
        $legacyCalc = $this->createMockLegacyCalculator(0.60);
        $v2Calc = $this->createMockV2Calculator([
            'probability' => 0.62,
            'decision' => ['bet' => true, 'reason' => null],
            'components' => [],
        ]);
        $filter = $this->createMockLegacyFilter(true);

        $service = new DualRunService($legacyCalc, $v2Calc, $filter);

        $result = $service->runBoth([], [], [], 25);

        $this->assertSame('none', $result['comparison']['divergence_level']);
        $this->assertLessThan(0.05, $result['comparison']['probability_diff']);
    }

    public function testRunBothWithLowDivergence(): void
    {
        $legacyCalc = $this->createMockLegacyCalculator(0.60);
        $v2Calc = $this->createMockV2Calculator([
            'probability' => 0.67,
            'decision' => ['bet' => true, 'reason' => null],
            'components' => [],
        ]);
        $filter = $this->createMockLegacyFilter(true);

        $service = new DualRunService($legacyCalc, $v2Calc, $filter);

        $result = $service->runBoth([], [], [], 25);

        $this->assertSame('low', $result['comparison']['divergence_level']);
        $this->assertGreaterThanOrEqual(0.05, $result['comparison']['probability_diff']);
        $this->assertLessThan(0.10, $result['comparison']['probability_diff']);
    }

    public function testRunBothWithMediumDivergence(): void
    {
        $legacyCalc = $this->createMockLegacyCalculator(0.55);
        $v2Calc = $this->createMockV2Calculator([
            'probability' => 0.70,
            'decision' => ['bet' => true, 'reason' => null],
            'components' => [],
        ]);
        $filter = $this->createMockLegacyFilter(true);

        $service = new DualRunService($legacyCalc, $v2Calc, $filter);

        $result = $service->runBoth([], [], [], 25);

        $this->assertSame('medium', $result['comparison']['divergence_level']);
        $this->assertGreaterThanOrEqual(0.10, $result['comparison']['probability_diff']);
        $this->assertLessThan(0.20, $result['comparison']['probability_diff']);
    }

    public function testRunBothWithHighDivergence(): void
    {
        $legacyCalc = $this->createMockLegacyCalculator(0.50);
        $v2Calc = $this->createMockV2Calculator([
            'probability' => 0.75,
            'decision' => ['bet' => true, 'reason' => null],
            'components' => [],
        ]);
        $filter = $this->createMockLegacyFilter(true);

        $service = new DualRunService($legacyCalc, $v2Calc, $filter);

        $result = $service->runBoth([], [], [], 25);

        $this->assertSame('high', $result['comparison']['divergence_level']);
        $this->assertGreaterThanOrEqual(0.20, $result['comparison']['probability_diff']);
    }

    public function testRunBothLogsHighDivergence(): void
    {
        $legacyCalc = $this->createMockLegacyCalculator(0.50);
        $v2Calc = $this->createMockV2Calculator([
            'probability' => 0.75,
            'decision' => ['bet' => true, 'reason' => null],
            'components' => [],
        ]);
        $filter = $this->createMockLegacyFilter(true);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('warning')
            ->with(
                'Algorithm 1 dual-run divergence detected',
                $this->callback(function ($context) {
                    return $context['divergence_level'] === 'high'
                        && isset($context['probability_diff'])
                        && isset($context['legacy'])
                        && isset($context['v2']);
                })
            );

        $service = new DualRunService($legacyCalc, $v2Calc, $filter, $logger);
        $service->runBoth([], [], [], 25);
    }

    public function testRunBothDoesNotLogNoDivergence(): void
    {
        $legacyCalc = $this->createMockLegacyCalculator(0.60);
        $v2Calc = $this->createMockV2Calculator([
            'probability' => 0.62,
            'decision' => ['bet' => true, 'reason' => null],
            'components' => [],
        ]);
        $filter = $this->createMockLegacyFilter(true);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->never())->method('warning');

        $service = new DualRunService($legacyCalc, $v2Calc, $filter, $logger);
        $service->runBoth([], [], [], 25);
    }

    public function testHasSignificantDivergenceWithNone(): void
    {
        $legacyCalc = $this->createMockLegacyCalculator(0.60);
        $v2Calc = $this->createMockV2Calculator([
            'probability' => 0.60,
            'decision' => ['bet' => true, 'reason' => null],
            'components' => [],
        ]);
        $filter = $this->createMockLegacyFilter(true);

        $service = new DualRunService($legacyCalc, $v2Calc, $filter);
        $result = $service->runBoth([], [], [], 25);

        $this->assertFalse($service->hasSignificantDivergence($result['comparison']));
    }

    public function testHasSignificantDivergenceWithLow(): void
    {
        $legacyCalc = $this->createMockLegacyCalculator(0.60);
        $v2Calc = $this->createMockV2Calculator([
            'probability' => 0.67,
            'decision' => ['bet' => true, 'reason' => null],
            'components' => [],
        ]);
        $filter = $this->createMockLegacyFilter(true);

        $service = new DualRunService($legacyCalc, $v2Calc, $filter);
        $result = $service->runBoth([], [], [], 25);

        $this->assertFalse($service->hasSignificantDivergence($result['comparison']));
    }

    public function testHasSignificantDivergenceWithMedium(): void
    {
        $legacyCalc = $this->createMockLegacyCalculator(0.55);
        $v2Calc = $this->createMockV2Calculator([
            'probability' => 0.70,
            'decision' => ['bet' => true, 'reason' => null],
            'components' => [],
        ]);
        $filter = $this->createMockLegacyFilter(true);

        $service = new DualRunService($legacyCalc, $v2Calc, $filter);
        $result = $service->runBoth([], [], [], 25);

        $this->assertTrue($service->hasSignificantDivergence($result['comparison']));
    }

    public function testHasSignificantDivergenceWithHigh(): void
    {
        $legacyCalc = $this->createMockLegacyCalculator(0.50);
        $v2Calc = $this->createMockV2Calculator([
            'probability' => 0.75,
            'decision' => ['bet' => true, 'reason' => null],
            'components' => [],
        ]);
        $filter = $this->createMockLegacyFilter(true);

        $service = new DualRunService($legacyCalc, $v2Calc, $filter);
        $result = $service->runBoth([], [], [], 25);

        $this->assertTrue($service->hasSignificantDivergence($result['comparison']));
    }

    public function testGetDivergenceStats(): void
    {
        $legacyCalc = $this->createMockLegacyCalculator(0.55);
        $v2Calc = $this->createMockV2Calculator([
            'probability' => 0.70,
            'decision' => ['bet' => true, 'reason' => null],
            'components' => [],
        ]);
        $filter = $this->createMockLegacyFilter(true);

        $service = new DualRunService($legacyCalc, $v2Calc, $filter);
        $result = $service->runBoth([], [], [], 25);

        $stats = $service->getDivergenceStats($result['comparison']);

        $this->assertTrue($stats['is_divergent']);
        $this->assertSame('medium', $stats['level']);
        $this->assertEqualsWithDelta(15.0, $stats['probability_diff_percent'], 0.1);
        $this->assertTrue($stats['decisions_match']);
    }

    public function testGetDivergenceStatsWithNoDivergence(): void
    {
        $legacyCalc = $this->createMockLegacyCalculator(0.60);
        $v2Calc = $this->createMockV2Calculator([
            'probability' => 0.62,
            'decision' => ['bet' => true, 'reason' => null],
            'components' => [],
        ]);
        $filter = $this->createMockLegacyFilter(true);

        $service = new DualRunService($legacyCalc, $v2Calc, $filter);
        $result = $service->runBoth([], [], [], 25);

        $stats = $service->getDivergenceStats($result['comparison']);

        $this->assertFalse($stats['is_divergent']);
        $this->assertSame('none', $stats['level']);
        $this->assertLessThan(5.0, $stats['probability_diff_percent']);
        $this->assertTrue($stats['decisions_match']);
    }

    public function testGetDivergenceStatsWithDecisionMismatch(): void
    {
        $legacyCalc = $this->createMockLegacyCalculator(0.50);
        $v2Calc = $this->createMockV2Calculator([
            'probability' => 0.65,
            'decision' => ['bet' => true, 'reason' => null],
            'components' => [],
        ]);
        $filter = $this->createMockLegacyFilter(false, 'probability_too_low');

        $service = new DualRunService($legacyCalc, $v2Calc, $filter);
        $result = $service->runBoth([], [], [], 25);

        $stats = $service->getDivergenceStats($result['comparison']);

        $this->assertTrue($stats['is_divergent']);
        $this->assertSame('high', $stats['level']);
        $this->assertFalse($stats['decisions_match']);
    }

    public function testLegacyComponentsAreIncluded(): void
    {
        $legacyCalc = $this->createMockLegacyCalculator(0.65);
        $v2Calc = $this->createMockV2Calculator([
            'probability' => 0.68,
            'decision' => ['bet' => true, 'reason' => null],
            'components' => ['pdi' => 0.8, 'shot_quality' => 0.7],
        ]);
        $filter = $this->createMockLegacyFilter(true);

        $formData = ['form_score' => 0.7];
        $h2hData = ['h2h_score' => 0.5];
        $liveData = ['live_score' => 0.6];

        $service = new DualRunService($legacyCalc, $v2Calc, $filter);
        $result = $service->runBoth($formData, $h2hData, $liveData, 25);

        $this->assertArrayHasKey('components', $result['legacy']);
        $this->assertSame(0.7, $result['legacy']['components']['form_score']);
        $this->assertSame(0.5, $result['legacy']['components']['h2h_score']);
        $this->assertSame(0.6, $result['legacy']['components']['live_score']);
    }

    public function testV2ComponentsAreIncluded(): void
    {
        $legacyCalc = $this->createMockLegacyCalculator(0.65);
        $v2Calc = $this->createMockV2Calculator([
            'probability' => 0.68,
            'decision' => ['bet' => true, 'reason' => null],
            'components' => [
                'pdi' => 0.8,
                'shot_quality' => 0.7,
                'trend_acceleration' => 0.6,
            ],
        ]);
        $filter = $this->createMockLegacyFilter(true);

        $service = new DualRunService($legacyCalc, $v2Calc, $filter);
        $result = $service->runBoth([], [], [], 25);

        $this->assertArrayHasKey('components', $result['v2']);
        $this->assertSame(0.8, $result['v2']['components']['pdi']);
        $this->assertSame(0.7, $result['v2']['components']['shot_quality']);
        $this->assertSame(0.6, $result['v2']['components']['trend_acceleration']);
    }
}
