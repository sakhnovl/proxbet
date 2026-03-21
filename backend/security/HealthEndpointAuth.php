<?php

declare(strict_types=1);

namespace Proxbet\Security;

/**
 * Health endpoint authentication
 * Protects health check endpoints from exposing system information
 */
class HealthEndpointAuth
{
    private bool $requireAuth;
    private string $username;
    private string $passwordHash;
    /** @var array<string> */
    private array $allowedIPs;

    /**
     * @param bool $requireAuth Whether to require authentication
     * @param string $username Basic auth username
     * @param string $password Basic auth password (will be hashed)
     * @param array<string> $allowedIPs Whitelist of allowed IPs (empty = allow all)
     */
    public function __construct(
        bool $requireAuth = true,
        string $username = '',
        string $password = '',
        array $allowedIPs = []
    ) {
        $this->requireAuth = $requireAuth;
        $this->username = $username;
        $this->passwordHash = $password !== '' ? password_hash($password, PASSWORD_BCRYPT) : '';
        $this->allowedIPs = $allowedIPs;
    }

    /**
     * Validate access to health endpoint
     * 
     * @return bool True if access is allowed
     */
    public function validateAccess(): bool
    {
        // Check IP whitelist first
        if (!empty($this->allowedIPs)) {
            $clientIP = $this->getClientIP();
            if (!in_array($clientIP, $this->allowedIPs, true)) {
                $this->sendUnauthorized('IP not allowed');
                return false;
            }
        }

        // If auth not required, allow access
        if (!$this->requireAuth) {
            return true;
        }

        // Check basic auth
        if (!isset($_SERVER['PHP_AUTH_USER'], $_SERVER['PHP_AUTH_PW'])) {
            $this->sendAuthRequired();
            return false;
        }

        $user = $_SERVER['PHP_AUTH_USER'];
        $pass = $_SERVER['PHP_AUTH_PW'];

        if ($user !== $this->username || !password_verify($pass, $this->passwordHash)) {
            $this->sendUnauthorized('Invalid credentials');
            return false;
        }

        return true;
    }

    /**
     * Get sanitized health response (hide sensitive info)
     * 
     * @param array<string,mixed> $fullResponse Full health check response
     * @param bool $isAuthenticated Whether user is authenticated
     * @return array<string,mixed> Sanitized response
     */
    public function sanitizeResponse(array $fullResponse, bool $isAuthenticated): array
    {
        if ($isAuthenticated) {
            return $fullResponse;
        }

        // Return minimal info for unauthenticated requests
        return [
            'ok' => $fullResponse['ok'] ?? false,
            'service' => $fullResponse['service'] ?? 'unknown',
        ];
    }

    /**
     * Get client IP address
     */
    private function getClientIP(): string
    {
        // Check for proxy headers (be careful with these in production)
        $headers = [
            'HTTP_CF_CONNECTING_IP', // Cloudflare
            'HTTP_X_REAL_IP',
            'HTTP_X_FORWARDED_FOR',
            'REMOTE_ADDR',
        ];

        foreach ($headers as $header) {
            if (isset($_SERVER[$header])) {
                $ip = $_SERVER[$header];
                // For X-Forwarded-For, take the first IP
                if (strpos($ip, ',') !== false) {
                    $ip = explode(',', $ip)[0];
                }
                return trim($ip);
            }
        }

        return '0.0.0.0';
    }

    /**
     * Send 401 Unauthorized with WWW-Authenticate header
     */
    private function sendAuthRequired(): void
    {
        header('WWW-Authenticate: Basic realm="Health Check"');
        http_response_code(401);
        header('Content-Type: application/json');
        echo json_encode([
            'ok' => false,
            'error' => 'Authentication required',
        ], JSON_THROW_ON_ERROR);
        exit;
    }

    /**
     * Send 403 Forbidden
     */
    private function sendUnauthorized(string $message): void
    {
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode([
            'ok' => false,
            'error' => $message,
        ], JSON_THROW_ON_ERROR);
        exit;
    }
}
