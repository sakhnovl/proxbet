<?php

declare(strict_types=1);

namespace Proxbet\Core;

use Proxbet\Core\Exceptions\ConfigurationException;

/**
 * Simple Dependency Injection Container.
 */
final class Container
{
    /** @var array<string,callable> */
    private array $factories = [];

    /** @var array<string,object> */
    private array $instances = [];

    /**
     * Register a factory for a service.
     *
     * @param string $id Service identifier
     * @param callable $factory Factory function
     * @param bool $singleton Whether to cache the instance
     * @return void
     */
    public function register(string $id, callable $factory, bool $singleton = true): void
    {
        $this->factories[$id] = $factory;
        
        if (!$singleton && isset($this->instances[$id])) {
            unset($this->instances[$id]);
        }
    }

    /**
     * Get a service instance.
     *
     * @param string $id Service identifier
     * @throws ConfigurationException
     */
    public function get(string $id): mixed
    {
        // Return cached instance if exists
        if (isset($this->instances[$id])) {
            return $this->instances[$id];
        }

        // Check if factory exists
        if (!isset($this->factories[$id])) {
            throw new ConfigurationException("Service '{$id}' not found in container");
        }

        // Create instance
        $instance = ($this->factories[$id])($this);
        
        // Cache instance
        $this->instances[$id] = $instance;

        return $instance;
    }

    /**
     * Check if service is registered.
     *
     * @param string $id Service identifier
     * @return bool
     */
    public function has(string $id): bool
    {
        return isset($this->factories[$id]);
    }

    /**
     * Set a service instance directly.
     *
     * @param string $id Service identifier
     * @param object $instance Service instance
     * @return void
     */
    public function set(string $id, object $instance): void
    {
        $this->instances[$id] = $instance;
    }
}
