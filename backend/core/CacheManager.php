<?php

declare(strict_types=1);

namespace Proxbet\Core;

use Redis;
use RuntimeException;

/**
 * Redis-based cache manager for performance optimization.
 * Provides caching layer for database queries, API responses, and configuration.
 */
final class CacheManager
{
    private const DEFAULT_TTL = 300; // 5 minutes
    private const BANS_TTL = 300; // 5 minutes
    private const CONFIG_TTL = 3600; // 1 hour
    private const API_RESPONSE_TTL = 60; // 1 minute
    private const STATS_TTL = 1800; // 30 minutes

    private ?Redis $redis = null;
    private bool $enabled = true;

    public function __construct()
    {
        $this->initializeRedis();
    }

    private function initializeRedis(): void
    {
        try {
            $host = getenv('REDIS_HOST') ?: 'localhost';
            $port = (int) (getenv('REDIS_PORT') ?: 6379);
            $password = getenv('REDIS_PASSWORD') ?: null;

            $this->redis = new Redis();
            $connected = $this->redis->connect($host, $port, 2.0);

            if (!$connected) {
                throw new RuntimeException('Failed to connect to Redis');
            }

            if ($password !== null && $password !== '') {
                $this->redis->auth($password);
            }

            $this->redis->setOption(Redis::OPT_SERIALIZER, Redis::SERIALIZER_JSON);
        } catch (\Throwable $e) {
            $this->enabled = false;
            error_log('CacheManager: Redis initialization failed - ' . $e->getMessage());
        }
    }

