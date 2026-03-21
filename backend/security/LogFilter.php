<?php

declare(strict_types=1);

namespace Proxbet\Security;

/**
 * Filter sensitive data from logs
 * Prevents API keys, tokens, passwords from being logged
 */
final class LogFilter
{
    private const SENSITIVE_PATTERNS = [
        // API keys
        '/AIza[0-9A-Za-z\-_]{35}/',  // Google/Gemini API keys
        '/sk-[a-zA-Z0-9]{48}/',       // OpenAI API keys
        '/[0-9]{10}:[A-Za-z0-9_-]{35}/', // Telegram bot tokens
        
        // Generic patterns
        '/api[_-]?key["\']?\s*[:=]\s*["\']?([a-zA-Z0-9\-_]{20,})["\']?/i',
        '/token["\']?\s*[:=]\s*["\']?([a-zA-Z0-9\-_]{20,})["\']?/i',
        '/password["\']?\s*[:=]\s*["\']?([^\s"\']{8,})["\']?/i',
        '/bearer\s+([a-zA-Z0-9\-_\.]{20,})/i',
        
        // Database credentials
        '/mysql:\/\/[^:]+:([^@]+)@/',
        '/postgres:\/\/[^:]+:([^@]+)@/',
    ];

    private const REPLACEMENT = '[REDACTED]';

    /**
     * Filter sensitive data from string
     */
    public static function filter(string $text): string
    {
        foreach (self::SENSITIVE_PATTERNS as $pattern) {
            $text = preg_replace($pattern, self::REPLACEMENT, $text);
        }

        return $text;
    }

    /**
     * Filter sensitive data from array (recursive)
     * 
     * @param array<mixed> $data
     * @return array<mixed>
     */
    public static function filterArray(array $data): array
    {
        $filtered = [];

        foreach ($data as $key => $value) {
            // Check if key indicates sensitive data
            $lowerKey = strtolower((string) $key);
            if (self::isSensitiveKey($lowerKey)) {
                $filtered[$key] = self::REPLACEMENT;
                continue;
            }

            // Recursively filter arrays
            if (is_array($value)) {
                $filtered[$key] = self::filterArray($value);
            } elseif (is_string($value)) {
                $filtered[$key] = self::filter($value);
            } else {
                $filtered[$key] = $value;
            }
        }

        return $filtered;
    }

    /**
     * Check if key name indicates sensitive data
     */
    private static function isSensitiveKey(string $key): bool
    {
        $sensitiveKeys = [
            'password',
            'passwd',
            'pwd',
            'secret',
            'api_key',
            'apikey',
            'token',
            'auth',
            'authorization',
            'bearer',
            'private_key',
            'privatekey',
            'access_token',
            'refresh_token',
            'session_id',
            'sessionid',
            'cookie',
            'csrf_token',
            'encryption_key',
        ];

        foreach ($sensitiveKeys as $sensitiveKey) {
            if (str_contains($key, $sensitiveKey)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Filter sensitive data from JSON string
     */
    public static function filterJson(string $json): string
    {
        $data = json_decode($json, true);
        if (!is_array($data)) {
            return self::filter($json);
        }

        $filtered = self::filterArray($data);
        return json_encode($filtered, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
