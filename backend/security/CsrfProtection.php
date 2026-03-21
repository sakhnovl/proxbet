<?php

declare(strict_types=1);

namespace Proxbet\Security;

/**
 * CSRF token generation and validation
 */
final class CsrfProtection
{
    private const TOKEN_LENGTH = 32;
    private const HEADER_NAME = 'X-CSRF-Token';

    /**
     * Generate a new CSRF token
     */
    public static function generateToken(): string
    {
        return bin2hex(random_bytes(self::TOKEN_LENGTH));
    }

    /**
     * Validate CSRF token from request
     * Checks both header and POST body
     */
    public static function validateToken(string $expectedToken): bool
    {
        if ($expectedToken === '') {
            return false;
        }

        // Check header first
        $headerToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
        if ($headerToken !== '' && hash_equals($expectedToken, $headerToken)) {
            return true;
        }

        // Check POST body
        $bodyToken = $_POST['csrf_token'] ?? '';
        if ($bodyToken !== '' && hash_equals($expectedToken, $bodyToken)) {
            return true;
        }

        return false;
    }

    /**
     * Validate token from request against session or other storage
     * For stateless API, token should be derived from authenticated user
     */
    public static function validateFromRequest(string $storedToken): bool
    {
        return self::validateToken($storedToken);
    }
}
