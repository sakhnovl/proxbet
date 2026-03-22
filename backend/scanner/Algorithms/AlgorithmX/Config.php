<?php

declare(strict_types=1);

namespace Proxbet\Scanner\Algorithms\AlgorithmX;

/**
 * Configuration for AlgorithmX (Goal Probability Algorithm).
 * 
 * Contains all constants, thresholds, and weights used by the algorithm.
 */
final class Config
{
    // Algorithm identity
    public const ALGORITHM_ID = 4;
    public const ALGORITHM_NAME = 'AlgorithmX: Goal Probability';
    
    // Sigmoid parameters
    public const SIGMOID_K = 2.5;              // Steepness of the function
    public const SIGMOID_THRESHOLD = 1.8;      // Calibration value (AIS_rate at ~50% probability)
    
    // AIS (Attack Intensity Score) weights
    public const WEIGHT_DANGEROUS_ATTACKS = 0.4;
    public const WEIGHT_SHOTS = 0.3;
    public const WEIGHT_SHOTS_ON_TARGET = 0.2;
    public const WEIGHT_CORNERS = 0.1;
    
    // Score modifiers
    public const SCORE_MODIFIER_DRAW = 1.05;       // Draw - both teams motivated
    public const SCORE_MODIFIER_ONE_GOAL = 1.10;   // 1 goal difference - losing team pushes
    public const SCORE_MODIFIER_TWO_PLUS = 0.90;   // 2+ goal difference - winner defends
    
    // Dry period modifier
    public const DRY_PERIOD_MODIFIER = 0.92;
    public const DRY_PERIOD_MINUTE_THRESHOLD = 30;
    
    // Time factor weights
    public const TIME_FACTOR_MIN_WEIGHT = 0.4;
    public const TIME_FACTOR_MAX_WEIGHT = 0.6;
    
    // Probability bounds
    public const PROBABILITY_MIN = 0.03;  // 3%
    public const PROBABILITY_MAX = 0.97;  // 97%
    
    // Decision thresholds
    public const DECISION_THRESHOLD_HIGH = 0.60;    // High probability - bet
    public const DECISION_THRESHOLD_MEDIUM = 0.40;  // Medium probability - caution
    public const DECISION_THRESHOLD_LOW = 0.20;     // Low probability - no bet
    
    // Time constraints
    public const MIN_MINUTE = 5;   // Minimum minute for analysis
    public const MAX_MINUTE = 45;  // Maximum minute (end of 1st half)
    
    /**
     * Get sigmoid steepness parameter from environment or default.
     * 
     * @return float
     */
    public static function getSigmoidK(): float
    {
        return self::getEnvFloat('ALGORITHMX_SIGMOID_K', self::SIGMOID_K, 0.5, 10.0);
    }
    
    /**
     * Get sigmoid threshold parameter from environment or default.
     * 
     * @return float
     */
    public static function getSigmoidThreshold(): float
    {
        return self::getEnvFloat('ALGORITHMX_SIGMOID_THRESHOLD', self::SIGMOID_THRESHOLD, 0.5, 5.0);
    }
    
    /**
     * Get AIS weights as array.
     * 
     * @return array{dangerous_attacks:float,shots:float,shots_on_target:float,corners:float}
     */
    public static function getAisWeights(): array
    {
        return [
            'dangerous_attacks' => self::WEIGHT_DANGEROUS_ATTACKS,
            'shots' => self::WEIGHT_SHOTS,
            'shots_on_target' => self::WEIGHT_SHOTS_ON_TARGET,
            'corners' => self::WEIGHT_CORNERS,
        ];
    }
    
    /**
     * Get score modifiers as array.
     * 
     * @return array{draw:float,one_goal:float,two_plus:float}
     */
    public static function getScoreModifiers(): array
    {
        return [
            'draw' => self::SCORE_MODIFIER_DRAW,
            'one_goal' => self::SCORE_MODIFIER_ONE_GOAL,
            'two_plus' => self::SCORE_MODIFIER_TWO_PLUS,
        ];
    }
    
    /**
     * Get decision thresholds as array.
     * 
     * @return array{high:float,medium:float,low:float}
     */
    public static function getDecisionThresholds(): array
    {
        return [
            'high' => self::getEnvFloat('ALGORITHMX_DECISION_THRESHOLD_HIGH', self::DECISION_THRESHOLD_HIGH, 0.0, 1.0),
            'medium' => self::DECISION_THRESHOLD_MEDIUM,
            'low' => self::getEnvFloat('ALGORITHMX_DECISION_THRESHOLD_LOW', self::DECISION_THRESHOLD_LOW, 0.0, 1.0),
        ];
    }
    
    /**
     * Check if AlgorithmX is enabled.
     * 
     * @return bool
     */
    public static function isEnabled(): bool
    {
        $enabled = $_ENV['ALGORITHMX_ENABLED'] ?? getenv('ALGORITHMX_ENABLED') ?: 'true';
        
        return $enabled === '1' || $enabled === 'true';
    }
    
    /**
     * Get float value from environment with validation.
     * 
     * @param string $key
     * @param float $default
     * @param float $min
     * @param float $max
     * @return float
     */
    private static function getEnvFloat(string $key, float $default, float $min, float $max): float
    {
        $value = $_ENV[$key] ?? getenv($key);
        if (!is_scalar($value) || !is_numeric((string) $value)) {
            return $default;
        }
        
        return max($min, min($max, (float) $value));
    }
}
