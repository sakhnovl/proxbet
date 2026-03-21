<?php
declare(strict_types=1);

namespace Proxbet\Scanner\Algorithms\AlgorithmOne\Calculators\V2;

use Proxbet\Scanner\Algorithms\AlgorithmOne\Config;

/**
 * Calculate time pressure with non-linear growth
 */
final class TimePressureCalculator
{
    /**
     * Calculate time pressure
     * Returns 0.0 for minute < 15 or > 30
     * Non-linear growth from 15 to 30
     * 
     * @param int $minute
     * @return float Score from 0.0 to 1.0
     */
    public function calculate(int $minute): float
    {
        if ($minute < Config::MIN_MINUTE || $minute > Config::MAX_MINUTE) {
            return 0.0;
        }

        $normalized = ($minute - Config::MIN_MINUTE) / (Config::MAX_MINUTE - Config::MIN_MINUTE);
        $curve = pow($normalized, Config::getV2TimePressureCurveExponent());
        $earlyWindowEnd = Config::getV2TimePressureEarlyWindowEnd();

        if ($minute <= $earlyWindowEnd) {
            $earlyProgress = ($minute - Config::MIN_MINUTE) / max(1, ($earlyWindowEnd - Config::MIN_MINUTE));
            $earlyFloor = Config::getV2TimePressureEarlyFloorMax() * $earlyProgress;

            return min(1.0, max($curve, $earlyFloor));
        }

        return min(1.0, $curve);
    }
}
