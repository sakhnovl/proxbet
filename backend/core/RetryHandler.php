<?php

declare(strict_types=1);

namespace Proxbet\Core;

use Proxbet\Line\Logger;

/**
 * Retry handler with exponential backoff and jitter
 */
class RetryHandler
{
    private int $maxAttempts;
    private int $baseDelayMs;
    private int $maxDelayMs;
    private float $jitterFactor;
    private array $retryableExceptions;

    public function __construct(
        int $maxAttempts = 3,
        int $baseDelayMs = 1000,
        int $maxDelayMs = 30000,
        float $jitterFactor = 0.1,
        array $retryableExceptions = []
    ) {
        $this->maxAttempts = max(1, $maxAttempts);
        $this->baseDelayMs = max(0, $baseDelayMs);
        $this->maxDelayMs = max($baseDelayMs, $maxDelayMs);
        $this->jitterFactor = max(0.0, min(1.0, $jitterFactor));
        $this->retryableExceptions = $retryableExceptions ?: [\RuntimeException::class, \Exception::class];
    }

    /**
     * Execute callable with retry logic
     */
    public function execute(callable $callback, ?string $operationName = null): mixed
    {
        $attempt = 0;
        $lastException = null;

        while ($attempt < $this->maxAttempts) {
            $attempt++;

            try {
                return $callback();
            } catch (\Throwable $e) {
                $lastException = $e;

                if (!$this->isRetryable($e)) {
                    Logger::warning('Non-retryable exception, failing immediately', [
                        'operation' => $operationName,
                        'exception' => get_class($e),
                        'message' => $e->getMessage(),
                    ]);
                    throw $e;
                }

                if ($attempt >= $this->maxAttempts) {
                    Logger::error('Max retry attempts reached', [
                        'operation' => $operationName,
                        'attempts' => $attempt,
                        'exception' => get_class($e),
                        'message' => $e->getMessage(),
                    ]);
                    break;
                }

                $delayMs = $this->calculateDelay($attempt);
                
                Logger::warning('Operation failed, retrying', [
                    'operation' => $operationName,
                    'attempt' => $attempt,
                    'max_attempts' => $this->maxAttempts,
                    'delay_ms' => $delayMs,
                    'exception' => get_class($e),
                    'message' => $e->getMessage(),
                ]);

                usleep($delayMs * 1000);
            }
        }

        throw $lastException ?? new \RuntimeException('Retry failed without exception');
    }

    /**
     * Check if exception is retryable
     */
    private function isRetryable(\Throwable $e): bool
    {
        foreach ($this->retryableExceptions as $exceptionClass) {
            if ($e instanceof $exceptionClass) {
                return true;
            }
        }
        return false;
    }

    /**
     * Calculate delay with exponential backoff and jitter
     */
    private function calculateDelay(int $attempt): int
    {
        // Exponential backoff: baseDelay * 2^(attempt-1)
        $exponentialDelay = $this->baseDelayMs * (2 ** ($attempt - 1));
        
        // Cap at max delay
        $delay = min($exponentialDelay, $this->maxDelayMs);
        
        // Add jitter to prevent thundering herd
        $jitter = $delay * $this->jitterFactor * (mt_rand() / mt_getrandmax());
        
        return (int) ($delay + $jitter);
    }

    /**
     * Create retry handler from environment
     */
    public static function fromEnv(string $prefix = 'RETRY_'): self
    {
        $maxAttempts = (int) (getenv($prefix . 'MAX_ATTEMPTS') ?: 3);
        $baseDelayMs = (int) (getenv($prefix . 'BASE_DELAY_MS') ?: 1000);
        $maxDelayMs = (int) (getenv($prefix . 'MAX_DELAY_MS') ?: 30000);
        $jitterFactor = (float) (getenv($prefix . 'JITTER_FACTOR') ?: 0.1);

        return new self($maxAttempts, $baseDelayMs, $maxDelayMs, $jitterFactor);
    }
}
