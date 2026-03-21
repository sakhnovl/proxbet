<?php

declare(strict_types=1);

namespace Proxbet\Security;

/**
 * Per-user rate limiting for Telegram bot
 * Prevents flood attacks and abuse
 */
final class TelegramRateLimiter
{
    private string $storageDir;
    private int $maxCommands;
    private int $windowSeconds;

    public function __construct(
        string $storageDir,
        int $maxCommands = 10,
        int $windowSeconds = 60
    ) {
        $this->storageDir = $storageDir;
        $this->maxCommands = $maxCommands;
        $this->windowSeconds = $windowSeconds;

        if (!is_dir($storageDir)) {
            mkdir($storageDir, 0755, true);
        }
    }

    /**
     * Check if user can execute command
     */
    public function checkUser(int $userId): bool
    {
        $file = $this->getUserFile($userId);
        $now = time();

        // Read existing attempts
        $attempts = $this->readAttempts($file);

        // Remove old attempts outside window
        $attempts = array_filter($attempts, fn($timestamp) => $timestamp > $now - $this->windowSeconds);

        // Check if limit exceeded
        if (count($attempts) >= $this->maxCommands) {
            return false;
        }

        // Add new attempt
        $attempts[] = $now;
        $this->writeAttempts($file, $attempts);

        return true;
    }

    /**
     * Get remaining commands for user
     */
    public function getRemainingCommands(int $userId): int
    {
        $file = $this->getUserFile($userId);
        $now = time();

        $attempts = $this->readAttempts($file);
        $attempts = array_filter($attempts, fn($timestamp) => $timestamp > $now - $this->windowSeconds);

        return max(0, $this->maxCommands - count($attempts));
    }

    /**
     * Get time until rate limit resets
     */
    public function getResetTime(int $userId): int
    {
        $file = $this->getUserFile($userId);
        $now = time();

        $attempts = $this->readAttempts($file);
        $attempts = array_filter($attempts, fn($timestamp) => $timestamp > $now - $this->windowSeconds);

        if (empty($attempts)) {
            return 0;
        }

        $oldestAttempt = min($attempts);
        return max(0, ($oldestAttempt + $this->windowSeconds) - $now);
    }

    /**
     * Reset rate limit for user (admin action)
     */
    public function resetUser(int $userId): void
    {
        $file = $this->getUserFile($userId);
        if (file_exists($file)) {
            unlink($file);
        }
    }

    private function getUserFile(int $userId): string
    {
        return $this->storageDir . '/telegram_rate_' . $userId . '.json';
    }

    private function readAttempts(string $file): array
    {
        if (!file_exists($file)) {
            return [];
        }

        $content = file_get_contents($file);
        if ($content === false) {
            return [];
        }

        $decoded = json_decode($content, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function writeAttempts(string $file, array $attempts): void
    {
        file_put_contents($file, json_encode($attempts, JSON_THROW_ON_ERROR));
    }
}
