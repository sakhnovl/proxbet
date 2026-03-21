<?php

declare(strict_types=1);

namespace Proxbet\Core;

use Proxbet\Core\Exceptions\NotFoundException;
use Proxbet\Core\Exceptions\ValidationException;

/**
 * Simple router for unified entry point
 */
class Router
{
    /** @var array<string, array{handler: callable, method: string}> */
    private array $routes = [];

    /**
     * Register a GET route
     *
     * @param string $path
     * @param callable $handler
     */
    public function get(string $path, callable $handler): void
    {
        $this->routes[$path] = ['handler' => $handler, 'method' => 'GET'];
    }

    /**
     * Register a POST route
     *
     * @param string $path
     * @param callable $handler
     */
    public function post(string $path, callable $handler): void
    {
        $this->routes[$path] = ['handler' => $handler, 'method' => 'POST'];
    }

    /**
     * Register a route for any method
     *
     * @param string $path
     * @param callable $handler
     */
    public function any(string $path, callable $handler): void
    {
        $this->routes[$path] = ['handler' => $handler, 'method' => 'ANY'];
    }

    /**
     * Dispatch request to appropriate handler
     *
     * @param string $path
     * @param string $method
     * @return mixed
     * @throws NotFoundException
     * @throws ValidationException
     */
    public function dispatch(string $path, string $method)
    {
        if (!isset($this->routes[$path])) {
            throw new NotFoundException("Route not found: {$path}");
        }

        $route = $this->routes[$path];
        
        if ($route['method'] !== 'ANY' && $route['method'] !== strtoupper($method)) {
            throw new ValidationException("Method not allowed: {$method}");
        }

        return ($route['handler'])();
    }

    /**
     * Get all registered routes
     *
     * @return array<string, array{handler: callable, method: string}>
     */
    public function getRoutes(): array
    {
        return $this->routes;
    }
}
