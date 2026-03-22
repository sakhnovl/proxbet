<?php

declare(strict_types=1);

namespace Proxbet\Scanner\Algorithms\AlgorithmX\Calculators;

use Proxbet\Scanner\Algorithms\AlgorithmX\Config;

/**
 * AIS (Attack Intensity Score) Calculator.
 * 
 * Calculates the attack intensity score for teams based on live statistics:
 * - Dangerous attacks (weight: 0.4)
 * - Shots (weight: 0.3)
 * - Shots on target (weight: 0.2)
 * - Corners (weight: 0.1)
 * 
 * Formula: AIS = (dangerous_attacks × 0.4) + (shots × 0.3) + (shots_on_target × 0.2) + (corners × 0.1)
 */
final class AisCalculator
{
    /**
     * Calculate AIS for a single team.
     * 
     * @param int $dangerousAttacks Number of dangerous attacks
     * @param int $shots Total shots (on target + off target)
     * @param int $shotsOnTarget Shots on target
     * @param int $corners Corner kicks
     * @return float Attack Intensity Score
     */
    public function calculateTeamAis(
        int $dangerousAttacks,
        int $shots,
        int $shotsOnTarget,
        int $corners
    ): float {
        return ($dangerousAttacks * Config::WEIGHT_DANGEROUS_ATTACKS)
             + ($shots * Config::WEIGHT_SHOTS)
             + ($shotsOnTarget * Config::WEIGHT_SHOTS_ON_TARGET)
             + ($corners * Config::WEIGHT_CORNERS);
    }

    /**
     * Calculate total AIS for the match.
     * 
     * @param array<string,mixed> $liveData Live match data containing statistics for both teams
     * @return array{
     *   ais_home: float,
     *   ais_away: float,
     *   ais_total: float,
     *   ais_rate: float
     * }
     */
    public function calculate(array $liveData): array
    {
        $minute = (int) ($liveData['minute'] ?? 0);
        
        // Calculate AIS for home team
        $aisHome = $this->calculateTeamAis(
            (int) ($liveData['dangerous_attacks_home'] ?? 0),
            (int) ($liveData['shots_home'] ?? 0),
            (int) ($liveData['shots_on_target_home'] ?? 0),
            (int) ($liveData['corners_home'] ?? 0)
        );
        
        // Calculate AIS for away team
        $aisAway = $this->calculateTeamAis(
            (int) ($liveData['dangerous_attacks_away'] ?? 0),
            (int) ($liveData['shots_away'] ?? 0),
            (int) ($liveData['shots_on_target_away'] ?? 0),
            (int) ($liveData['corners_away'] ?? 0)
        );
        
        // Calculate totals
        $aisTotal = $aisHome + $aisAway;
        
        // Calculate rate (AIS per minute) - avoid division by zero
        $aisRate = $minute > 0 ? $aisTotal / $minute : 0.0;
        
        return [
            'ais_home' => $aisHome,
            'ais_away' => $aisAway,
            'ais_total' => $aisTotal,
            'ais_rate' => $aisRate,
        ];
    }
}
