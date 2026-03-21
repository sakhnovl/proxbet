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

    public const LEAGUE_CATEGORY_TOP_TIER = 'top-tier';
    public const LEAGUE_CATEGORY_LOW_TIER = 'low-tier';
    public const LEAGUE_CATEGORY_WOMEN = 'women';
    public const LEAGUE_CATEGORY_YOUTH = 'youth';
    
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
    public const V2_TIME_PRESSURE_CURVE_EXPONENT = 1.15;
    public const V2_TIME_PRESSURE_EARLY_WINDOW_END = 20;
    public const V2_TIME_PRESSURE_EARLY_FLOOR_MAX = 0.32;
    
    // V2 weighted form weights
    public const V2_HOME_ATTACK_WEIGHT = 0.6;
    public const V2_AWAY_DEFENSE_WEIGHT = 0.4;
    public const V2_AWAY_ATTACK_WEIGHT = 0.6;
    public const V2_HOME_DEFENSE_WEIGHT = 0.4;
    
    // V2 specific thresholds
    public const V2_MIN_ATTACK_TEMPO = 1.5;
    public const V2_ATTACK_TEMPO_SOFT_PENALTY_MIN = 0.82;
    public const V2_NO_H2H_PENALTY = 0.92;
    public const V2_EARLY_SHOTS_WINDOW_END = 18;
    public const V2_EARLY_SHOTS_PRESSURE_THRESHOLD = 2.0;
    public const V2_EARLY_SHOTS_TREND_THRESHOLD = 0.60;
    public const V2_EARLY_SHOTS_XG_THRESHOLD = 0.90;
    public const V2_EARLY_SHOTS_CORNERS_THRESHOLD = 4;
    public const V2_EARLY_SHOTS_RELIEF_PENALTY = 0.91;
    public const V2_PDI_MIN_ATTACKS = 20;
    public const V2_PDI_MAX_ATTACKS = 40.0;
    public const V2_INEFFECTIVE_PRESSURE_THRESHOLD = 30;
    public const V2_INEFFECTIVE_PRESSURE_MIN_SHOTS = 2;

    // V2 red flag thresholds
    public const V2_LOW_ACCURACY_THRESHOLD = 0.25;
    public const V2_LOW_ACCURACY_PENALTY = 0.88;
    public const V2_INEFFECTIVE_PRESSURE_PENALTY = 0.90;
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
    public const V2_XG_FALLBACK_SCALE = 0.85;
    
    // V2 shot quality weights
    public const V2_SHOT_QUALITY_XG_WEIGHT = 0.7;
    public const V2_SHOT_QUALITY_ACCURACY_WEIGHT = 0.3;
    public const V2_SHOT_QUALITY_FALLBACK_ACCURACY_WEIGHT = 0.55;
    public const V2_SHOT_QUALITY_FALLBACK_SOT_WEIGHT = 0.20;
    public const V2_SHOT_QUALITY_FALLBACK_VOLUME_WEIGHT = 0.10;
    public const V2_SHOT_QUALITY_FALLBACK_CORNERS_WEIGHT = 0.05;
    public const V2_SHOT_QUALITY_FALLBACK_TREND_WEIGHT = 0.10;
    
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

    public static function getV2MinProbability(): float
    {
        return self::getEnvFloat('ALGORITHM1_V2_MIN_PROBABILITY', self::MIN_PROBABILITY, 0.0, 1.0);
    }

    /**
     * @return list<float>
     */
    public static function getV2ThresholdCandidates(): array
    {
        $rawValue = $_ENV['ALGORITHM1_V2_THRESHOLD_CANDIDATES']
            ?? getenv('ALGORITHM1_V2_THRESHOLD_CANDIDATES')
            ?: '';

        if (!is_string($rawValue) || trim($rawValue) === '') {
            return [0.55, 0.52, 0.50];
        }

        $thresholds = [];
        foreach (explode(',', $rawValue) as $candidate) {
            $candidate = trim($candidate);
            if ($candidate === '' || !is_numeric($candidate)) {
                continue;
            }

            $value = max(0.0, min(1.0, (float) $candidate));
            $thresholds[number_format($value, 4, '.', '')] = $value;
        }

        if ($thresholds === []) {
            return [0.55, 0.52, 0.50];
        }

        $values = array_values($thresholds);
        rsort($values, SORT_NUMERIC);

        return $values;
    }

    public static function getV2TimePressureCurveExponent(): float
    {
        return self::getEnvFloat(
            'ALGORITHM1_V2_TIME_PRESSURE_EXPONENT',
            self::V2_TIME_PRESSURE_CURVE_EXPONENT,
            0.5,
            3.0
        );
    }

    public static function getV2TimePressureEarlyWindowEnd(): int
    {
        return self::getEnvInt(
            'ALGORITHM1_V2_TIME_PRESSURE_EARLY_WINDOW_END',
            self::V2_TIME_PRESSURE_EARLY_WINDOW_END,
            self::MIN_MINUTE + 1,
            self::MAX_MINUTE
        );
    }

    public static function getV2TimePressureEarlyFloorMax(): float
    {
        return self::getEnvFloat(
            'ALGORITHM1_V2_TIME_PRESSURE_EARLY_FLOOR',
            self::V2_TIME_PRESSURE_EARLY_FLOOR_MAX,
            0.0,
            1.0
        );
    }

    /**
     * @return array{
     *   category:string,
     *   min_attack_tempo:float,
     *   missing_h2h_penalty:float,
     *   xg_weight_multiplier:float,
     *   probability_threshold:float
     * }
     */
    public static function getLeagueSegmentProfile(?string $category = null): array
    {
        $profiles = [
            self::LEAGUE_CATEGORY_TOP_TIER => [
                'category' => self::LEAGUE_CATEGORY_TOP_TIER,
                'min_attack_tempo' => 1.55,
                'missing_h2h_penalty' => 0.94,
                'xg_weight_multiplier' => 1.10,
                'probability_threshold' => 0.55,
            ],
            self::LEAGUE_CATEGORY_LOW_TIER => [
                'category' => self::LEAGUE_CATEGORY_LOW_TIER,
                'min_attack_tempo' => 1.35,
                'missing_h2h_penalty' => 0.98,
                'xg_weight_multiplier' => 0.90,
                'probability_threshold' => self::getV2MinProbability(),
            ],
            self::LEAGUE_CATEGORY_WOMEN => [
                'category' => self::LEAGUE_CATEGORY_WOMEN,
                'min_attack_tempo' => 1.25,
                'missing_h2h_penalty' => 1.00,
                'xg_weight_multiplier' => 0.80,
                'probability_threshold' => 0.50,
            ],
            self::LEAGUE_CATEGORY_YOUTH => [
                'category' => self::LEAGUE_CATEGORY_YOUTH,
                'min_attack_tempo' => 1.20,
                'missing_h2h_penalty' => 1.00,
                'xg_weight_multiplier' => 0.75,
                'probability_threshold' => 0.49,
            ],
        ];

        $resolvedCategory = $category ?? self::LEAGUE_CATEGORY_LOW_TIER;

        return $profiles[$resolvedCategory] ?? $profiles[self::LEAGUE_CATEGORY_LOW_TIER];
    }

    private static function getEnvFloat(string $key, float $default, float $min, float $max): float
    {
        $value = $_ENV[$key] ?? getenv($key);
        if (!is_scalar($value) || !is_numeric((string) $value)) {
            return $default;
        }

        return max($min, min($max, (float) $value));
    }

    private static function getEnvInt(string $key, int $default, int $min, int $max): int
    {
        $value = $_ENV[$key] ?? getenv($key);
        if (!is_scalar($value) || !is_numeric((string) $value)) {
            return $default;
        }

        return max($min, min($max, (int) $value));
    }
}
