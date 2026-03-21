<?php

declare(strict_types=1);

namespace Proxbet\Tests\Security;

/**
 * Security testing configuration and utilities.
 * 
 * This class provides configuration for OWASP ZAP and other security testing tools.
 */
final class SecurityTestConfig
{
    /**
     * Get endpoints to test for security vulnerabilities.
     * 
     * @return array<int,array{url:string,method:string,auth:bool}>
     */
    public static function getEndpointsToTest(): array
    {
        return [
            // Public API endpoints
            ['url' => '/backend/api.php?action=matches', 'method' => 'GET', 'auth' => false],
            ['url' => '/backend/healthz.php', 'method' => 'GET', 'auth' => false],
            ['url' => '/backend/metrics.php', 'method' => 'GET', 'auth' => false],
            
            // Admin API endpoints (require authentication)
            ['url' => '/backend/admin/api.php?action=list_bans', 'method' => 'GET', 'auth' => true],
            ['url' => '/backend/admin/api.php?action=add_ban', 'method' => 'POST', 'auth' => true],
            ['url' => '/backend/admin/api.php?action=update_ban', 'method' => 'POST', 'auth' => true],
            ['url' => '/backend/admin/api.php?action=delete_ban', 'method' => 'POST', 'auth' => true],
        ];
    }

    /**
     * Get OWASP Top 10 test cases.
     * 
     * @return array<string,array<string,mixed>>
     */
    public static function getOwaspTestCases(): array
    {
        return [
            'sql_injection' => [
                'name' => 'SQL Injection',
                'payloads' => [
                    "' OR '1'='1",
                    "1' UNION SELECT NULL--",
                    "admin'--",
                    "' OR 1=1--",
                ],
            ],
            'xss' => [
                'name' => 'Cross-Site Scripting (XSS)',
                'payloads' => [
                    '<script>alert("XSS")</script>',
                    '<img src=x onerror=alert("XSS")>',
                    'javascript:alert("XSS")',
                    '<svg onload=alert("XSS")>',
                ],
            ],
            'path_traversal' => [
                'name' => 'Path Traversal',
                'payloads' => [
                    '../../../etc/passwd',
                    '..\\..\\..\\windows\\system32\\config\\sam',
                    '....//....//....//etc/passwd',
                ],
            ],
            'command_injection' => [
                'name' => 'Command Injection',
                'payloads' => [
                    '; ls -la',
                    '| cat /etc/passwd',
                    '`whoami`',
                    '$(whoami)',
                ],
            ],
            'xxe' => [
                'name' => 'XML External Entity (XXE)',
                'payloads' => [
                    '<?xml version="1.0"?><!DOCTYPE foo [<!ENTITY xxe SYSTEM "file:///etc/passwd">]><foo>&xxe;</foo>',
                ],
            ],
        ];
    }

    /**
     * Get security headers that should be present.
     * 
     * @return array<string,string>
     */
    public static function getRequiredSecurityHeaders(): array
    {
        return [
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'DENY',
            'X-XSS-Protection' => '1; mode=block',
            'Strict-Transport-Security' => 'max-age=31536000',
            'Content-Security-Policy' => "default-src 'self'",
        ];
    }

    /**
     * Get rate limiting test configuration.
     * 
     * @return array{requests:int,window:int,endpoint:string}
     */
    public static function getRateLimitTestConfig(): array
    {
        return [
            'requests' => 25, // Should trigger rate limit
            'window' => 60, // seconds
            'endpoint' => '/backend/admin/api.php?action=list_bans',
        ];
    }
}
