<?php

declare(strict_types=1);

namespace Proxbet\Core;

require_once __DIR__ . '/../security/LogFilter.php';

use Proxbet\Security\LogFilter;

/**
 * Performance monitoring for tracking execution times and resource usage.
 */
final class PerformanceMonitor
{
    /** @var array<string,array{start:float,end:float|null,memory_start:int,memory_end:int|null}> */
    private static array $timers = [];

    /** @var array<string,int> */
    private static array $counters = [];

    /**
     * Start timing an operation.
     */
    public static function start(string $operation): void
    {
        self::$timers[$operation] = [
            'start' => microtime(true),
            'end' => null,
            'memory_start' => memory_get_usage(true),
            'memory_end' => null,
        ];
    }

    /**
     * Stop timing an operation.
     */
    public static function stop(string $operation): float
    {
        if (!isset(self::$timers[$operation])) {
            return 0.0;
        }

        $end = microtime(true);
        self::$timers[$operation]['end'] = $end;
        self::$timers[$operation]['memory_end'] = memory_get_usage(true);

        return $end - self::$timers[$operation]['start'];
    }

    /**
     * Get duration of an operation in milliseconds.
     */
    public static function getDuration(string $operation): float
    {
        if (!isset(self::$timers[$operation])) {
            return 0.0;
        }

        $timer = self::$timers[$operation];
        $end = $timer['end'] ?? microtime(true);

        return round(($end - $timer['start']) * 1000, 2);
    }

    /**
     * Get memory usage of an operation in MB.
     */
    public static function getMemoryUsage(string $operation): float
    {
        if (!isset(self::$timers[$operation])) {
            return 0.0;
        }

        $timer = self::$timers[$operation];
        $memoryEnd = $timer['memory_end'] ?? memory_get_usage(true);
        $memoryDiff = $memoryEnd - $timer['memory_start'];

        return round($memoryDiff / 1024 / 1024, 2);
    }

    /**
     * Increment a counter.
     */
    public static function increment(string $counter, int $value = 1): void
    {
        if (!isset(self::$counters[$counter])) {
            self::$counters[$counter] = 0;
        }

        self::$counters[$counter] += $value;
    }

    /**
     * Get counter value.
     */
    public static function getCounter(string $counter): int
    {
        return self::$counters[$counter] ?? 0;
    }

    /**
     * Get all metrics.
     *
     * @return array<string,mixed>
     */
    public static function getMetrics(): array
    {
        $metrics = [
            'timers' => [],
            'counters' => self::$counters,
            'memory' => [
                'current' => round(memory_get_usage(true) / 1024 / 1024, 2),
                'peak' => round(memory_get_peak_usage(true) / 1024 / 1024, 2),
            ],
        ];

        foreach (self::$timers as $operation => $timer) {
            $metrics['timers'][$operation] = [
                'duration_ms' => self::getDuration($operation),
                'memory_mb' => self::getMemoryUsage($operation),
            ];
        }

        return $metrics;
    }

    /**
     * Reset all metrics.
     */
    public static function reset(): void
    {
        self::$timers = [];
        self::$counters = [];
    }

    /**
     * Log metrics to error log.
     */
    public static function logMetrics(string $context = ''): void
    {
        $metrics = self::getMetrics();
        $prefix = $context !== '' ? "[{$context}] " : '';
        $payload = LogFilter::filterJson((string) json_encode($metrics, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        error_log($prefix . 'Performance Metrics: ' . $payload);
    }
}
