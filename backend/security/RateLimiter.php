<?php

declare(strict_types=1);

namespace Proxbet\Security;

/**
 * Simple file-based rate limiter for admin endpoints
 * For production, use Redis or Memcached
 */
final class RateLimiter
{
    private string $storageDir;
    private int $maxAttempts;
    private int $windowSeconds;

    public function __construct(
        string $storageDir,
        int $maxAttempts = 10,
        int $windowSeconds = 60
    ) {
        $this->storageDir = rtrim($storageDir, '/\\');
        $this->maxAttempts = $maxAttempts;
        $this->windowSeconds = $windowSeconds;

        if (!is_dir($this->storageDir)) {
            mkdir($this->storageDir, 0755, true);
        }
    }

    /**
     * Check if identifier (IP, user ID) is rate limited
     * Returns true if allowed, false if rate limited
     */
    public function check(string $identifier): bool
    {
        $key = $this->getKey($identifier);
        $attempts = $this->getAttempts($key);
        
        // Clean old attempts
        $now = time();
        $attempts = array_filter($attempts, fn($timestamp) => $timestamp > $now - $this->windowSeconds);

        if (count($attempts) >= $this->maxAttempts) {
            return false;
        }

        // Record this attempt
        $attempts[] = $now;
        $this->saveAttempts($key, $attempts);

        return true;
    }

    /**
     * Get remaining attempts for identifier
     */
    public function remaining(string $identifier): int
    {
        $key = $this->getKey($identifier);
        $attempts = $this->getAttempts($key);
        
        $now = time();
        $attempts = array_filter($attempts, fn($timestamp) => $timestamp > $now - $this->windowSeconds);

        return max(0, $this->maxAttempts - count($attempts));
    }

    /**
     * Reset rate limit for identifier
     */
    public function reset(string $identifier): void
    {
        $key = $this->getKey($identifier);
        $file = $this->getFilePath($key);
        if (file_exists($file)) {
            unlink($file);
        }
    }

    private function getKey(string $identifier): string
    {
        return hash('sha256', $identifier);
    }

    private function getFilePath(string $key): string
    {
        return $this->storageDir . '/' . $key . '.json';
    }

    /**
     * @return array<int>
     */
    private function getAttempts(string $key): array
    {
        $file = $this->getFilePath($key);
        if (!file_exists($file)) {
            return [];
        }

        $content = file_get_contents($file);
        if ($content === false) {
            return [];
        }

        $data = json_decode($content, true);
        return is_array($data) ? $data : [];
    }

    /**
     * @param array<int> $attempts
     */
    private function saveAttempts(string $key, array $attempts): void
    {
        $file = $this->getFilePath($key);
        file_put_contents($file, json_encode(array_values($attempts)));
    }
}
