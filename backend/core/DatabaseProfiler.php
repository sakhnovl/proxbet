<?php

declare(strict_types=1);

namespace Proxbet\Core;

use PDO;
use PDOStatement;

/**
 * Database query profiler for performance analysis.
 * Tracks query execution time, memory usage, and provides EXPLAIN analysis.
 */
final class DatabaseProfiler
{
    /** @var array<int,array<string,mixed>> */
    private static array $queries = [];
    
    private static bool $enabled = false;
    private static float $slowQueryThreshold = 0.1; // 100ms

    /**
     * Enable query profiling.
     */
    public static function enable(float $slowQueryThresholdSeconds = 0.1): void
    {
        self::$enabled = true;
        self::$slowQueryThreshold = $slowQueryThresholdSeconds;
        self::$queries = [];
    }

    /**
     * Disable query profiling.
     */
    public static function disable(): void
    {
        self::$enabled = false;
    }

    /**
     * Check if profiling is enabled.
     */
    public static function isEnabled(): bool
    {
        return self::$enabled;
    }

    /**
     * Profile a query execution.
     *
     * @param callable $callback Function that executes the query
     * @return mixed Result from callback
     */
    public static function profile(string $sql, callable $callback)
    {
        if (!self::$enabled) {
            return $callback();
        }

        $startTime = microtime(true);
        $startMemory = memory_get_usage();

        try {
            $result = $callback();
            $success = true;
            $error = null;
        } catch (\Throwable $e) {
            $success = false;
            $error = $e->getMessage();
            throw $e;
        } finally {
            $endTime = microtime(true);
            $endMemory = memory_get_usage();

            $duration = $endTime - $startTime;
            $memoryUsed = $endMemory - $startMemory;

            self::$queries[] = [
                'sql' => $sql,
                'duration' => $duration,
                'memory' => $memoryUsed,
                'success' => $success ?? false,
                'error' => $error ?? null,
                'timestamp' => $startTime,
                'is_slow' => $duration > self::$slowQueryThreshold,
            ];
        }

        return $result;
    }

    /**
     * Get EXPLAIN analysis for a SELECT query.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function explainQuery(PDO $pdo, string $sql): array
    {
        // Only works for SELECT queries
        if (!preg_match('/^\s*SELECT/i', $sql)) {
            return [];
        }

        try {
            $explainSql = 'EXPLAIN ' . $sql;
            $stmt = $pdo->query($explainSql);
            $result = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            return is_array($result) ? $result : [];
        } catch (\Throwable $e) {
            return ['error' => $e->getMessage()];
        }
    }

    /**
     * Analyze query for potential issues.
     *
     * @return array<string,mixed>
     */
    public static function analyzeQuery(PDO $pdo, string $sql): array
    {
        $analysis = [
            'sql' => $sql,
            'issues' => [],
            'recommendations' => [],
            'explain' => [],
        ];

        // Get EXPLAIN data
        $explain = self::explainQuery($pdo, $sql);
        $analysis['explain'] = $explain;

        // Analyze EXPLAIN results
        foreach ($explain as $row) {
            // Check for full table scan
            if (isset($row['type']) && $row['type'] === 'ALL') {
                $analysis['issues'][] = 'Full table scan detected on table: ' . ($row['table'] ?? 'unknown');
                $analysis['recommendations'][] = 'Consider adding an index on the WHERE clause columns';
            }

            // Check for filesort
            if (isset($row['Extra']) && str_contains($row['Extra'], 'Using filesort')) {
                $analysis['issues'][] = 'Filesort detected - query requires sorting without index';
                $analysis['recommendations'][] = 'Consider adding an index on ORDER BY columns';
            }

            // Check for temporary table
            if (isset($row['Extra']) && str_contains($row['Extra'], 'Using temporary')) {
                $analysis['issues'][] = 'Temporary table created - may impact performance';
                $analysis['recommendations'][] = 'Review GROUP BY and DISTINCT clauses';
            }

            // Check for large row examination
            if (isset($row['rows']) && $row['rows'] > 10000) {
                $analysis['issues'][] = 'Large number of rows examined: ' . $row['rows'];
                $analysis['recommendations'][] = 'Add WHERE clause or improve index selectivity';
            }
        }

        // Check for SELECT *
        if (preg_match('/SELECT\s+\*/i', $sql)) {
            $analysis['issues'][] = 'SELECT * detected - fetching all columns';
            $analysis['recommendations'][] = 'Select only needed columns for better performance';
        }

        // Check for missing LIMIT
        if (preg_match('/^\s*SELECT/i', $sql) && !preg_match('/LIMIT\s+\d+/i', $sql)) {
            $analysis['issues'][] = 'No LIMIT clause - may return large result set';
            $analysis['recommendations'][] = 'Add LIMIT clause to prevent excessive data transfer';
        }

        return $analysis;
    }

