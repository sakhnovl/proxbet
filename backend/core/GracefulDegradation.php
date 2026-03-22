<?php

declare(strict_types=1);

namespace Proxbet\Core;

/**
 * Graceful Degradation Manager
 * Handles service degradation and fallback mechanisms
 */
class GracefulDegradation
{
    private CacheManager $cache;
    private StructuredLogger $logger;
    private array $serviceStatus = [];
    private array $featureFlags = [];

    public function __construct(CacheManager $cache, StructuredLogger $logger)
    {
        $this->cache = $cache;
        $this->logger = $logger;
        $this->loadFeatureFlags();
    }

    /**
     * Execute with fallback to cached data
     */
    public function executeWithCacheFallback(string $key, callable $primary, int $cacheTtl = 3600): mixed
    {
        try {
            $result = $primary();
            $this->cache->set($key, $result, $cacheTtl);
            $this->markServiceHealthy($key);
            return $result;
        } catch (\Throwable $e) {
            $this->logger->warning('Primary service failed, using cache fallback', [
                'service' => $key,
                'error' => $e->getMessage()
            ]);

            $this->markServiceDegraded($key);
            $cached = $this->cache->get($key);

            if ($cached !== null) {
                return $cached;
            }

            throw $e;
        }
    }

    /**
     * Execute with timeout and fallback
     */
    public function executeWithTimeout(callable $callback, int $timeoutSeconds, mixed $fallbackValue = null): mixed
    {
        $startTime = microtime(true);

        try {
            // Set timeout for the operation
            set_time_limit($timeoutSeconds);
            $result = $callback();
            
            $duration = microtime(true) - $startTime;
            if ($duration > $timeoutSeconds) {
                throw new \RuntimeException('Operation exceeded timeout');
            }

            return $result;
        } catch (\Throwable $e) {
            $this->logger->warning('Operation timed out or failed, using fallback', [
                'error' => $e->getMessage(),
                'duration' => microtime(true) - $startTime
            ]);

            if ($fallbackValue !== null) {
                return $fallbackValue;
            }

            throw $e;
        }
    }

    /**
     * Check if feature is enabled
     */
    public function isFeatureEnabled(string $feature): bool
    {
        return $this->featureFlags[$feature] ?? false;
    }

    /**
     * Enable feature flag
     */
    public function enableFeature(string $feature): void
    {
        $this->featureFlags[$feature] = true;
        $this->saveFeatureFlags();
        $this->logger->info('Feature enabled', ['feature' => $feature]);
    }

    /**
     * Disable feature flag
     */
    public function disableFeature(string $feature): void
    {
        $this->featureFlags[$feature] = false;
        $this->saveFeatureFlags();
        $this->logger->info('Feature disabled', ['feature' => $feature]);
    }

    /**
     * Mark service as degraded
     */
    private function markServiceDegraded(string $service): void
    {
        $this->serviceStatus[$service] = [
            'status' => 'degraded',
            'timestamp' => time()
        ];
    }

    /**
     * Mark service as healthy
     */
    private function markServiceHealthy(string $service): void
    {
        $this->serviceStatus[$service] = [
            'status' => 'healthy',
            'timestamp' => time()
        ];
    }

    /**
     * Get service status
     */
    public function getServiceStatus(string $service): string
    {
        return $this->serviceStatus[$service]['status'] ?? 'unknown';
    }

    /**
     * Get all service statuses
     */
    public function getAllServiceStatuses(): array
    {
        return $this->serviceStatus;
    }

    /**
     * Check if system is in degraded mode
     */
    public function isSystemDegraded(): bool
    {
        $degradedCount = 0;
        foreach ($this->serviceStatus as $status) {
            if ($status['status'] === 'degraded') {
                $degradedCount++;
            }
        }

        return $degradedCount > 0;
    }

    /**
     * Load feature flags from cache
     */
    private function loadFeatureFlags(): void
    {
        $flags = $this->cache->get('feature_flags');
        if ($flags !== null) {
            $this->featureFlags = $flags;
        } else {
            // Default feature flags
            $this->featureFlags = [
                'ai_analysis' => true,
                'statistics_api' => true,
                'telegram_notifications' => true,
                'advanced_algorithms' => true,
                'live_updates' => true
            ];
        }
    }

    /**
     * Save feature flags to cache
     */
    private function saveFeatureFlags(): void
    {
        $this->cache->set('feature_flags', $this->featureFlags, 86400);
    }

    /**
     * Get all feature flags
     */
    public function getAllFeatureFlags(): array
    {
        return $this->featureFlags;
    }
}
