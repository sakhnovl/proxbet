<?php

declare(strict_types=1);

namespace Proxbet\Core;

use Proxbet\Line\Logger;

/**
 * Graceful shutdown handler for long-running processes
 */
class GracefulShutdown
{
    private static bool $shutdownRequested = false;
    private static array $cleanupCallbacks = [];
    private static bool $registered = false;

    /**
     * Register signal handlers for graceful shutdown
     */
    public static function register(): void
    {
        if (self::$registered) {
            return;
        }

        if (!function_exists('pcntl_signal')) {
            Logger::warning('pcntl extension not available, graceful shutdown disabled');
            return;
        }

        pcntl_async_signals(true);

        pcntl_signal(SIGTERM, [self::class, 'handleSignal']);
        pcntl_signal(SIGINT, [self::class, 'handleSignal']);
        pcntl_signal(SIGHUP, [self::class, 'handleSignal']);

        self::$registered = true;
        Logger::info('Graceful shutdown handlers registered');
    }

    /**
     * Handle shutdown signals
     */
    public static function handleSignal(int $signal): void
    {
        $signalName = match ($signal) {
            SIGTERM => 'SIGTERM',
            SIGINT => 'SIGINT',
            SIGHUP => 'SIGHUP',
            default => "Signal $signal",
        };

        Logger::info("Received $signalName, initiating graceful shutdown");
        self::$shutdownRequested = true;
    }

    /**
     * Check if shutdown was requested
     */
    public static function isShutdownRequested(): bool
    {
        return self::$shutdownRequested;
    }

    /**
     * Register cleanup callback to run on shutdown
     */
    public static function onShutdown(callable $callback): void
    {
        self::$cleanupCallbacks[] = $callback;
    }

    /**
     * Execute all cleanup callbacks
     */
    public static function cleanup(): void
    {
        Logger::info('Running cleanup callbacks', ['count' => count(self::$cleanupCallbacks)]);

        foreach (self::$cleanupCallbacks as $callback) {
            try {
                $callback();
            } catch (\Throwable $e) {
                Logger::error('Cleanup callback failed', ['error' => $e->getMessage()]);
            }
        }

        Logger::info('Graceful shutdown completed');
    }

    /**
     * Request shutdown programmatically
     */
    public static function requestShutdown(): void
    {
        self::$shutdownRequested = true;
    }
}
