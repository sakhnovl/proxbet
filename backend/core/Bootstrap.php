<?php

declare(strict_types=1);

namespace Proxbet\Core;

use Proxbet\Line\Logger;

/**
 * Unified bootstrap class for all entry points.
 * Handles environment loading, autoloading, and common initialization.
 */
final class Bootstrap
{
    private static bool $initialized = false;

    /**
     * Initialize the application with unified bootstrap process.
     * 
     * @param array<string> $requiredEnvVars Required environment variables
     * @throws \RuntimeException If required environment variables are missing
     */
    public static function init(array $requiredEnvVars = []): void
    {
        if (self::$initialized) {
            return;
        }

        // Load autoloader
        self::loadAutoloader();

        // Load environment
        self::loadEnvironment();

        // Initialize logger
        Logger::init();

        // Validate required environment variables
        self::validateEnvironment($requiredEnvVars);

        self::$initialized = true;
    }

    /**
     * Load Composer autoloader and custom autoloader.
     */
    private static function loadAutoloader(): void
    {
        $rootDir = dirname(__DIR__, 2);
        $vendorAutoload = $rootDir . '/vendor/autoload.php';

        if (is_file($vendorAutoload)) {
            require_once $vendorAutoload;
        }

        require_once __DIR__ . '/../bootstrap/autoload.php';
    }

    /**
     * Load environment variables from .env file.
     */
    private static function loadEnvironment(): void
    {
        require_once __DIR__ . '/../bootstrap/runtime.php';
        proxbet_bootstrap_env();
    }

    /**
     * Validate that required environment variables are set.
     * 
     * @param array<string> $requiredVars
     * @throws \RuntimeException If required variables are missing
     */
    private static function validateEnvironment(array $requiredVars): void
    {
        if (empty($requiredVars)) {
            return;
        }

        proxbet_require_env($requiredVars);
    }

    /**
     * Get environment variable with type casting.
     * 
     * @param string $key Environment variable name
     * @param mixed $default Default value if not set
     * @return mixed
     */
    public static function env(string $key, mixed $default = null): mixed
    {
        $value = getenv($key);
        
        if ($value === false) {
            return $default;
        }

        return $value;
    }

    /**
     * Get environment variable as string.
     */
    public static function envString(string $key, string $default = ''): string
    {
        return (string) self::env($key, $default);
    }

    /**
     * Get environment variable as integer.
     */
    public static function envInt(string $key, int $default = 0): int
    {
        return (int) self::env($key, $default);
    }

    /**
     * Get environment variable as boolean.
     */
    public static function envBool(string $key, bool $default = false): bool
    {
        $value = self::env($key, $default);
        
        if (is_bool($value)) {
            return $value;
        }

        return in_array(strtolower((string) $value), ['1', 'true', 'yes', 'on'], true);
    }
}
