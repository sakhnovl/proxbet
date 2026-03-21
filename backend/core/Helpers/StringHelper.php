<?php

declare(strict_types=1);

namespace Proxbet\Core\Helpers;

/**
 * Helper class for string operations.
 * Reduces code duplication across the application.
 */
final class StringHelper
{
    /**
     * Sanitize string (trim and limit length).
     * 
     * @param string $value The string
     * @param int $maxLength Maximum length
     * @return string
     */
    public static function sanitize(string $value, int $maxLength = 255): string
    {
        $value = trim($value);
        
        if (mb_strlen($value) > $maxLength) {
            $value = mb_substr($value, 0, $maxLength);
        }
        
        return $value;
    }

    /**
     * Check if string contains substring.
     * 
     * @param string $haystack The string to search in
     * @param string $needle The substring to search for
     * @param bool $caseInsensitive Whether to ignore case
     * @return bool
     */
    public static function contains(string $haystack, string $needle, bool $caseInsensitive = false): bool
    {
        if ($needle === '') {
            return true;
        }
        
        if ($caseInsensitive) {
            return mb_stripos($haystack, $needle) !== false;
        }
        
        return mb_strpos($haystack, $needle) !== false;
    }

    /**
     * Check if string starts with prefix.
     * 
     * @param string $haystack The string
     * @param string $needle The prefix
     * @return bool
     */
    public static function startsWith(string $haystack, string $needle): bool
    {
        if ($needle === '') {
            return true;
        }
        
        return mb_strpos($haystack, $needle) === 0;
    }

    /**
     * Check if string ends with suffix.
     * 
     * @param string $haystack The string
     * @param string $needle The suffix
     * @return bool
     */
    public static function endsWith(string $haystack, string $needle): bool
    {
        if ($needle === '') {
            return true;
        }
        
        $length = mb_strlen($needle);
        return mb_substr($haystack, -$length) === $needle;
    }

    /**
     * Convert string to snake_case.
     * 
     * @param string $value The string
     * @return string
     */
    public static function toSnakeCase(string $value): string
    {
        $value = preg_replace('/([A-Z])/', '_$1', $value);
        $value = strtolower($value);
        return ltrim($value, '_');
    }

    /**
     * Convert string to camelCase.
     * 
     * @param string $value The string
     * @return string
     */
    public static function toCamelCase(string $value): string
    {
        $value = str_replace(['-', '_'], ' ', $value);
        $value = ucwords($value);
        $value = str_replace(' ', '', $value);
        return lcfirst($value);
    }

    /**
     * Generate random string.
     * 
     * @param int $length String length
     * @param string $characters Allowed characters
     * @return string
     */
    public static function random(int $length = 16, string $characters = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ'): string
    {
        $charactersLength = strlen($characters);
        $randomString = '';
        
        for ($i = 0; $i < $length; $i++) {
            $randomString .= $characters[random_int(0, $charactersLength - 1)];
        }
        
        return $randomString;
    }

    /**
     * Truncate string with ellipsis.
     * 
     * @param string $value The string
     * @param int $length Maximum length
     * @param string $ellipsis Ellipsis string
     * @return string
     */
    public static function truncate(string $value, int $length = 100, string $ellipsis = '...'): string
    {
        if (mb_strlen($value) <= $length) {
            return $value;
        }
        
        return mb_substr($value, 0, $length - mb_strlen($ellipsis)) . $ellipsis;
    }

    /**
     * Escape HTML special characters.
     * 
     * @param string $value The string
     * @return string
     */
    public static function escapeHtml(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /**
     * Remove all whitespace from string.
     * 
     * @param string $value The string
     * @return string
     */
    public static function removeWhitespace(string $value): string
    {
        return preg_replace('/\s+/', '', $value);
    }
}
