<?php

declare(strict_types=1);

namespace Proxbet\Scanner\Algorithms\AlgorithmX\Filters;

use Proxbet\Scanner\Algorithms\AlgorithmX\Config;

/**
 * Decision Filter for AlgorithmX.
 * 
 * Determines whether to place a bet based on calculated probability
 * and additional match conditions.
 * 
 * Decision logic:
 * - High probability (≥60%): Bet with confidence
 * - Low probability (<20%): No bet
 * - Medium probability (20-60%): Additional checks required
 */
final class DecisionFilter
{
    /**
     * Minimum minute to consider betting (need sufficient data).
     */
    private const MIN_MINUTE_FOR_BET = 10;
    
    /**
     * Minimum AIS rate to consider match active enough.
     */
    private const MIN_AIS_RATE_FOR_BET = 0.8;
    
    /**
     * Maximum score difference to consider betting (avoid blowouts).
     */
    private const MAX_SCORE_DIFF_FOR_BET = 2;

    /**
     * Determine if a bet should be placed based on probability and match data.
     * 
     * @param float $probability Calculated probability (0.0-1.0)
     * @param array<string,mixed> $liveData Live match data
     * @param array<string,mixed> $debug Debug information from calculation
     * @return array{bet: bool, reason: string}
     */
    public function shouldBet(float $probability, array $liveData, array $debug): array
    {
        $thresholds = Config::getDecisionThresholds();
        $minute = (int) ($liveData['minute'] ?? 0);
        $scoreHome = (int) ($liveData['score_home'] ?? 0);
        $scoreAway = (int) ($liveData['score_away'] ?? 0);
        $scoreDiff = abs($scoreHome - $scoreAway);
        $aisRate = (float) ($debug['ais_rate'] ?? 0.0);
        
        // High probability - strong bet signal
        if ($probability >= $thresholds['high']) {
            return $this->evaluateHighProbability($probability, $minute, $scoreDiff, $aisRate);
        }
        
        // Low probability - clear no bet
        if ($probability < $thresholds['low']) {
            return $this->evaluateLowProbability($probability, $aisRate);
        }
        
        // Medium probability - requires additional analysis
        return $this->evaluateMediumProbability($probability, $minute, $scoreDiff, $aisRate, $liveData);
    }

    /**
     * Evaluate high probability scenario (≥60%).
     * 
     * @param float $probability
     * @param int $minute
     * @param int $scoreDiff
     * @param float $aisRate
     * @return array{bet: bool, reason: string}
     */
    private function evaluateHighProbability(
        float $probability,
        int $minute,
        int $scoreDiff,
        float $aisRate
    ): array {
        $probPercent = round($probability * 100, 1);
        
        // Check if match is too one-sided (blowout)
        if ($scoreDiff > self::MAX_SCORE_DIFF_FOR_BET) {
            return [
                'bet' => false,
                'reason' => "High probability ({$probPercent}%) but score difference too large ({$scoreDiff} goals). Avoiding blowout scenario.",
            ];
        }
        
        // Check if we have enough data
        if ($minute < self::MIN_MINUTE_FOR_BET) {
            return [
                'bet' => false,
                'reason' => "High probability ({$probPercent}%) but insufficient data (minute {$minute}). Need at least " . self::MIN_MINUTE_FOR_BET . " minutes.",
            ];
        }
        
        // All checks passed - strong bet signal
        return [
            'bet' => true,
            'reason' => "High goal probability ({$probPercent}%). Strong attacking intensity (AIS rate: " . round($aisRate, 2) . "). Recommended bet.",
        ];
    }

    /**
     * Evaluate low probability scenario (<20%).
     * 
     * @param float $probability
     * @param float $aisRate
     * @return array{bet: bool, reason: string}
     */
    private function evaluateLowProbability(float $probability, float $aisRate): array
    {
        $probPercent = round($probability * 100, 1);
        
        return [
            'bet' => false,
            'reason' => "Low goal probability ({$probPercent}%). Match lacks attacking intensity (AIS rate: " . round($aisRate, 2) . "). No bet recommended.",
        ];
    }

    /**
     * Evaluate medium probability scenario (20-60%).
     * 
     * Requires additional checks to make decision.
     * 
     * @param float $probability
     * @param int $minute
     * @param int $scoreDiff
     * @param float $aisRate
     * @param array<string,mixed> $liveData
     * @return array{bet: bool, reason: string}
     */
    private function evaluateMediumProbability(
        float $probability,
        int $minute,
        int $scoreDiff,
        float $aisRate,
        array $liveData
    ): array {
        $probPercent = round($probability * 100, 1);
        
        // Check 1: Sufficient data collected
        if ($minute < self::MIN_MINUTE_FOR_BET) {
            return [
                'bet' => false,
                'reason' => "Medium probability ({$probPercent}%) but insufficient data (minute {$minute}). Need at least " . self::MIN_MINUTE_FOR_BET . " minutes.",
            ];
        }
        
        // Check 2: Match activity level
        if ($aisRate < self::MIN_AIS_RATE_FOR_BET) {
            return [
                'bet' => false,
                'reason' => "Medium probability ({$probPercent}%) but low match activity (AIS rate: " . round($aisRate, 2) . "). Match too passive.",
            ];
        }
        
        // Check 3: Score difference not too large
        if ($scoreDiff > self::MAX_SCORE_DIFF_FOR_BET) {
            return [
                'bet' => false,
                'reason' => "Medium probability ({$probPercent}%) but score difference too large ({$scoreDiff} goals). Risky scenario.",
            ];
        }
        
        // Check 4: Evaluate attacking balance
        $dangerousAttacksHome = (int) ($liveData['dangerous_attacks_home'] ?? 0);
        $dangerousAttacksAway = (int) ($liveData['dangerous_attacks_away'] ?? 0);
        $totalDangerousAttacks = $dangerousAttacksHome + $dangerousAttacksAway;
        
        if ($totalDangerousAttacks < 5) {
            return [
                'bet' => false,
                'reason' => "Medium probability ({$probPercent}%) but very few dangerous attacks ({$totalDangerousAttacks}). Insufficient offensive pressure.",
            ];
        }
        
        // Check 5: Time remaining consideration
        $timeRemaining = 45 - $minute;
        if ($timeRemaining < 5) {
            return [
                'bet' => false,
                'reason' => "Medium probability ({$probPercent}%) but too little time remaining ({$timeRemaining} min). Risk too high.",
            ];
        }
        
        // Check 6: Shots on target as quality indicator
        $shotsOnTargetHome = (int) ($liveData['shots_on_target_home'] ?? 0);
        $shotsOnTargetAway = (int) ($liveData['shots_on_target_away'] ?? 0);
        $totalShotsOnTarget = $shotsOnTargetHome + $shotsOnTargetAway;
        
        // If probability is in upper medium range (40-60%) and quality is good
        if ($probability >= 0.40 && $totalShotsOnTarget >= 3) {
            return [
                'bet' => true,
                'reason' => "Medium-high probability ({$probPercent}%) with good shot quality ({$totalShotsOnTarget} on target). Cautious bet recommended.",
            ];
        }
        
        // If probability is in lower medium range (20-40%)
        if ($probability < 0.40) {
            return [
                'bet' => false,
                'reason' => "Medium-low probability ({$probPercent}%). Insufficient confidence for betting despite decent activity.",
            ];
        }
        
        // Default for medium range: cautious approach
        return [
            'bet' => false,
            'reason' => "Medium probability ({$probPercent}%). Match conditions acceptable but confidence threshold not met. Conservative approach.",
        ];
    }
}
