<?php

declare(strict_types=1);

namespace Proxbet\Scanner;

/**
 * Scanner configuration for algorithm version control.
 * 
 * @deprecated This class is deprecated and will be removed in a future version.
 *             Use Proxbet\Scanner\Algorithms\AlgorithmOne\Config for Algorithm 1 configuration.
 *             
 * Migration path:
 * - Use AlgorithmOne\Config::getAlgorithmVersion()
 * - Use AlgorithmOne\Config::isDualRunEnabled()
 * - Use AlgorithmOne\Config constants for thresholds
 */
final class Config
{
    /**
     * Get algorithm version from environment or default to 1 (legacy).
     * 
     * Set ALGORITHM_VERSION=2 in .env to enable v2.
     */
    public static function getAlgorithmVersion(): int
    {
        $version = (int) ($_ENV['ALGORITHM_VERSION'] ?? getenv('ALGORITHM_VERSION') ?: 1);
        
        if ($version !== 1 && $version !== 2) {
            return 1;
        }
        
        return $version;
    }

    /**
     * Check if dual-run mode is enabled (calculate both legacy and v2 for comparison).
     * 
     * Set ALGORITHM1_DUAL_RUN=1 in .env to enable.
     */
    public static function isDualRunEnabled(): bool
    {
        $dualRun = $_ENV['ALGORITHM1_DUAL_RUN'] ?? getenv('ALGORITHM1_DUAL_RUN') ?: '0';
        
        return $dualRun === '1' || $dualRun === 'true';
    }
}
