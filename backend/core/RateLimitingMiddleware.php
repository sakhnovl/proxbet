<?php

declare(strict_types=1);

namespace Proxbet\Core;

use Proxbet\Security\RateLimiter;
use Proxbet\Security\DDoSProtection;
use Proxbet\Security\TelegramRateLimiter;

/**
 * Comprehensive Rate Limiting Middleware
 * Integrates all rate limiting mechanisms
 */
class RateLimitingMiddleware
{
    private RateLimiter $rateLimiter;
    private DDoSProtection $ddosProtection;
    private ?TelegramRateLimiter $telegramRateLimiter;
    private StructuredLogger $logger;
    private array $endpointLimits = [];

    public function __construct(
        RateLimiter $rateLimiter,
        DDoSProtection $ddosProtection,
        StructuredLogger $logger,
        ?TelegramRateLimiter $telegramRateLimiter = null
    ) {
        $this->rateLimiter = $rateLimiter;
        $this->ddosProtection = $ddosProtection;
        $this->logger = $logger;
        $this->telegramRateLimiter = $telegramRateLimiter;
        $this->initializeEndpointLimits();
    }

    /**
     * Apply rate limiting for public API
     */
    public function applyPublicApiLimit(string $endpoint, string $identifier): bool
    {
        $limit = $this->endpointLimits['public'][$endpoint] ?? $this->endpointLimits['public']['default'];

        if (!$this->rateLimiter->checkLimit($identifier, $limit['requests'], $limit['window'])) {
            $this->logger->warning('Public API rate limit exceeded', [
                'endpoint' => $endpoint,
                'identifier' => $identifier,
                'limit' => $limit
            ]);
            return false;
        }

        // Also check DDoS protection
        try {
            $this->ddosProtection->checkRequest($identifier, $endpoint);
        } catch (\Throwable $e) {
            $this->logger->warning('DDoS protection triggered', [
                'endpoint' => $endpoint,
                'identifier' => $identifier,
                'error' => $e->getMessage(),
            ]);
            return false;
        }

        return true;
    }

    /**
     * Apply rate limiting for admin API
     */
    public function applyAdminApiLimit(string $endpoint, string $userId): bool
    {
        $limit = $this->endpointLimits['admin'][$endpoint] ?? $this->endpointLimits['admin']['default'];

        if (!$this->rateLimiter->checkLimit("admin:$userId", $limit['requests'], $limit['window'])) {
            $this->logger->warning('Admin API rate limit exceeded', [
                'endpoint' => $endpoint,
                'user_id' => $userId,
                'limit' => $limit
            ]);
            return false;
        }

        return true;
    }

    /**
     * Apply rate limiting for Telegram bot commands
     */
    public function applyTelegramLimit(int $userId, string $command): bool
    {
        if ($this->telegramRateLimiter === null) {
            return true;
        }

        if (!$this->telegramRateLimiter->checkLimit($userId, $command)) {
            $this->logger->warning('Telegram rate limit exceeded', [
                'user_id' => $userId,
                'command' => $command
            ]);
            return false;
        }

        return true;
    }

    /**
     * Apply rate limiting for external API calls
     */
    public function applyExternalApiLimit(string $apiName, string $operation): bool
    {
        $limit = $this->endpointLimits['external'][$apiName] ?? $this->endpointLimits['external']['default'];

        $key = "external:$apiName:$operation";
        if (!$this->rateLimiter->checkLimit($key, $limit['requests'], $limit['window'])) {
            $this->logger->warning('External API rate limit exceeded', [
                'api' => $apiName,
                'operation' => $operation,
                'limit' => $limit
            ]);
            return false;
        }

        return true;
    }

    /**
     * Check for abuse patterns
     */
    public function checkAbusePatterns(string $identifier, array $requestData): bool
    {
        // Check for suspicious patterns
        $patterns = [
            'rapid_requests' => $this->checkRapidRequests($identifier),
            'suspicious_payload' => $this->checkSuspiciousPayload($requestData),
            'blacklisted' => $this->isBlacklisted($identifier)
        ];

        foreach ($patterns as $pattern => $detected) {
            if ($detected) {
                $this->logger->warning('Abuse pattern detected', [
                    'identifier' => $identifier,
                    'pattern' => $pattern
                ]);
                return false;
            }
        }

        return true;
    }

    /**
     * Initialize endpoint-specific limits
     */
    private function initializeEndpointLimits(): void
    {
        $this->endpointLimits = [
            'public' => [
                'default' => ['requests' => 100, 'window' => 60],
                '/api/matches' => ['requests' => 60, 'window' => 60],
                '/api/stats' => ['requests' => 30, 'window' => 60],
                '/api/live' => ['requests' => 120, 'window' => 60]
            ],
            'admin' => [
                'default' => ['requests' => 20, 'window' => 60],
                '/admin/bans' => ['requests' => 10, 'window' => 60],
                '/admin/stats' => ['requests' => 30, 'window' => 60]
            ],
            'external' => [
                'default' => ['requests' => 50, 'window' => 60],
                'gemini' => ['requests' => 10, 'window' => 60],
                'statistics_api' => ['requests' => 30, 'window' => 60],
                'eventsstat' => ['requests' => 60, 'window' => 60]
            ]
        ];
    }

    /**
     * Check for rapid requests
     */
    private function checkRapidRequests(string $identifier): bool
    {
        // Check if more than 10 requests in 1 second
        return !$this->rateLimiter->checkLimit("rapid:$identifier", 10, 1);
    }

    /**
     * Check for suspicious payload
     */
    private function checkSuspiciousPayload(array $requestData): bool
    {
        // Check for common attack patterns
        $suspicious = ['<script', 'javascript:', 'onerror=', '../', 'union select', 'drop table'];
        
        $dataString = json_encode($requestData);
        foreach ($suspicious as $pattern) {
            if (stripos($dataString, $pattern) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if identifier is blacklisted
     */
    private function isBlacklisted(string $identifier): bool
    {
        // This would check against a blacklist in Redis/database
        // For now, return false
        return false;
    }

    /**
     * Get rate limit headers for response
     */
    public function getRateLimitHeaders(string $identifier, int $limit, int $window): array
    {
        $remaining = $this->rateLimiter->getRemainingRequests($identifier, $limit, $window);
        $resetTime = time() + $window;

        return [
            'X-RateLimit-Limit' => (string)$limit,
            'X-RateLimit-Remaining' => (string)max(0, $remaining),
            'X-RateLimit-Reset' => (string)$resetTime
        ];
    }

    /**
     * Get current rate limit status
     */
    public function getStatus(): array
    {
        return [
            'rate_limiter_active' => true,
            'ddos_protection_active' => true,
            'telegram_limiter_active' => $this->telegramRateLimiter !== null,
            'endpoint_limits' => $this->endpointLimits
        ];
    }
}
