<?php

declare(strict_types=1);

namespace Proxbet\Core;

/**
 * In-memory configuration cache to avoid repeated getenv() calls.
 */
class ConfigCache
{
    /** @var array<string, string|false> */
    private static array $cache = [];
    
    private static bool $enabled = true;

    /**
     * Get environment variable with caching.
     */
    public static function get(string $key, ?string $default = null): ?string
    {
        if (!self::$enabled) {
            $value = getenv($key);
            return $value !== false ? $value : $default;
        }

        if (!array_key_exists($key, self::$cache)) {
            self::$cache[$key] = getenv($key);
        }

        $value = self::$cache[$key];
        return $value !== false ? $value : $default;
    }

    /**
     * Get environment variable as integer.
     */
    public static function getInt(string $key, int $default = 0): int
    {
        $value = self::get($key);
        return $value !== null ? (int) $value : $default;
    }

    /**
     * Get environment variable as boolean.
     */
    public static function getBool(string $key, bool $default = false): bool
    {
        $value = self::get($key);
        if ($value === null) {
            return $default;
        }
        return in_array(strtolower($value), ['1', 'true', 'yes', 'on'], true);
    }

    /**
     * Get environment variable as float.
     */
    public static function getFloat(string $key, float $default = 0.0): float
    {
        $value = self::get($key);
        return $value !== null ? (float) $value : $default;
    }

    /**
     * Clear the cache (useful for testing).
     */
    public static function clear(): void
    {
        self::$cache = [];
    }

    /**
     * Disable caching (useful for testing).
     */
    public static function disable(): void
    {
        self::$enabled = false;
        self::clear();
    }

    /**
     * Enable caching.
     */
    public static function enable(): void
    {
        self::$enabled = true;
    }

    /**
     * Warm up cache with commonly used config keys.
     *
     * @param array<int, string> $keys
     */
    public static function warmUp(array $keys): void
    {
        foreach ($keys as $key) {
            self::get($key);
        }
    }
}
