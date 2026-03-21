<?php
declare(strict_types=1);

namespace Proxbet\Scanner\Algorithms\AlgorithmOne\Tests\Calculators\V2;

use PHPUnit\Framework\TestCase;
use Proxbet\Scanner\Algorithms\AlgorithmOne\Calculators\V2\RedFlagChecker;

final class RedFlagCheckerTest extends TestCase
{
    private RedFlagChecker $checker;

    protected function setUp(): void
    {
        $this->checker = new RedFlagChecker();
    }

    public function testReturnsNullWhenNoFlags(): void
    {
        $liveData = [
            'shots_total' => 10,
            'shots_on_target' => 5,
            'dangerous_attacks_home' => 15,
            'dangerous_attacks_away' => 15,
            'shots_on_target_home' => 3,
            'shots_on_target_away' => 2,
        ];

        $result = $this->checker->check($liveData, 20);

        $this->assertNull($result);
    }

    public function testDetectsLowAccuracy(): void
    {
        $liveData = [
            'shots_total' => 12,
            'shots_on_target' => 2,
        ];

        $result = $this->checker->check($liveData, 20);

        // 2/12 = 0.167 < 0.25
        $this->assertSame('low_accuracy', $result);
    }

    public function testDetectsIneffectivePressureHome(): void
    {
        $liveData = [
            'shots_total' => 10,
            'shots_on_target' => 5,
            'dangerous_attacks_home' => 35,
            'dangerous_attacks_away' => 10,
            'shots_on_target_home' => 1,
            'shots_on_target_away' => 4,
        ];

        $result = $this->checker->check($liveData, 20);

        // Home: 35 attacks > 30, but only 1 shot on target < 2
        $this->assertSame('ineffective_pressure', $result);
    }

    public function testDetectsIneffectivePressureAway(): void
    {
        $liveData = [
            'shots_total' => 10,
            'shots_on_target' => 5,
            'dangerous_attacks_home' => 10,
            'dangerous_attacks_away' => 32,
            'shots_on_target_home' => 4,
            'shots_on_target_away' => 1,
        ];

        $result = $this->checker->check($liveData, 20);

        // Away: 32 attacks > 30, but only 1 shot on target < 2
        $this->assertSame('ineffective_pressure', $result);
    }

    public function testDetectsXgMismatch(): void
    {
        $liveData = [
            'shots_total' => 10,
            'shots_on_target' => 5,
            'dangerous_attacks_home' => 20,
            'dangerous_attacks_away' => 20,
            'shots_on_target_home' => 3,
            'shots_on_target_away' => 2,
            'xg_home' => 1.5,
            'xg_away' => 1.0,
            'ht_hscore' => 0,
            'ht_ascore' => 0,
        ];

        $result = $this->checker->check($liveData, 28);

        // Total xG: 2.5 > 1.2, score 0:0, minute >= 25
        $this->assertSame('xg_mismatch', $result);
    }

    public function testXgMismatchNotDetectedBeforeMinute25(): void
    {
        $liveData = [
            'shots_total' => 10,
            'shots_on_target' => 5,
            'xg_home' => 1.5,
            'xg_away' => 1.0,
            'ht_hscore' => 0,
            'ht_ascore' => 0,
        ];

        $result = $this->checker->check($liveData, 22);

        $this->assertNull($result);
    }

    public function testXgMismatchNotDetectedWhenScoreIsNot0_0(): void
    {
        $liveData = [
            'shots_total' => 10,
            'shots_on_target' => 5,
            'xg_home' => 1.5,
            'xg_away' => 1.0,
            'ht_hscore' => 1,
            'ht_ascore' => 0,
        ];

        $result = $this->checker->check($liveData, 28);

        $this->assertNull($result);
    }

    public function testLowAccuracyTakesPrecedence(): void
    {
        $liveData = [
            'shots_total' => 12,
            'shots_on_target' => 2,
            'dangerous_attacks_home' => 35,
            'dangerous_attacks_away' => 10,
            'shots_on_target_home' => 1,
            'shots_on_target_away' => 1,
        ];

        $result = $this->checker->check($liveData, 20);

        // Both low_accuracy and ineffective_pressure present
        // low_accuracy should be returned first
        $this->assertSame('low_accuracy', $result);
    }
}
