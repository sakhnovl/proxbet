<?php

declare(strict_types=1);

namespace Proxbet\Scanner\Algorithms\AlgorithmX\Tests\Calculators;

use PHPUnit\Framework\TestCase;
use Proxbet\Scanner\Algorithms\AlgorithmX\Calculators\AisCalculator;

/**
 * Unit tests for AisCalculator.
 */
final class AisCalculatorTest extends TestCase
{
    private AisCalculator $calculator;

    protected function setUp(): void
    {
        $this->calculator = new AisCalculator();
    }

    public function testCalculateTeamAisWithTypicalValues(): void
    {
        $ais = $this->calculator->calculateTeamAis(
            dangerousAttacks: 10,
            shots: 5,
            shotsOnTarget: 3,
            corners: 2
        );

        // Expected: 10*0.4 + 5*0.3 + 3*0.2 + 2*0.1 = 4.0 + 1.5 + 0.6 + 0.2 = 6.3
        $this->assertEqualsWithDelta(6.3, $ais, 0.01);
    }

    public function testCalculateTeamAisWithZeroValues(): void
    {
        $ais = $this->calculator->calculateTeamAis(
            dangerousAttacks: 0,
            shots: 0,
            shotsOnTarget: 0,
            corners: 0
        );

        $this->assertEquals(0.0, $ais);
    }

    public function testCalculateTeamAisWithHighValues(): void
    {
        $ais = $this->calculator->calculateTeamAis(
            dangerousAttacks: 25,
            shots: 12,
            shotsOnTarget: 6,
            corners: 5
        );

        // Expected: 25*0.4 + 12*0.3 + 6*0.2 + 5*0.1 = 10.0 + 3.6 + 1.2 + 0.5 = 15.3
        $this->assertEqualsWithDelta(15.3, $ais, 0.01);
    }

    public function testCalculateWithTypicalMatch(): void
    {
        $liveData = [
            'minute' => 28,
            'dangerous_attacks_home' => 14,
            'dangerous_attacks_away' => 6,
            'shots_home' => 7,
            'shots_away' => 3,
            'shots_on_target_home' => 3,
            'shots_on_target_away' => 1,
            'corners_home' => 4,
            'corners_away' => 1,
        ];

        $result = $this->calculator->calculate($liveData);

        // AIS_home = 14*0.4 + 7*0.3 + 3*0.2 + 4*0.1 = 5.6 + 2.1 + 0.6 + 0.4 = 8.7
        $this->assertEqualsWithDelta(8.7, $result['ais_home'], 0.01);

        // AIS_away = 6*0.4 + 3*0.3 + 1*0.2 + 1*0.1 = 2.4 + 0.9 + 0.2 + 0.1 = 3.6
        $this->assertEqualsWithDelta(3.6, $result['ais_away'], 0.01);

        // AIS_total = 8.7 + 3.6 = 12.3
        $this->assertEqualsWithDelta(12.3, $result['ais_total'], 0.01);

        // AIS_rate = 12.3 / 28 = 0.439
        $this->assertEqualsWithDelta(0.439, $result['ais_rate'], 0.01);
    }

    public function testCalculateWithZeroMinute(): void
    {
        $liveData = [
            'minute' => 0,
            'dangerous_attacks_home' => 5,
            'dangerous_attacks_away' => 3,
            'shots_home' => 2,
            'shots_away' => 1,
            'shots_on_target_home' => 1,
            'shots_on_target_away' => 0,
            'corners_home' => 1,
            'corners_away' => 0,
        ];

        $result = $this->calculator->calculate($liveData);

        // AIS_rate should be 0 when minute is 0 (avoid division by zero)
        $this->assertEquals(0.0, $result['ais_rate']);
    }

    public function testCalculateWithMissingFields(): void
    {
        $liveData = [
            'minute' => 20,
        ];

        $result = $this->calculator->calculate($liveData);

        // All missing fields should default to 0
        $this->assertEquals(0.0, $result['ais_home']);
        $this->assertEquals(0.0, $result['ais_away']);
        $this->assertEquals(0.0, $result['ais_total']);
        $this->assertEquals(0.0, $result['ais_rate']);
    }

    public function testCalculateWithHighActivityMatch(): void
    {
        $liveData = [
            'minute' => 20,
            'dangerous_attacks_home' => 25,
            'dangerous_attacks_away' => 18,
            'shots_home' => 12,
            'shots_away' => 8,
            'shots_on_target_home' => 6,
            'shots_on_target_away' => 4,
            'corners_home' => 5,
            'corners_away' => 3,
        ];

        $result = $this->calculator->calculate($liveData);

        // AIS_home = 25*0.4 + 12*0.3 + 6*0.2 + 5*0.1 = 10.0 + 3.6 + 1.2 + 0.5 = 15.3
        $this->assertEqualsWithDelta(15.3, $result['ais_home'], 0.01);

        // AIS_away = 18*0.4 + 8*0.3 + 4*0.2 + 3*0.1 = 7.2 + 2.4 + 0.8 + 0.3 = 10.7
        $this->assertEqualsWithDelta(10.7, $result['ais_away'], 0.01);

        // AIS_total = 15.3 + 10.7 = 26.0
        $this->assertEqualsWithDelta(26.0, $result['ais_total'], 0.01);

        // AIS_rate = 26.0 / 20 = 1.3
        $this->assertEqualsWithDelta(1.3, $result['ais_rate'], 0.01);
    }
}
