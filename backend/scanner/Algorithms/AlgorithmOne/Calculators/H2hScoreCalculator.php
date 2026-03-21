<?php

declare(strict_types=1);

namespace Proxbet\Scanner\Algorithms\AlgorithmOne\Calculators;

/**
 * Calculate H2H score based on last 5 head-to-head matches.
 */
final class H2hScoreCalculator
{
    /**
     * Calculate H2H score based on last 5 head-to-head matches.
     * 
     * @param array{home_goals:int,away_goals:int,has_data:bool} $h2hData
     * @return float Score from 0.0 to 1.0
     */
    public function calculate(array $h2hData): float
    {
        if (!$h2hData['has_data']) {
            return 0.0;
        }

        return ($h2hData['home_goals'] + $h2hData['away_goals']) / 10.0;
    }
}