    /**
     * Get cached value by key.
     *
     * @return mixed|null
     */
    public function get(string $key)
    {
        if (!$this->enabled || $this->redis === null) {
            return null;
        }

        try {
            $value = $this->redis->get($key);
            return $value === false ? null : $value;
        } catch (\Throwable $e) {
            error_log('CacheManager: Get failed - ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Set cached value with TTL.
     *
     * @param mixed $value
     */
    public function set(string $key, $value, int $ttl = self::DEFAULT_TTL): bool
    {
        if (!$this->enabled || $this->redis === null) {
            return false;
        }

        try {
            return $this->redis->setex($key, $ttl, $value);
        } catch (\Throwable $e) {
            error_log('CacheManager: Set failed - ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Delete cached value.
     */
    public function delete(string $key): bool
    {
        if (!$this->enabled || $this->redis === null) {
            return false;
        }

        try {
            return $this->redis->del($key) > 0;
        } catch (\Throwable $e) {
            error_log('CacheManager: Delete failed - ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Delete multiple keys by pattern.
     */
    public function deletePattern(string $pattern): int
    {
        if (!$this->enabled || $this->redis === null) {
            return 0;
        }

        try {
            $keys = $this->redis->keys($pattern);
            if (empty($keys)) {
                return 0;
            }

            return $this->redis->del($keys);
        } catch (\Throwable $e) {
            error_log('CacheManager: DeletePattern failed - ' . $e->getMessage());
            return 0;
        }
    }

    /**
     * Cache active bans.
     *
     * @param array<int,array<string,mixed>> $bans
     */
    public function cacheActiveBans(array $bans): bool
    {
        return $this->set('bans:active', $bans, self::BANS_TTL);
    }

    /**
     * Get cached active bans.
     *
     * @return array<int,array<string,mixed>>|null
     */
    public function getActiveBans(): ?array
    {
        $result = $this->get('bans:active');
        return is_array($result) ? $result : null;
    }

    /**
     * Invalidate bans cache.
     */
    public function invalidateBans(): bool
    {
        return $this->delete('bans:active');
    }

    /**
     * Cache API response.
     *
     * @param mixed $response
     */
    public function cacheApiResponse(string $endpoint, array $params, $response): bool
    {
        $key = $this->buildApiCacheKey($endpoint, $params);
        return $this->set($key, $response, self::API_RESPONSE_TTL);
    }

    /**
     * Get cached API response.
     *
     * @return mixed|null
     */
    public function getApiResponse(string $endpoint, array $params)
    {
        $key = $this->buildApiCacheKey($endpoint, $params);
        return $this->get($key);
    }

    /**
     * Cache match statistics.
     *
     * @param array<string,mixed> $stats
     */
    public function cacheMatchStats(int $matchId, array $stats): bool
    {
        $key = "match:stats:{$matchId}";
        return $this->set($key, $stats, self::STATS_TTL);
    }

    /**
     * Get cached match statistics.
     *
     * @return array<string,mixed>|null
     */
    public function getMatchStats(int $matchId): ?array
    {
        $result = $this->get("match:stats:{$matchId}");
        return is_array($result) ? $result : null;
    }

    /**
     * Invalidate match statistics cache.
     */
    public function invalidateMatchStats(int $matchId): bool
    {
        return $this->delete("match:stats:{$matchId}");
    }

    /**
     * Cache configuration value.
     *
     * @param mixed $value
     */
    public function cacheConfig(string $key, $value): bool
    {
        return $this->set("config:{$key}", $value, self::CONFIG_TTL);
    }

    /**
     * Get cached configuration value.
     *
     * @return mixed|null
     */
    public function getConfig(string $key)
    {
        return $this->get("config:{$key}");
    }

    /**
     * Check if cache is enabled and connected.
     */
    public function isEnabled(): bool
    {
        return $this->enabled && $this->redis !== null;
    }

    /**
     * Get cache statistics.
     *
     * @return array<string,mixed>
     */
    public function getStats(): array
    {
        if (!$this->enabled || $this->redis === null) {
            return ['enabled' => false];
        }

        try {
            $info = $this->redis->info();
            return [
                'enabled' => true,
                'connected' => true,
                'used_memory' => $info['used_memory_human'] ?? 'unknown',
                'connected_clients' => $info['connected_clients'] ?? 0,
                'total_commands_processed' => $info['total_commands_processed'] ?? 0,
                'keyspace_hits' => $info['keyspace_hits'] ?? 0,
                'keyspace_misses' => $info['keyspace_misses'] ?? 0,
                'hit_rate' => $this->calculateHitRate($info),
            ];
        } catch (\Throwable $e) {
            return ['enabled' => true, 'connected' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Clear all cache.
     */
    public function flush(): bool
    {
        if (!$this->enabled || $this->redis === null) {
            return false;
        }

        try {
            return $this->redis->flushDB();
        } catch (\Throwable $e) {
            error_log('CacheManager: Flush failed - ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Add cache tags to a key for group invalidation.
     *
     * @param string[] $tags
     */
    public function setWithTags(string $key, $value, array $tags, int $ttl = self::DEFAULT_TTL): bool
    {
        if (!$this->enabled || $this->redis === null) {
            return false;
        }

        try {
            // Set the main value
            $result = $this->redis->setex($key, $ttl, $value);
            
            if (!$result) {
                return false;
            }

            // Add key to each tag set
            foreach ($tags as $tag) {
                $tagKey = "tag:{$tag}";
                $this->redis->sAdd($tagKey, $key);
                // Set expiration for tag set (slightly longer than max TTL)
                $this->redis->expire($tagKey, $ttl + 300);
            }

            return true;
        } catch (\Throwable $e) {
            error_log('CacheManager: SetWithTags failed - ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Invalidate all keys associated with given tags.
     *
     * @param string[] $tags
     */
    public function invalidateByTags(array $tags): int
    {
        if (!$this->enabled || $this->redis === null) {
            return 0;
        }

        try {
            $keysToDelete = [];
            
            foreach ($tags as $tag) {
                $tagKey = "tag:{$tag}";
                $keys = $this->redis->sMembers($tagKey);
                
                if (is_array($keys)) {
                    $keysToDelete = array_merge($keysToDelete, $keys);
                }
                
                // Delete the tag set itself
                $this->redis->del($tagKey);
            }

            if (empty($keysToDelete)) {
                return 0;
            }

            // Remove duplicates
            $keysToDelete = array_unique($keysToDelete);
            
            return $this->redis->del($keysToDelete);
        } catch (\Throwable $e) {
            error_log('CacheManager: InvalidateByTags failed - ' . $e->getMessage());
            return 0;
        }
    }

    /**
     * Warm up cache with critical data.
     * Should be called on application startup or after cache flush.
     *
     * @param callable $bansLoader Function that returns active bans array
     * @param callable|null $configLoader Function that returns config array
     */
    public function warmUp(callable $bansLoader, ?callable $configLoader = null): array
    {
        $results = [
            'bans' => false,
            'config' => false,
            'errors' => [],
        ];

        // Warm up active bans
        try {
            $bans = $bansLoader();
            if (is_array($bans)) {
                $results['bans'] = $this->cacheActiveBans($bans);
            }
        } catch (\Throwable $e) {
            $results['errors'][] = 'Bans warming failed: ' . $e->getMessage();
            error_log('CacheManager: Bans warming failed - ' . $e->getMessage());
        }

        // Warm up configuration
        if ($configLoader !== null) {
            try {
                $config = $configLoader();
                if (is_array($config)) {
                    foreach ($config as $key => $value) {
                        $this->cacheConfig($key, $value);
                    }
                    $results['config'] = true;
                }
            } catch (\Throwable $e) {
                $results['errors'][] = 'Config warming failed: ' . $e->getMessage();
                error_log('CacheManager: Config warming failed - ' . $e->getMessage());
            }
        }

        return $results;
    }

    /**
     * Check if cache warming is needed (cache is empty or stale).
     */
    public function needsWarming(): bool
    {
        if (!$this->enabled || $this->redis === null) {
            return false;
        }

        try {
            // Check if critical keys exist
            $bansExist = $this->redis->exists('bans:active');
            
            return $bansExist === 0;
        } catch (\Throwable $e) {
            error_log('CacheManager: NeedsWarming check failed - ' . $e->getMessage());
            return false;
        }
    }

    private function buildApiCacheKey(string $endpoint, array $params): string
    {
        ksort($params);
        $paramsHash = md5(json_encode($params));
        return "api:{$endpoint}:{$paramsHash}";
    }

    /**
     * @param array<string,mixed> $info
     */
    private function calculateHitRate(array $info): float
    {
        $hits = (int) ($info['keyspace_hits'] ?? 0);
        $misses = (int) ($info['keyspace_misses'] ?? 0);
        $total = $hits + $misses;

        if ($total === 0) {
            return 0.0;
        }

        return round(($hits / $total) * 100, 2);
    }
}
