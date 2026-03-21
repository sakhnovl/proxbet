<?php
declare(strict_types=1);

namespace Proxbet\Scanner\Algorithms\AlgorithmOne\Tests\Calculators\V2;

use PHPUnit\Framework\TestCase;
use Proxbet\Scanner\Algorithms\AlgorithmOne\Calculators\V2\PdiCalculator;

final class PdiCalculatorTest extends TestCase
{
    private PdiCalculator $calculator;

    protected function setUp(): void
    {
        $this->calculator = new PdiCalculator();
    }

    public function testReturnsZeroWhenAttacksLessThan20(): void
    {
        $liveData = [
            'dangerous_attacks_home' => 8,
            'dangerous_attacks_away' => 10,
        ];

        $result = $this->calculator->calculate($liveData);

        $this->assertSame(0.0, $result);
    }

    public function testHighScoreForBalancedGame(): void
    {
        $liveData = [
            'dangerous_attacks_home' => 20,
            'dangerous_attacks_away' => 20,
        ];

        $result = $this->calculator->calculate($liveData);

        // Perfect balance (1.0) * intensity (40/40 = 1.0) = 1.0
        $this->assertSame(1.0, $result);
    }

    public function testMediumScoreForSlightImbalance(): void
    {
        $liveData = [
            'dangerous_attacks_home' => 25,
            'dangerous_attacks_away' => 15,
        ];

        $result = $this->calculator->calculate($liveData);

        // Balance: 1 - |25-15|/40 = 1 - 0.25 = 0.75
        // Intensity: 40/40 = 1.0
        // Result: 0.75 * 1.0 = 0.75
        $this->assertSame(0.75, $result);
    }

    public function testLowScoreForHighImbalance(): void
    {
        $liveData = [
            'dangerous_attacks_home' => 30,
            'dangerous_attacks_away' => 10,
        ];

        $result = $this->calculator->calculate($liveData);

        // Balance: 1 - |30-10|/40 = 1 - 0.5 = 0.5
        // Intensity: 40/40 = 1.0
        // Result: 0.5 * 1.0 = 0.5
        $this->assertSame(0.5, $result);
    }

    public function testIntensityScaling(): void
    {
        $liveData = [
            'dangerous_attacks_home' => 10,
            'dangerous_attacks_away' => 10,
        ];

        $result = $this->calculator->calculate($liveData);

        // Balance: 1.0 (perfect)
        // Intensity: 20/40 = 0.5
        // Result: 1.0 * 0.5 = 0.5
        $this->assertSame(0.5, $result);
    }

    public function testIntensityCappedAt1(): void
    {
        $liveData = [
            'dangerous_attacks_home' => 30,
            'dangerous_attacks_away' => 30,
        ];

        $result = $this->calculator->calculate($liveData);

        // Balance: 1.0
        // Intensity: min(60/40, 1.0) = 1.0
        // Result: 1.0 * 1.0 = 1.0
        $this->assertSame(1.0, $result);
    }

    public function testHandlesMissingData(): void
    {
        $liveData = [];

        $result = $this->calculator->calculate($liveData);

        $this->assertSame(0.0, $result);
    }
}
