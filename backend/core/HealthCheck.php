<?php

declare(strict_types=1);

namespace Proxbet\Core;

use PDO;
use Redis;
use Proxbet\Line\Logger;

/**
 * Health check system for monitoring critical components
 */
class HealthCheck
{
    private array $checks = [];
    private array $results = [];

    /**
     * Add database health check
     */
    public function addDatabaseCheck(PDO $pdo, string $name = 'database'): self
    {
        $this->checks[$name] = function () use ($pdo) {
            try {
                $stmt = $pdo->query('SELECT 1');
                $result = $stmt->fetch();
                return [
                    'status' => 'healthy',
                    'message' => 'Database connection OK',
                ];
            } catch (\Throwable $e) {
                return [
                    'status' => 'unhealthy',
                    'message' => 'Database connection failed: ' . $e->getMessage(),
                ];
            }
        };

        return $this;
    }

    /**
     * Add Redis health check
     */
    public function addRedisCheck(string $host = '127.0.0.1', int $port = 6379, string $name = 'redis'): self
    {
        $this->checks[$name] = function () use ($host, $port) {
            try {
                $redis = new Redis();
                $connected = $redis->connect($host, $port, 1.0);
                
                if (!$connected) {
                    return [
                        'status' => 'unhealthy',
                        'message' => 'Redis connection failed',
                    ];
                }

                $redis->ping();
                $redis->close();

                return [
                    'status' => 'healthy',
                    'message' => 'Redis connection OK',
                ];
            } catch (\Throwable $e) {
                return [
                    'status' => 'unhealthy',
                    'message' => 'Redis check failed: ' . $e->getMessage(),
                ];
            }
        };

        return $this;
    }

    /**
     * Add disk space health check
     */
    public function addDiskSpaceCheck(string $path = '/', int $minFreePercent = 10, string $name = 'disk_space'): self
    {
        $this->checks[$name] = function () use ($path, $minFreePercent) {
            try {
                $total = disk_total_space($path);
                $free = disk_free_space($path);
                
                if ($total === false || $free === false) {
                    return [
                        'status' => 'unhealthy',
                        'message' => 'Cannot read disk space',
                    ];
                }

                $freePercent = ($free / $total) * 100;

                if ($freePercent < $minFreePercent) {
                    return [
                        'status' => 'unhealthy',
                        'message' => sprintf('Low disk space: %.2f%% free', $freePercent),
                        'free_percent' => round($freePercent, 2),
                    ];
                }

                return [
                    'status' => 'healthy',
                    'message' => sprintf('Disk space OK: %.2f%% free', $freePercent),
                    'free_percent' => round($freePercent, 2),
                ];
            } catch (\Throwable $e) {
                return [
                    'status' => 'unhealthy',
                    'message' => 'Disk space check failed: ' . $e->getMessage(),
                ];
            }
        };

        return $this;
    }

    /**
     * Add memory usage health check
     */
    public function addMemoryCheck(int $maxUsagePercent = 90, string $name = 'memory'): self
    {
        $this->checks[$name] = function () use ($maxUsagePercent) {
            try {
                $memoryUsage = memory_get_usage(true);
                $memoryLimit = $this->parseMemoryLimit(ini_get('memory_limit'));

                if ($memoryLimit === -1) {
                    return [
                        'status' => 'healthy',
                        'message' => 'Memory limit unlimited',
                        'usage_mb' => round($memoryUsage / 1024 / 1024, 2),
                    ];
                }

                $usagePercent = ($memoryUsage / $memoryLimit) * 100;

                if ($usagePercent > $maxUsagePercent) {
                    return [
                        'status' => 'unhealthy',
                        'message' => sprintf('High memory usage: %.2f%%', $usagePercent),
                        'usage_percent' => round($usagePercent, 2),
                        'usage_mb' => round($memoryUsage / 1024 / 1024, 2),
                    ];
                }

                return [
                    'status' => 'healthy',
                    'message' => sprintf('Memory usage OK: %.2f%%', $usagePercent),
                    'usage_percent' => round($usagePercent, 2),
                    'usage_mb' => round($memoryUsage / 1024 / 1024, 2),
                ];
            } catch (\Throwable $e) {
                return [
                    'status' => 'unhealthy',
                    'message' => 'Memory check failed: ' . $e->getMessage(),
                ];
            }
        };

        return $this;
    }

    /**
     * Add custom health check
     */
    public function addCustomCheck(string $name, callable $check): self
    {
        $this->checks[$name] = $check;
        return $this;
    }

    /**
     * Run all health checks
     */
    public function run(): array
    {
        $this->results = [];
        $overallHealthy = true;

        foreach ($this->checks as $name => $check) {
            try {
                $result = $check();
                $this->results[$name] = $result;

                if (($result['status'] ?? 'unhealthy') !== 'healthy') {
                    $overallHealthy = false;
                }
            } catch (\Throwable $e) {
                $this->results[$name] = [
                    'status' => 'unhealthy',
                    'message' => 'Check failed: ' . $e->getMessage(),
                ];
                $overallHealthy = false;
            }
        }

        return [
            'status' => $overallHealthy ? 'healthy' : 'unhealthy',
            'timestamp' => time(),
            'checks' => $this->results,
        ];
    }

    /**
     * Parse memory limit string to bytes
     */
    private function parseMemoryLimit(string $limit): int
    {
        $limit = trim($limit);
        
        if ($limit === '-1') {
            return -1;
        }

        $unit = strtolower(substr($limit, -1));
        $value = (int) substr($limit, 0, -1);

        return match ($unit) {
            'g' => $value * 1024 * 1024 * 1024,
            'm' => $value * 1024 * 1024,
            'k' => $value * 1024,
            default => (int) $limit,
        };
    }

    /**
     * Get results from last run
     */
    public function getResults(): array
    {
        return $this->results;
    }

    /**
     * Check if system is healthy
     */
    public function isHealthy(): bool
    {
        foreach ($this->results as $result) {
            if (($result['status'] ?? 'unhealthy') !== 'healthy') {
                return false;
            }
        }
        return true;
    }
}
