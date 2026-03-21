<?php

declare(strict_types=1);

namespace Proxbet\Core;

use Proxbet\Line\Logger;

/**
 * Memory usage monitoring and management.
 */
class MemoryMonitor
{
    private static int $peakMemory = 0;
    private static int $checkpointMemory = 0;
    private static ?string $checkpointLabel = null;

    /**
     * Get current memory usage in bytes.
     */
    public static function getCurrentUsage(): int
    {
        return memory_get_usage(true);
    }

    /**
     * Get peak memory usage in bytes.
     */
    public static function getPeakUsage(): int
    {
        return memory_get_peak_usage(true);
    }

    /**
     * Get current memory usage in human-readable format.
     */
    public static function getCurrentUsageFormatted(): string
    {
        return self::formatBytes(self::getCurrentUsage());
    }

    /**
     * Get peak memory usage in human-readable format.
     */
    public static function getPeakUsageFormatted(): string
    {
        return self::formatBytes(self::getPeakUsage());
    }

    /**
     * Set a memory checkpoint for tracking.
     */
    public static function checkpoint(string $label): void
    {
        self::$checkpointMemory = self::getCurrentUsage();
        self::$checkpointLabel = $label;
        
        $peak = self::getPeakUsage();
        if ($peak > self::$peakMemory) {
            self::$peakMemory = $peak;
        }

        Logger::debug('Memory checkpoint', [
            'label' => $label,
            'current' => self::formatBytes(self::$checkpointMemory),
            'peak' => self::formatBytes($peak),
        ]);
    }

    /**
     * Get memory usage since last checkpoint.
     */
    public static function getSinceCheckpoint(): int
    {
        if (self::$checkpointMemory === 0) {
            return 0;
        }
        return self::getCurrentUsage() - self::$checkpointMemory;
    }

    /**
     * Log memory usage since last checkpoint.
     */
    public static function logSinceCheckpoint(string $label): void
    {
        $diff = self::getSinceCheckpoint();
        $current = self::getCurrentUsage();
        
        Logger::info('Memory usage', [
            'label' => $label,
            'since_checkpoint' => self::formatBytes($diff),
            'checkpoint_label' => self::$checkpointLabel,
            'current' => self::formatBytes($current),
            'peak' => self::getPeakUsageFormatted(),
        ]);
    }

    /**
     * Check if memory usage exceeds threshold.
     *
     * @param int $thresholdBytes Memory threshold in bytes
     * @return bool True if threshold exceeded
     */
    public static function isThresholdExceeded(int $thresholdBytes): bool
    {
        return self::getCurrentUsage() > $thresholdBytes;
    }

    /**
     * Check if memory usage exceeds percentage of limit.
     *
     * @param float $percentage Percentage (0.0 to 1.0)
     * @return bool True if threshold exceeded
     */
    public static function isPercentageExceeded(float $percentage): bool
    {
        $limit = self::getMemoryLimit();
        if ($limit === -1) {
            return false; // No limit
        }
        
        $threshold = (int) ($limit * $percentage);
        return self::getCurrentUsage() > $threshold;
    }

    /**
     * Get PHP memory limit in bytes.
     *
     * @return int Memory limit in bytes, or -1 if unlimited
     */
    public static function getMemoryLimit(): int
    {
        $limit = ini_get('memory_limit');
        if ($limit === '-1') {
            return -1;
        }
        
        return self::parseMemoryLimit($limit);
    }

    /**
     * Format bytes to human-readable string.
     */
    public static function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        $bytes /= (1 << (10 * $pow));
        
        return round($bytes, 2) . ' ' . $units[$pow];
    }

    /**
     * Parse memory limit string to bytes.
     */
    private static function parseMemoryLimit(string $limit): int
    {
        $limit = trim($limit);
        $last = strtolower($limit[strlen($limit) - 1]);
        $value = (int) $limit;
        
        switch ($last) {
            case 'g':
                $value *= 1024;
                // fall through
            case 'm':
                $value *= 1024;
                // fall through
            case 'k':
                $value *= 1024;
        }
        
        return $value;
    }

    /**
     * Force garbage collection and log memory freed.
     */
    public static function gc(string $label = 'GC'): void
    {
        $before = self::getCurrentUsage();
        gc_collect_cycles();
        $after = self::getCurrentUsage();
        $freed = $before - $after;
        
        Logger::debug('Garbage collection', [
            'label' => $label,
            'freed' => self::formatBytes($freed),
            'before' => self::formatBytes($before),
            'after' => self::formatBytes($after),
        ]);
    }
}
