<?php

declare(strict_types=1);

namespace Proxbet\Core;

/**
 * Prometheus metrics collector
 * Collects and exposes metrics in Prometheus format
 */
final class PrometheusMetrics
{
    private static ?self $instance = null;
    
    /** @var array<string,int> */
    private array $counters = [];
    
    /** @var array<string,float> */
    private array $gauges = [];
    
    /** @var array<string,array<string,mixed>> */
    private array $histograms = [];
    
    /** @var array<string,string> */
    private array $help = [];
    
    /** @var array<string,string> */
    private array $type = [];

    private function __construct()
    {
        $this->initializeMetrics();
    }

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function initializeMetrics(): void
    {
        // HTTP metrics
        $this->registerCounter('http_requests_total', 'Total HTTP requests');
        $this->registerCounter('http_requests_errors_total', 'Total HTTP request errors');
        $this->registerHistogram('http_request_duration_seconds', 'HTTP request duration');
        
        // Database metrics
        $this->registerCounter('db_queries_total', 'Total database queries');
        $this->registerCounter('db_queries_errors_total', 'Total database query errors');
        $this->registerHistogram('db_query_duration_seconds', 'Database query duration');
        
        // Business metrics
        $this->registerCounter('matches_processed_total', 'Total matches processed');
        $this->registerCounter('signals_sent_total', 'Total signals sent to Telegram');
        $this->registerCounter('bets_won_total', 'Total bets won');
        $this->registerCounter('bets_lost_total', 'Total bets lost');
        $this->registerGauge('active_matches', 'Number of active matches');
        $this->registerGauge('active_bans', 'Number of active bans');
        
        // AI metrics
        $this->registerCounter('ai_requests_total', 'Total AI analysis requests');
        $this->registerCounter('ai_requests_cached_total', 'Total cached AI responses');
        $this->registerHistogram('ai_request_duration_seconds', 'AI request duration');
        
        // Cache metrics
        $this->registerCounter('cache_hits_total', 'Total cache hits');
        $this->registerCounter('cache_misses_total', 'Total cache misses');
        
        // Circuit breaker metrics
        $this->registerGauge('circuit_breaker_open', 'Circuit breaker state (1=open, 0=closed)');
        $this->registerCounter('circuit_breaker_trips_total', 'Total circuit breaker trips');
    }

    private function registerCounter(string $name, string $help): void
    {
        $this->counters[$name] = 0;
        $this->help[$name] = $help;
        $this->type[$name] = 'counter';
    }

    private function registerGauge(string $name, string $help): void
    {
        $this->gauges[$name] = 0.0;
        $this->help[$name] = $help;
        $this->type[$name] = 'gauge';
    }

    private function registerHistogram(string $name, string $help): void
    {
        $this->histograms[$name] = [
            'sum' => 0.0,
            'count' => 0,
            'buckets' => [0.005 => 0, 0.01 => 0, 0.025 => 0, 0.05 => 0, 0.1 => 0, 0.25 => 0, 0.5 => 0, 1.0 => 0, 2.5 => 0, 5.0 => 0, 10.0 => 0],
        ];
        $this->help[$name] = $help;
        $this->type[$name] = 'histogram';
    }

    public function incrementCounter(string $name, int $value = 1): void
    {
        if (isset($this->counters[$name])) {
            $this->counters[$name] += $value;
        }
    }

    public function setGauge(string $name, float $value): void
    {
        if (isset($this->gauges[$name])) {
            $this->gauges[$name] = $value;
        }
    }

    public function observeHistogram(string $name, float $value): void
    {
        if (!isset($this->histograms[$name])) {
            return;
        }

        $this->histograms[$name]['sum'] += $value;
        $this->histograms[$name]['count']++;

        foreach ($this->histograms[$name]['buckets'] as $bucket => $count) {
            if ($value <= $bucket) {
                $this->histograms[$name]['buckets'][$bucket]++;
            }
        }
    }

    /**
     * Measure execution time and record to histogram
     * @param callable $callback
     * @return mixed
     */
    public function measureTime(string $metricName, callable $callback)
    {
        $start = microtime(true);
        try {
            return $callback();
        } finally {
            $duration = microtime(true) - $start;
            $this->observeHistogram($metricName, $duration);
        }
    }

    /**
     * Export metrics in Prometheus format
     */
    public function export(): string
    {
        $output = [];

        // Export counters
        foreach ($this->counters as $name => $value) {
            $output[] = sprintf("# HELP %s %s", $name, $this->help[$name]);
            $output[] = sprintf("# TYPE %s %s", $name, $this->type[$name]);
            $output[] = sprintf("%s %d", $name, $value);
        }

        // Export gauges
        foreach ($this->gauges as $name => $value) {
            $output[] = sprintf("# HELP %s %s", $name, $this->help[$name]);
            $output[] = sprintf("# TYPE %s %s", $name, $this->type[$name]);
            $output[] = sprintf("%s %.2f", $name, $value);
        }

        // Export histograms
        foreach ($this->histograms as $name => $data) {
            $output[] = sprintf("# HELP %s %s", $name, $this->help[$name]);
            $output[] = sprintf("# TYPE %s %s", $name, $this->type[$name]);
            
            foreach ($data['buckets'] as $bucket => $count) {
                $output[] = sprintf('%s_bucket{le="%.3f"} %d', $name, $bucket, $count);
            }
            $output[] = sprintf('%s_bucket{le="+Inf"} %d', $name, $data['count']);
            $output[] = sprintf('%s_sum %.6f', $name, $data['sum']);
            $output[] = sprintf('%s_count %d', $name, $data['count']);
        }

        return implode("\n", $output) . "\n";
    }

    /**
     * Get current metrics as array
     * @return array<string,mixed>
     */
    public function getMetrics(): array
    {
        return [
            'counters' => $this->counters,
            'gauges' => $this->gauges,
            'histograms' => $this->histograms,
        ];
    }
}
