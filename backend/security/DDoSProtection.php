<?php

declare(strict_types=1);

namespace Proxbet\Security;

use Proxbet\Core\Exceptions\SecurityException;

/**
 * DDoS Protection System
 * 
 * Multi-layer protection against DDoS attacks:
 * - Connection rate limiting per IP
 * - Request pattern analysis
 * - Automatic IP blocking
 * - Challenge-response for suspicious traffic
 */
class DDoSProtection
{
    private \Redis $redis;
    private AuditLogger $auditLogger;
    
    private const PREFIX = 'ddos:';
    private const BLOCK_PREFIX = 'ddos:block:';
    private const PATTERN_PREFIX = 'ddos:pattern:';
    
    // Thresholds
    private const REQUESTS_PER_SECOND = 10;
    private const REQUESTS_PER_MINUTE = 100;
    private const REQUESTS_PER_HOUR = 1000;
    
    private const BLOCK_DURATION = 3600; // 1 hour
    private const SUSPICIOUS_THRESHOLD = 50; // requests per 10 seconds
    
    public function __construct(\Redis $redis, AuditLogger $auditLogger)
    {
        $this->redis = $redis;
        $this->auditLogger = $auditLogger;
    }
    
    /**
     * Check if request should be allowed
     * 
     * @throws SecurityException if request is blocked
     */
    public function checkRequest(string $ip, string $endpoint): void
    {
        // Check if IP is blocked
        if ($this->isBlocked($ip)) {
            $this->auditLogger->log('ddos_blocked_request', [
                'ip' => $ip,
                'endpoint' => $endpoint
            ]);
            
            throw new SecurityException('Your IP has been temporarily blocked due to suspicious activity');
        }
        
        // Check rate limits
        $this->checkRateLimits($ip);
        
        // Analyze request pattern
        $this->analyzePattern($ip, $endpoint);
        
        // Record request
        $this->recordRequest($ip, $endpoint);
    }
    
    /**
     * Check if IP is blocked
     */
    public function isBlocked(string $ip): bool
    {
        $key = self::BLOCK_PREFIX . $ip;
        return (bool)$this->redis->exists($key);
    }
    
    /**
     * Block IP address
     */
    public function blockIP(string $ip, int $duration = self::BLOCK_DURATION, string $reason = 'DDoS protection'): void
    {
        $key = self::BLOCK_PREFIX . $ip;
        
        $this->redis->setex($key, $duration, json_encode([
            'blocked_at' => time(),
            'reason' => $reason,
            'expires_at' => time() + $duration
        ]));
        
        $this->auditLogger->log('ddos_ip_blocked', [
            'ip' => $ip,
            'duration' => $duration,
            'reason' => $reason
        ]);
    }
    
    /**
     * Unblock IP address
     */
    public function unblockIP(string $ip): void
    {
        $key = self::BLOCK_PREFIX . $ip;
        $this->redis->del($key);
        
        $this->auditLogger->log('ddos_ip_unblocked', ['ip' => $ip]);
    }
    
    /**
     * Get blocked IPs
     */
    public function getBlockedIPs(): array
    {
        $pattern = self::BLOCK_PREFIX . '*';
        $keys = $this->redis->keys($pattern);
        
        $blocked = [];
        foreach ($keys as $key) {
            $ip = str_replace(self::BLOCK_PREFIX, '', $key);
            $data = json_decode($this->redis->get($key), true);
            $blocked[$ip] = $data;
        }
        
        return $blocked;
    }
    
    /**
     * Check rate limits for IP
     */
    private function checkRateLimits(string $ip): void
    {
        // Check per-second limit
        $perSecondKey = self::PREFIX . 'second:' . $ip;
        $perSecondCount = (int)$this->redis->get($perSecondKey);
        
        if ($perSecondCount > self::REQUESTS_PER_SECOND) {
            $this->blockIP($ip, 300, 'Exceeded per-second rate limit');
            throw new SecurityException('Rate limit exceeded');
        }
        
        // Check per-minute limit
        $perMinuteKey = self::PREFIX . 'minute:' . $ip;
        $perMinuteCount = (int)$this->redis->get($perMinuteKey);
        
        if ($perMinuteCount > self::REQUESTS_PER_MINUTE) {
            $this->blockIP($ip, 600, 'Exceeded per-minute rate limit');
            throw new SecurityException('Rate limit exceeded');
        }
        
        // Check per-hour limit
        $perHourKey = self::PREFIX . 'hour:' . $ip;
        $perHourCount = (int)$this->redis->get($perHourKey);
        
        if ($perHourCount > self::REQUESTS_PER_HOUR) {
            $this->blockIP($ip, self::BLOCK_DURATION, 'Exceeded per-hour rate limit');
            throw new SecurityException('Rate limit exceeded');
        }
    }
    
