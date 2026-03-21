<?php

declare(strict_types=1);

namespace Proxbet\Security;

/**
 * Request validation including size limits and malicious payload detection
 */
final class RequestValidator
{
    private const MAX_REQUEST_SIZE = 1048576; // 1MB
    private const MAX_JSON_DEPTH = 10;

    /**
     * Validate request size
     */
    public static function validateRequestSize(?int $maxSize = null): bool
    {
        $maxSize = $maxSize ?? self::MAX_REQUEST_SIZE;
        $contentLength = $_SERVER['CONTENT_LENGTH'] ?? 0;

        if ($contentLength > $maxSize) {
            http_response_code(413);
            header('Content-Type: application/json');
            echo json_encode(['ok' => false, 'error' => 'Request entity too large']);
            exit;
        }

        return true;
    }

    /**
     * Validate and parse JSON body with depth limit
     */
    public static function parseJsonBody(?int $maxDepth = null): ?array
    {
        $maxDepth = $maxDepth ?? self::MAX_JSON_DEPTH;
        $raw = file_get_contents('php://input');

        if ($raw === '' || $raw === false) {
            return null;
        }

        try {
            $decoded = json_decode($raw, true, $maxDepth, JSON_THROW_ON_ERROR);
            return is_array($decoded) ? $decoded : null;
        } catch (\JsonException $e) {
            http_response_code(400);
            header('Content-Type: application/json');
            echo json_encode(['ok' => false, 'error' => 'Invalid JSON']);
            exit;
        }
    }

    /**
     * Check for malicious patterns in input
     */
    public static function detectMaliciousPayload(string $input): bool
    {
        // SQL injection patterns
        $sqlPatterns = [
            '/(\bUNION\b.*\bSELECT\b)/i',
            '/(\bDROP\b.*\bTABLE\b)/i',
            '/(\bINSERT\b.*\bINTO\b)/i',
            '/(\bDELETE\b.*\bFROM\b)/i',
            '/(\bUPDATE\b.*\bSET\b)/i',
            '/(;|\-\-|\/\*|\*\/|xp_|sp_)/i'
        ];

        // XSS patterns
        $xssPatterns = [
            '/<script[^>]*>.*?<\/script>/is',
            '/javascript:/i',
            '/on\w+\s*=/i', // event handlers
            '/<iframe/i',
            '/<object/i',
            '/<embed/i'
        ];

        // Command injection patterns
        $cmdPatterns = [
            '/[;&|`$(){}[\]<>]/i',
            '/\b(cat|ls|rm|wget|curl|nc|bash|sh)\b/i'
        ];

        $allPatterns = array_merge($sqlPatterns, $xssPatterns, $cmdPatterns);

        foreach ($allPatterns as $pattern) {
            if (preg_match($pattern, $input)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Validate type of value
     */
    public static function validateType(mixed $value, string $expectedType): bool
    {
        return match ($expectedType) {
            'string' => is_string($value),
            'int' => is_int($value) || (is_string($value) && ctype_digit($value)),
            'float' => is_float($value) || is_numeric($value),
            'bool' => is_bool($value) || in_array($value, [0, 1, '0', '1', 'true', 'false'], true),
            'array' => is_array($value),
            'null' => $value === null,
            default => false
        };
    }

    /**
     * Sanitize error message for public display
     */
    public static function sanitizeErrorMessage(string $message, bool $isProduction = true): string
    {
        if (!$isProduction) {
            return $message;
        }

        // Remove file paths
        $message = preg_replace('/\/[^\s]+\.php/', '[file]', $message);
        
        // Remove line numbers
        $message = preg_replace('/\bline\s+\d+/i', 'line [redacted]', $message);
        
        // Remove SQL details
        $message = preg_replace('/SQL.*$/i', 'Database error', $message);

        // Generic messages for common errors
        $genericMessages = [
            'PDOException' => 'Database error occurred',
            'mysqli' => 'Database error occurred',
            'syntax error' => 'Invalid request',
            'undefined' => 'Invalid request',
            'failed to open stream' => 'Resource not available'
        ];

        foreach ($genericMessages as $pattern => $generic) {
            if (stripos($message, $pattern) !== false) {
                return $generic;
            }
        }

        return 'An error occurred';
    }
}
