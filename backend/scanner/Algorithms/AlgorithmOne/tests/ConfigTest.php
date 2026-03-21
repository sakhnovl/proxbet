<?php
declare(strict_types=1);

namespace Proxbet\Scanner\Algorithms\AlgorithmOne\Tests;

use PHPUnit\Framework\TestCase;
use Proxbet\Scanner\Algorithms\AlgorithmOne\Config;

final class ConfigTest extends TestCase
{
    protected function tearDown(): void
    {
        unset(
            $_ENV['ALGORITHM1_V2_MIN_PROBABILITY'],
            $_ENV['ALGORITHM1_V2_THRESHOLD_CANDIDATES'],
            $_ENV['ALGORITHM1_V2_TIME_PRESSURE_EXPONENT'],
            $_ENV['ALGORITHM1_V2_TIME_PRESSURE_EARLY_WINDOW_END'],
            $_ENV['ALGORITHM1_V2_TIME_PRESSURE_EARLY_FLOOR']
        );

        parent::tearDown();
    }

    public function testUsesDefaultCalibrationValues(): void
    {
        $this->assertSame(0.55, Config::getV2MinProbability());
        $this->assertSame([0.55, 0.52, 0.5], Config::getV2ThresholdCandidates());
    }

    public function testReadsThresholdsFromEnvironment(): void
    {
        $_ENV['ALGORITHM1_V2_MIN_PROBABILITY'] = '0.52';
        $_ENV['ALGORITHM1_V2_THRESHOLD_CANDIDATES'] = '0.50,0.55,0.52';

        $this->assertSame(0.52, Config::getV2MinProbability());
        $this->assertSame([0.55, 0.52, 0.5], Config::getV2ThresholdCandidates());
    }

    public function testReadsTimePressureCalibrationFromEnvironment(): void
    {
        $_ENV['ALGORITHM1_V2_TIME_PRESSURE_EXPONENT'] = '1.05';
        $_ENV['ALGORITHM1_V2_TIME_PRESSURE_EARLY_WINDOW_END'] = '19';
        $_ENV['ALGORITHM1_V2_TIME_PRESSURE_EARLY_FLOOR'] = '0.28';

        $this->assertSame(1.05, Config::getV2TimePressureCurveExponent());
        $this->assertSame(19, Config::getV2TimePressureEarlyWindowEnd());
        $this->assertSame(0.28, Config::getV2TimePressureEarlyFloorMax());
    }

    public function testReturnsSegmentProfilesForLeagueCategories(): void
    {
        $topTier = Config::getLeagueSegmentProfile(Config::LEAGUE_CATEGORY_TOP_TIER);
        $youth = Config::getLeagueSegmentProfile(Config::LEAGUE_CATEGORY_YOUTH);

        $this->assertSame(0.55, $topTier['probability_threshold']);
        $this->assertSame(1.20, $youth['min_attack_tempo']);
        $this->assertSame(0.75, $youth['xg_weight_multiplier']);
    }
}
