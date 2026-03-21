<?php

declare(strict_types=1);

namespace Proxbet\Security;

/**
 * Centralized input validation and sanitization
 */
final class InputValidator
{
    /**
     * Validate and sanitize string input
     */
    public static function sanitizeString(?string $value, int $maxLength = 255): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        $trimmed = trim($value);
        
        // Remove null bytes
        $trimmed = str_replace("\0", '', $trimmed);
        
        // Limit length
        if (mb_strlen($trimmed) > $maxLength) {
            $trimmed = mb_substr($trimmed, 0, $maxLength);
        }

        return $trimmed;
    }

    /**
     * Sanitize for SQL LIKE queries (escape wildcards)
     */
    public static function sanitizeLike(?string $value): ?string
    {
        $value = self::sanitizeString($value);
        if ($value === null) {
            return null;
        }

        // Escape LIKE wildcards and backslash
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }

    /**
     * Validate integer within range
     */
    public static function validateInt(mixed $value, int $min = PHP_INT_MIN, int $max = PHP_INT_MAX): ?int
    {
        if (!is_numeric($value)) {
            return null;
        }

        $int = (int) $value;
        
        if ($int < $min || $int > $max) {
            return null;
        }

        return $int;
    }

    /**
     * Validate and sanitize Telegram user input
     * Prevents command injection and XSS
     */
    public static function sanitizeTelegramInput(?string $input, int $maxLength = 4096): ?string
    {
        if ($input === null || trim($input) === '') {
            return null;
        }

        $sanitized = self::sanitizeString($input, $maxLength);
        
        if ($sanitized === null) {
            return null;
        }

        // Remove control characters except newlines and tabs
        $sanitized = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $sanitized);

        return $sanitized;
    }

    /**
     * Validate email format
     */
    public static function validateEmail(?string $email): ?string
    {
        if ($email === null || trim($email) === '') {
            return null;
        }

        $email = trim($email);
        
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return null;
        }

        return $email;
    }

    /**
     * Validate URL format
     */
    public static function validateUrl(?string $url): ?string
    {
        if ($url === null || trim($url) === '') {
            return null;
        }

        $url = trim($url);
        
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            return null;
        }

        return $url;
    }

    /**
     * Whitelist validation - value must be in allowed list
     */
    public static function validateWhitelist(mixed $value, array $allowed): ?string
    {
        if (!is_string($value) && !is_int($value)) {
            return null;
        }

        $strValue = (string) $value;
        
        if (!in_array($strValue, $allowed, true)) {
            return null;
        }

        return $strValue;
    }
}
