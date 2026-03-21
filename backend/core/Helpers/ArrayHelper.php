<?php

declare(strict_types=1);

namespace Proxbet\Core\Helpers;

/**
 * Helper class for array operations.
 * Reduces code duplication across the application.
 */
final class ArrayHelper
{
    /**
     * Get value from array with default.
     * 
     * @param array<string,mixed> $array The array
     * @param string $key The key
     * @param mixed $default Default value
     * @return mixed
     */
    public static function get(array $array, string $key, mixed $default = null): mixed
    {
        return $array[$key] ?? $default;
    }

    /**
     * Get integer value from array.
     * 
     * @param array<string,mixed> $array The array
     * @param string $key The key
     * @param int $default Default value
     * @return int
     */
    public static function getInt(array $array, string $key, int $default = 0): int
    {
        return (int) ($array[$key] ?? $default);
    }

    /**
     * Get string value from array.
     * 
     * @param array<string,mixed> $array The array
     * @param string $key The key
     * @param string $default Default value
     * @return string
     */
    public static function getString(array $array, string $key, string $default = ''): string
    {
        return (string) ($array[$key] ?? $default);
    }

    /**
     * Get float value from array.
     * 
     * @param array<string,mixed> $array The array
     * @param string $key The key
     * @param float $default Default value
     * @return float
     */
    public static function getFloat(array $array, string $key, float $default = 0.0): float
    {
        return (float) ($array[$key] ?? $default);
    }

    /**
     * Get boolean value from array.
     * 
     * @param array<string,mixed> $array The array
     * @param string $key The key
     * @param bool $default Default value
     * @return bool
     */
    public static function getBool(array $array, string $key, bool $default = false): bool
    {
        return (bool) ($array[$key] ?? $default);
    }

    /**
     * Get array value from array.
     * 
     * @param array<string,mixed> $array The array
     * @param string $key The key
     * @param array<mixed> $default Default value
     * @return array<mixed>
     */
    public static function getArray(array $array, string $key, array $default = []): array
    {
        $value = $array[$key] ?? $default;
        return is_array($value) ? $value : $default;
    }

    /**
     * Check if array has all specified keys.
     * 
     * @param array<string,mixed> $array The array
     * @param array<string> $keys Required keys
     * @return bool
     */
    public static function hasKeys(array $array, array $keys): bool
    {
        foreach ($keys as $key) {
            if (!array_key_exists($key, $array)) {
                return false;
            }
        }
        
        return true;
    }

    /**
     * Filter array by keys (whitelist).
     * 
     * @param array<string,mixed> $array The array
     * @param array<string> $allowedKeys Allowed keys
     * @return array<string,mixed>
     */
    public static function only(array $array, array $allowedKeys): array
    {
        return array_intersect_key($array, array_flip($allowedKeys));
    }

    /**
     * Remove keys from array (blacklist).
     * 
     * @param array<string,mixed> $array The array
     * @param array<string> $excludedKeys Keys to exclude
     * @return array<string,mixed>
     */
    public static function except(array $array, array $excludedKeys): array
    {
        return array_diff_key($array, array_flip($excludedKeys));
    }

    /**
     * Flatten multidimensional array.
     * 
     * @param array<mixed> $array The array
     * @param string $prefix Key prefix
     * @return array<string,mixed>
     */
    public static function flatten(array $array, string $prefix = ''): array
    {
        $result = [];
        
        foreach ($array as $key => $value) {
            $newKey = $prefix === '' ? (string) $key : "{$prefix}.{$key}";
            
            if (is_array($value)) {
                $result = array_merge($result, self::flatten($value, $newKey));
            } else {
                $result[$newKey] = $value;
            }
        }
        
        return $result;
    }

    /**
     * Group array by key.
     * 
     * @param array<int,array<string,mixed>> $array The array
     * @param string $key Key to group by
     * @return array<string,array<int,array<string,mixed>>>
     */
    public static function groupBy(array $array, string $key): array
    {
        $result = [];
        
        foreach ($array as $item) {
            if (!is_array($item) || !isset($item[$key])) {
                continue;
            }
            
            $groupKey = (string) $item[$key];
            $result[$groupKey][] = $item;
        }
        
        return $result;
    }
}
