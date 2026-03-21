<?php

declare(strict_types=1);

namespace Proxbet\Core\Interfaces;

/**
 * Interface for cache implementations.
 */
interface CacheInterface
{
    /**
     * Get value from cache.
     *
     * @param string $key
     * @return mixed
     */
    public function get(string $key): mixed;

    /**
     * Set value in cache.
     *
     * @param string $key
     * @param mixed $value
     * @param int $ttl Time to live in seconds
     * @return bool
     */
    public function set(string $key, mixed $value, int $ttl = 3600): bool;

    /**
     * Delete value from cache.
     *
     * @param string $key
     * @return bool
     */
    public function delete(string $key): bool;

    /**
     * Check if key exists in cache.
     *
     * @param string $key
     * @return bool
     */
    public function has(string $key): bool;
}