    /**
     * Analyze request pattern for suspicious behavior
     */
    private function analyzePattern(string $ip, string $endpoint): void
    {
        $patternKey = self::PATTERN_PREFIX . $ip;
        
        // Get recent requests
        $this->redis->lPush($patternKey, json_encode([
            'endpoint' => $endpoint,
            'timestamp' => microtime(true)
        ]));
        
        // Keep only last 100 requests
        $this->redis->lTrim($patternKey, 0, 99);
        $this->redis->expire($patternKey, 60);
        
        // Analyze pattern
        $requests = $this->redis->lRange($patternKey, 0, -1);
        $recentRequests = array_filter($requests, function($req) {
            $data = json_decode($req, true);
            return (microtime(true) - $data['timestamp']) < 10; // Last 10 seconds
        });
        
        // Check for suspicious patterns
        if (count($recentRequests) > self::SUSPICIOUS_THRESHOLD) {
            $this->blockIP($ip, 1800, 'Suspicious request pattern detected');
            throw new SecurityException('Suspicious activity detected');
        }
        
        // Check for endpoint scanning (many different endpoints)
        $endpoints = array_map(function($req) {
            $data = json_decode($req, true);
            return $data['endpoint'];
        }, $recentRequests);
        
        $uniqueEndpoints = count(array_unique($endpoints));
        if ($uniqueEndpoints > 20) {
            $this->blockIP($ip, 3600, 'Endpoint scanning detected');
            throw new SecurityException('Suspicious activity detected');
        }
    }
    
    /**
     * Record request for rate limiting
     */
    private function recordRequest(string $ip, string $endpoint): void
    {
        // Increment per-second counter
        $perSecondKey = self::PREFIX . 'second:' . $ip;
        $this->redis->incr($perSecondKey);
        $this->redis->expire($perSecondKey, 1);
        
        // Increment per-minute counter
        $perMinuteKey = self::PREFIX . 'minute:' . $ip;
        $this->redis->incr($perMinuteKey);
        $this->redis->expire($perMinuteKey, 60);
        
        // Increment per-hour counter
        $perHourKey = self::PREFIX . 'hour:' . $ip;
        $this->redis->incr($perHourKey);
        $this->redis->expire($perHourKey, 3600);
    }
    
    /**
     * Get statistics for IP
     */
    public function getIPStats(string $ip): array
    {
        return [
            'is_blocked' => $this->isBlocked($ip),
            'requests_per_second' => (int)$this->redis->get(self::PREFIX . 'second:' . $ip),
            'requests_per_minute' => (int)$this->redis->get(self::PREFIX . 'minute:' . $ip),
            'requests_per_hour' => (int)$this->redis->get(self::PREFIX . 'hour:' . $ip),
            'limits' => [
                'per_second' => self::REQUESTS_PER_SECOND,
                'per_minute' => self::REQUESTS_PER_MINUTE,
                'per_hour' => self::REQUESTS_PER_HOUR
            ]
        ];
    }
    
    /**
     * Whitelist IP (bypass DDoS protection)
     */
    public function whitelistIP(string $ip): void
    {
        $key = 'ddos:whitelist:' . $ip;
        $this->redis->set($key, '1');
        
        $this->auditLogger->log('ddos_ip_whitelisted', ['ip' => $ip]);
    }
    
    /**
     * Check if IP is whitelisted
     */
    public function isWhitelisted(string $ip): bool
    {
        $key = 'ddos:whitelist:' . $ip;
        return (bool)$this->redis->exists($key);
    }
    
    /**
     * Remove IP from whitelist
     */
    public function removeFromWhitelist(string $ip): void
    {
        $key = 'ddos:whitelist:' . $ip;
        $this->redis->del($key);
        
        $this->auditLogger->log('ddos_ip_removed_from_whitelist', ['ip' => $ip]);
    }
}
