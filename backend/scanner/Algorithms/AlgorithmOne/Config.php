<?php
declare(strict_types=1);

namespace Proxbet\Scanner\Algorithms\AlgorithmOne;

/**
 * Configuration for Algorithm One (legacy v1 and v2).
 * 
 * Contains all constants, thresholds, and weights used by the algorithm.
 */
final class Config
{
    // Algorithm identity
    public const ALGORITHM_ID = 1;
    public const ALGORITHM_NAME = 'Алгоритм 1';
    
    // Version control
    public const VERSION_LEGACY = 1;
    public const VERSION_V2 = 2;
    
    // Common thresholds
    public const MIN_MINUTE = 15;
    public const MAX_MINUTE = 30;
    public const MIN_PROBABILITY = 0.55;
    public const MIN_DANGEROUS_ATTACKS = 20;
    public const MIN_SHOTS_ON_TARGET = 1;
    
    // Legacy (v1) weights
    public const LEGACY_FORM_WEIGHT = 0.35;
    public const LEGACY_H2H_WEIGHT = 0.15;
    public const LEGACY_LIVE_WEIGHT = 0.50;
    
    // V2 probability weights
    public const V2_FORM_WEIGHT = 0.25;
    public const V2_H2H_WEIGHT = 0.10;
    public const V2_LIVE_WEIGHT = 0.65;
    
    // V2 live score component weights
    public const V2_PDI_WEIGHT = 0.20;
    public const V2_SHOT_QUALITY_WEIGHT = 0.25;
    public const V2_TREND_WEIGHT = 0.25;
    public const V2_XG_PRESSURE_WEIGHT = 0.20;
    public const V2_CARD_FACTOR_WEIGHT = 0.10;
    
    // V2 time pressure
    public const V2_TIME_PRESSURE_BASE = 0.7;
    public const V2_TIME_PRESSURE_MULTIPLIER = 0.3;
    
    // V2 weighted form weights
    public const V2_HOME_ATTACK_WEIGHT = 0.6;
    public const V2_AWAY_DEFENSE_WEIGHT = 0.4;
    public const V2_AWAY_ATTACK_WEIGHT = 0.6;
    public const V2_HOME_DEFENSE_WEIGHT = 0.4;
    
    // V2 specific thresholds
    public const V2_MIN_ATTACK_TEMPO = 1.5;
    public const V2_PDI_MIN_ATTACKS = 20;
    public const V2_PDI_MAX_ATTACKS = 40.0;
    public const V2_INEFFECTIVE_PRESSURE_THRESHOLD = 30;
    public const V2_INEFFECTIVE_PRESSURE_MIN_SHOTS = 2;
    
    // V2 red flag thresholds
    public const V2_LOW_ACCURACY_THRESHOLD = 0.25;
    public const V2_XG_MISMATCH_THRESHOLD = 1.2;
    public const V2_XG_MISMATCH_MIN_MINUTE = 25;
    public const V2_XG_MISMATCH_AMPLIFIER = 0.20;
    
    // V2 league factor bounds
    public const V2_LEAGUE_FACTOR_MIN = 0.7;
    public const V2_LEAGUE_FACTOR_MAX = 1.3;
    public const V2_LEAGUE_FACTOR_BASELINE = 2.5;
    
    // V2 card factor scaling
    public const V2_CARD_FACTOR_SCALE_1 = 0.03;
    public const V2_CARD_FACTOR_SCALE_2 = 0.08;
    public const V2_CARD_FACTOR_SCALE_3 = 0.15;
    
    // V2 xG normalization
    public const V2_XG_NORMALIZATION_FACTOR = 1.5;
    
    // V2 shot quality weights
    public const V2_SHOT_QUALITY_XG_WEIGHT = 0.7;
    public const V2_SHOT_QUALITY_ACCURACY_WEIGHT = 0.3;
    
    /**
     * Get algorithm version from environment or default to legacy (v1).
     * 
     * Set ALGORITHM_VERSION=2 in .env to enable v2.
     * 
     * @return int VERSION_LEGACY (1) or VERSION_V2 (2)
     */
    public static function getAlgorithmVersion(): int
    {
        $version = (int) ($_ENV['ALGORITHM_VERSION'] ?? getenv('ALGORITHM_VERSION') ?: 1);
        
        // Validate version and default to legacy if invalid
        if ($version !== self::VERSION_LEGACY && $version !== self::VERSION_V2) {
            return self::VERSION_LEGACY;
        }
        
        return $version;
    }
    
    /**
     * Check if dual-run mode is enabled (calculate both legacy and v2 for comparison).
     * 
     * Set ALGORITHM1_DUAL_RUN=1 in .env to enable.
     * 
     * @return bool
     */
    public static function isDualRunEnabled(): bool
    {
        $dualRun = $_ENV['ALGORITHM1_DUAL_RUN'] ?? getenv('ALGORITHM1_DUAL_RUN') ?: '0';
        
        return $dualRun === '1' || $dualRun === 'true';
    }
}
