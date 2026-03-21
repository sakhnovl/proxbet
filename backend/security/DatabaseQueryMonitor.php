<?php

declare(strict_types=1);

namespace Proxbet\Security;

use Proxbet\Core\StructuredLogger;

/**
 * Database Query Monitoring System
 * 
 * Monitors database queries for performance and security issues
 */
class DatabaseQueryMonitor
{
    private StructuredLogger $logger;
    private array $queryLog = [];
    private float $slowQueryThreshold;
    private int $maxQueriesWarning;
    
    public function __construct(
        StructuredLogger $logger,
        float $slowQueryThreshold = 0.1, // 100ms
        int $maxQueriesWarning = 50
    ) {
        $this->logger = $logger;
        $this->slowQueryThreshold = $slowQueryThreshold;
        $this->maxQueriesWarning = $maxQueriesWarning;
    }
    
    /**
     * Monitor query execution
     */
    public function monitorQuery(string $query, array $params, callable $executor): mixed
    {
        $startTime = microtime(true);
        $startMemory = memory_get_usage();
        
        try {
            $result = $executor();
            $success = true;
            $error = null;
        } catch (\Throwable $e) {
            $success = false;
            $error = $e->getMessage();
            throw $e;
        } finally {
            $executionTime = microtime(true) - $startTime;
            $memoryUsed = memory_get_usage() - $startMemory;
            
            $this->logQuery($query, $params, $executionTime, $memoryUsed, $success, $error);
        }
        
        return $result;
    }
    
    /**
     * Log query execution details
     */
    private function logQuery(
        string $query,
        array $params,
        float $executionTime,
        int $memoryUsed,
        bool $success,
        ?string $error
    ): void {
        $queryInfo = [
            'query' => $this->sanitizeQuery($query),
            'params_count' => count($params),
            'execution_time' => round($executionTime, 4),
            'memory_used' => $memoryUsed,
            'success' => $success
        ];
        
        if ($error) {
            $queryInfo['error'] = $error;
        }
        
        $this->queryLog[] = $queryInfo;
        
        // Log slow queries
        if ($executionTime > $this->slowQueryThreshold) {
            $this->logger->warning('Slow query detected', array_merge($queryInfo, [
                'threshold' => $this->slowQueryThreshold
            ]));
        }
        
        // Log failed queries
        if (!$success) {
            $this->logger->error('Query execution failed', $queryInfo);
        }
        
        // Warn about too many queries
        if (count($this->queryLog) > $this->maxQueriesWarning) {
            $this->logger->warning('High query count detected', [
                'query_count' => count($this->queryLog),
                'threshold' => $this->maxQueriesWarning
            ]);
        }
    }
    
    /**
     * Get query statistics
     */
    public function getStatistics(): array
    {
        if (empty($this->queryLog)) {
            return [
                'total_queries' => 0,
                'total_time' => 0,
                'avg_time' => 0,
                'slow_queries' => 0,
                'failed_queries' => 0
            ];
        }
        
        $totalTime = array_sum(array_column($this->queryLog, 'execution_time'));
        $slowQueries = count(array_filter(
            $this->queryLog,
            fn($q) => $q['execution_time'] > $this->slowQueryThreshold
        ));
        $failedQueries = count(array_filter(
            $this->queryLog,
            fn($q) => !$q['success']
        ));
        
        return [
            'total_queries' => count($this->queryLog),
            'total_time' => round($totalTime, 4),
            'avg_time' => round($totalTime / count($this->queryLog), 4),
            'slow_queries' => $slowQueries,
            'failed_queries' => $failedQueries,
            'memory_total' => array_sum(array_column($this->queryLog, 'memory_used'))
        ];
    }
    
    /**
     * Get slow queries
     */
    public function getSlowQueries(): array
    {
        return array_filter(
            $this->queryLog,
            fn($q) => $q['execution_time'] > $this->slowQueryThreshold
        );
    }
    
    /**
     * Detect N+1 query problems
     */
    public function detectNPlusOne(): array
    {
        $patterns = [];
        
        foreach ($this->queryLog as $query) {
            $normalized = $this->normalizeQuery($query['query']);
            if (!isset($patterns[$normalized])) {
                $patterns[$normalized] = 0;
            }
            $patterns[$normalized]++;
        }
        
        // Find patterns that repeat more than 10 times
        $suspicious = array_filter($patterns, fn($count) => $count > 10);
        
        if (!empty($suspicious)) {
            $this->logger->warning('Potential N+1 query problem detected', [
                'patterns' => $suspicious
            ]);
        }
        
        return $suspicious;
    }
    
    /**
     * Reset query log
     */
    public function reset(): void
    {
        $this->queryLog = [];
    }
    
    /**
     * Sanitize query for logging (remove sensitive data)
     */
    private function sanitizeQuery(string $query): string
    {
        // Remove actual values, keep structure
        $query = preg_replace('/\b\d{10,}\b/', '[NUMBER]', $query);
        $query = preg_replace("/'[^']{20,}'/", "'[STRING]'", $query);
        return substr($query, 0, 500); // Limit length
    }
    
    /**
     * Normalize query for pattern detection
     */
    private function normalizeQuery(string $query): string
    {
        // Remove all values, keep only structure
        $query = preg_replace('/\b\d+\b/', '?', $query);
        $query = preg_replace("/'[^']*'/", '?', $query);
        $query = preg_replace('/\s+/', ' ', $query);
        return trim($query);
    }
    
    /**
     * Export statistics for monitoring systems
     */
    public function exportMetrics(): array
    {
        $stats = $this->getStatistics();
        
        return [
            'db_queries_total' => $stats['total_queries'],
            'db_queries_slow_total' => $stats['slow_queries'],
            'db_queries_failed_total' => $stats['failed_queries'],
            'db_query_duration_seconds' => $stats['total_time'],
            'db_query_duration_avg_seconds' => $stats['avg_time'],
            'db_query_memory_bytes' => $stats['memory_total']
        ];
    }
}
