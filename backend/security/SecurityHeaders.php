<?php

declare(strict_types=1);

namespace Proxbet\Security;

/**
 * Security headers middleware
 * Adds CSP, X-Frame-Options, and other security headers
 */
final class SecurityHeaders
{
    /**
     * Apply all security headers
     */
    public static function apply(bool $isApi = true): void
    {
        // Prevent MIME type sniffing
        header('X-Content-Type-Options: nosniff');

        // Prevent clickjacking
        header('X-Frame-Options: DENY');

        // XSS Protection (legacy browsers)
        header('X-XSS-Protection: 1; mode=block');

        // Referrer policy
        header('Referrer-Policy: strict-origin-when-cross-origin');

        // Permissions policy
        header('Permissions-Policy: geolocation=(), microphone=(), camera=()');

        if ($isApi) {
            self::applyApiHeaders();
        } else {
            self::applyWebHeaders();
        }
    }

    /**
     * Apply CSP for API endpoints
     */
    private static function applyApiHeaders(): void
    {
        // Strict CSP for API - no scripts, no styles
        header("Content-Security-Policy: default-src 'none'; frame-ancestors 'none'");
    }

    /**
     * Apply CSP for web pages
     */
    private static function applyWebHeaders(): void
    {
        // CSP for web pages - adjust as needed
        $csp = implode('; ', [
            "default-src 'self'",
            "script-src 'self' 'unsafe-inline'",
            "style-src 'self' 'unsafe-inline'",
            "img-src 'self' data: https:",
            "font-src 'self'",
            "connect-src 'self'",
            "frame-ancestors 'none'",
            "base-uri 'self'",
            "form-action 'self'"
        ]);

        header("Content-Security-Policy: $csp");
    }

    /**
     * Apply HSTS (HTTP Strict Transport Security)
     * Only use in production with HTTPS
     */
    public static function applyHSTS(int $maxAge = 31536000): void
    {
        if (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') {
            header("Strict-Transport-Security: max-age=$maxAge; includeSubDomains; preload");
        }
    }
}