    /**
     * Get all profiled queries.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function getQueries(): array
    {
        return self::$queries;
    }

    /**
     * Get slow queries only.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function getSlowQueries(): array
    {
        return array_filter(self::$queries, static fn(array $query): bool => $query['is_slow']);
    }

    /**
     * Get profiling statistics.
     *
     * @return array<string,mixed>
     */
    public static function getStats(): array
    {
        if (empty(self::$queries)) {
            return [
                'total_queries' => 0,
                'slow_queries' => 0,
                'failed_queries' => 0,
                'total_time' => 0.0,
                'avg_time' => 0.0,
                'max_time' => 0.0,
                'total_memory' => 0,
            ];
        }

        $totalTime = 0.0;
        $maxTime = 0.0;
        $totalMemory = 0;
        $slowCount = 0;
        $failedCount = 0;

        foreach (self::$queries as $query) {
            $totalTime += $query['duration'];
            $maxTime = max($maxTime, $query['duration']);
            $totalMemory += $query['memory'];
            
            if ($query['is_slow']) {
                $slowCount++;
            }
            
            if (!$query['success']) {
                $failedCount++;
            }
        }

        $totalQueries = count(self::$queries);

        return [
            'total_queries' => $totalQueries,
            'slow_queries' => $slowCount,
            'failed_queries' => $failedCount,
            'total_time' => round($totalTime, 4),
            'avg_time' => round($totalTime / $totalQueries, 4),
            'max_time' => round($maxTime, 4),
            'total_memory' => $totalMemory,
            'avg_memory' => (int) round($totalMemory / $totalQueries),
        ];
    }

    /**
     * Generate profiling report.
     */
    public static function generateReport(): string
    {
        $stats = self::getStats();
        $slowQueries = self::getSlowQueries();

        $report = "=== Database Query Profiling Report ===\n\n";
        $report .= "Statistics:\n";
        $report .= sprintf("  Total Queries: %d\n", $stats['total_queries']);
        $report .= sprintf("  Slow Queries: %d (%.1f%%)\n", 
            $stats['slow_queries'], 
            $stats['total_queries'] > 0 ? ($stats['slow_queries'] / $stats['total_queries'] * 100) : 0
        );
        $report .= sprintf("  Failed Queries: %d\n", $stats['failed_queries']);
        $report .= sprintf("  Total Time: %.4fs\n", $stats['total_time']);
        $report .= sprintf("  Average Time: %.4fs\n", $stats['avg_time']);
        $report .= sprintf("  Max Time: %.4fs\n", $stats['max_time']);
        $report .= sprintf("  Total Memory: %s\n", self::formatBytes($stats['total_memory']));
        $report .= sprintf("  Average Memory: %s\n\n", self::formatBytes($stats['avg_memory']));

        if (!empty($slowQueries)) {
            $report .= "Slow Queries (> " . self::$slowQueryThreshold . "s):\n";
            foreach ($slowQueries as $i => $query) {
                $report .= sprintf("\n%d. Duration: %.4fs, Memory: %s\n", 
                    $i + 1, 
                    $query['duration'],
                    self::formatBytes($query['memory'])
                );
                $report .= "   SQL: " . substr($query['sql'], 0, 200);
                if (strlen($query['sql']) > 200) {
                    $report .= "...";
                }
                $report .= "\n";
            }
        }

        return $report;
    }

    /**
     * Clear all profiled queries.
     */
    public static function reset(): void
    {
        self::$queries = [];
    }

    /**
     * Format bytes to human-readable format.
     */
    private static function formatBytes(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes . ' B';
        }
        
        if ($bytes < 1048576) {
            return round($bytes / 1024, 2) . ' KB';
        }
        
        return round($bytes / 1048576, 2) . ' MB';
    }
}
