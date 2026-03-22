<?php

declare(strict_types=1);

namespace Proxbet\Core;

use Proxbet\Line\Logger;
use RuntimeException;

/**
 * Redis-based state manager to replace JSON file storage
 */
class RedisStateManager
{
    private \Redis $redis;
    private string $keyPrefix;
    private int $defaultTtl;

    public function __construct(
        string $host = '127.0.0.1',
        int $port = 6379,
        string $keyPrefix = 'proxbet:',
        int $defaultTtl = 86400
    ) {
        if (!class_exists(\Redis::class)) {
            throw new RuntimeException('Redis extension is not installed. Redis is optional and must be enabled explicitly.');
        }

        $this->redis = new \Redis();
        $this->keyPrefix = $keyPrefix;
        $this->defaultTtl = $defaultTtl;

        try {
            $connected = $this->redis->connect($host, $port, 2.0);
            if (!$connected) {
                throw new RuntimeException("Failed to connect to Redis at $host:$port");
            }

            // Test connection
            $this->redis->ping();
            Logger::info('Redis state manager connected', ['host' => $host, 'port' => $port]);
        } catch (\Throwable $e) {
            Logger::error('Redis connection failed', ['error' => $e->getMessage()]);
            throw new RuntimeException('Redis state manager initialization failed: ' . $e->getMessage());
        }
    }

    /**
     * Get state by key
     */
    public function get(string $key, mixed $default = null): mixed
    {
        $fullKey = $this->keyPrefix . $key;

        try {
            $value = $this->redis->get($fullKey);
            if ($value === false) {
                return $default;
            }

            $decoded = json_decode($value, true);
            return $decoded ?? $default;
        } catch (\Throwable $e) {
            Logger::error('Redis get failed', ['key' => $key, 'error' => $e->getMessage()]);
            return $default;
        }
    }

    /**
     * Set state with optional TTL
     */
    public function set(string $key, mixed $value, ?int $ttl = null): bool
    {
        $fullKey = $this->keyPrefix . $key;
        $ttl = $ttl ?? $this->defaultTtl;

        try {
            $encoded = json_encode($value, JSON_THROW_ON_ERROR);
            $result = $this->redis->setex($fullKey, $ttl, $encoded);
            return $result !== false;
        } catch (\Throwable $e) {
            Logger::error('Redis set failed', ['key' => $key, 'error' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * Delete state by key
     */
    public function delete(string $key): bool
    {
        $fullKey = $this->keyPrefix . $key;

        try {
            return $this->redis->del($fullKey) !== 0;
        } catch (\Throwable $e) {
            Logger::error('Redis delete failed', ['key' => $key, 'error' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * Check if key exists
     */
    public function exists(string $key): bool
    {
        $fullKey = $this->keyPrefix . $key;

        try {
            return $this->redis->exists($fullKey) !== 0;
        } catch (\Throwable $e) {
            Logger::error('Redis exists check failed', ['key' => $key, 'error' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * Increment counter atomically
     */
    public function increment(string $key, int $by = 1): int
    {
        $fullKey = $this->keyPrefix . $key;

        try {
            return $this->redis->incrBy($fullKey, $by);
        } catch (\Throwable $e) {
            Logger::error('Redis increment failed', ['key' => $key, 'error' => $e->getMessage()]);
            return 0;
        }
    }

    /**
     * Set with expiration timestamp
     */
    public function setWithExpiry(string $key, mixed $value, int $expiryTimestamp): bool
    {
        $fullKey = $this->keyPrefix . $key;

        try {
            $encoded = json_encode($value, JSON_THROW_ON_ERROR);
            return $this->redis->set($fullKey, $encoded, ['EXAT' => $expiryTimestamp]);
        } catch (\Throwable $e) {
            Logger::error('Redis setWithExpiry failed', ['key' => $key, 'error' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * Get multiple keys at once
     */
    public function mget(array $keys): array
    {
        $fullKeys = array_map(fn($k) => $this->keyPrefix . $k, $keys);

        try {
            $values = $this->redis->mget($fullKeys);
            $result = [];

            foreach ($keys as $i => $key) {
                $value = $values[$i] ?? false;
                if ($value !== false) {
                    $result[$key] = json_decode($value, true);
                }
            }

            return $result;
        } catch (\Throwable $e) {
            Logger::error('Redis mget failed', ['error' => $e->getMessage()]);
            return [];
        }
    }

    /**
     * Close Redis connection
     */
    public function close(): void
    {
        try {
            $this->redis->close();
        } catch (\Throwable $e) {
            Logger::error('Redis close failed', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Create from environment variables
     */
    public static function fromEnv(): self
    {
        $host = getenv('REDIS_HOST') ?: '127.0.0.1';
        $port = (int) (getenv('REDIS_PORT') ?: 6379);
        $prefix = getenv('REDIS_KEY_PREFIX') ?: 'proxbet:';
        $ttl = (int) (getenv('REDIS_DEFAULT_TTL') ?: 86400);

        return new self($host, $port, $prefix, $ttl);
    }
}
