<?php
declare(strict_types=1);

namespace Proxbet\Scanner\Algorithms\AlgorithmOne\Tests\Calculators\V2;

use PHPUnit\Framework\TestCase;
use Proxbet\Scanner\Algorithms\AlgorithmOne\Calculators\V2\ShotQualityCalculator;

final class ShotQualityCalculatorTest extends TestCase
{
    private ShotQualityCalculator $calculator;

    protected function setUp(): void
    {
        $this->calculator = new ShotQualityCalculator();
    }

    public function testReturnsZeroWhenNoShots(): void
    {
        $liveData = [
            'shots_total' => 0,
            'shots_on_target' => 0,
        ];

        $result = $this->calculator->calculate($liveData);

        $this->assertSame(0.0, $result);
    }

    public function testCalculatesWithXgData(): void
    {
        $liveData = [
            'shots_total' => 10,
            'shots_on_target' => 6,
            'xg_home' => 1.5,
            'xg_away' => 1.5,
        ];

        $result = $this->calculator->calculate($liveData);

        // Accuracy: 6/10 = 0.6
        // xG per shot: 3.0/10 = 0.3
        // Quality: min(0.3/0.33, 1.0) = 0.909
        // Result: 0.909 * 0.7 + 0.6 * 0.3 = 0.636 + 0.18 = 0.816
        $this->assertEqualsWithDelta(0.816, $result, 0.01);
    }

    public function testFallbackToLiveSignalsWithoutXg(): void
    {
        $liveData = [
            'shots_total' => 10,
            'shots_on_target' => 6,
            'corners' => 3,
            'trend_shots_on_target_delta' => 2,
        ];

        $result = $this->calculator->calculate($liveData);

        $this->assertGreaterThan(0.6, $result);
        $this->assertLessThanOrEqual(1.0, $result);
    }

    public function testHighQualityWithHighXg(): void
    {
        $liveData = [
            'shots_total' => 8,
            'shots_on_target' => 7,
            'xg_home' => 2.0,
            'xg_away' => 1.5,
        ];

        $result = $this->calculator->calculate($liveData);

        // Accuracy: 7/8 = 0.875
        // xG per shot: 3.5/8 = 0.4375
        // Quality: min(0.4375/0.33, 1.0) = 1.0 (capped)
        // Result: 1.0 * 0.7 + 0.875 * 0.3 = 0.7 + 0.2625 = 0.9625
        $this->assertEqualsWithDelta(0.9625, $result, 0.01);
    }

    public function testLowQualityWithLowAccuracy(): void
    {
        $liveData = [
            'shots_total' => 12,
            'shots_on_target' => 2,
            'xg_home' => 0.5,
            'xg_away' => 0.4,
        ];

        $result = $this->calculator->calculate($liveData);

        // Accuracy: 2/12 = 0.167
        // xG per shot: 0.9/12 = 0.075
        // Quality: min(0.075/0.33, 1.0) = 0.227
        // Result: 0.227 * 0.7 + 0.167 * 0.3 = 0.159 + 0.05 = 0.209
        $this->assertEqualsWithDelta(0.209, $result, 0.01);
    }

    public function testHandlesNullXgValues(): void
    {
        $liveData = [
            'shots_total' => 8,
            'shots_on_target' => 4,
            'xg_home' => null,
            'xg_away' => 1.0,
            'corners' => 2,
        ];

        $result = $this->calculator->calculate($liveData);

        $this->assertGreaterThan(0.5, $result);
    }
}
