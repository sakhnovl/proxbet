<?php

declare(strict_types=1);

namespace Proxbet\Core;

use Proxbet\Line\Logger;

/**
 * Circuit Breaker pattern implementation for external service calls
 * 
 * States:
 * - CLOSED: Normal operation, requests pass through
 * - OPEN: Too many failures, requests fail fast
 * - HALF_OPEN: Testing if service recovered
 */
class CircuitBreaker
{
    private const STATE_CLOSED = 'closed';
    private const STATE_OPEN = 'open';
    private const STATE_HALF_OPEN = 'half_open';

    private string $serviceName;
    private int $failureThreshold;
    private int $recoveryTimeout;
    private int $successThreshold;
    private ?RedisStateManager $stateManager;

    public function __construct(
        string $serviceName,
        int $failureThreshold = 5,
        int $recoveryTimeout = 60,
        int $successThreshold = 2,
        ?RedisStateManager $stateManager = null
    ) {
        $this->serviceName = $serviceName;
        $this->failureThreshold = $failureThreshold;
        $this->recoveryTimeout = $recoveryTimeout;
        $this->successThreshold = $successThreshold;
        $this->stateManager = $stateManager;
    }

    /**
     * Execute callable with circuit breaker protection
     */
    public function call(callable $callback): mixed
    {
        $state = $this->getState();

        if ($state === self::STATE_OPEN) {
            if ($this->shouldAttemptRecovery()) {
                $this->setState(self::STATE_HALF_OPEN);
                Logger::info('Circuit breaker entering half-open state', ['service' => $this->serviceName]);
            } else {
                throw new \RuntimeException("Circuit breaker is OPEN for service: {$this->serviceName}");
            }
        }

        try {
            $result = $callback();
            $this->onSuccess();
            return $result;
        } catch (\Throwable $e) {
            $this->onFailure();
            throw $e;
        }
    }

    /**
     * Record successful call
     */
    private function onSuccess(): void
    {
        $state = $this->getState();

        if ($state === self::STATE_HALF_OPEN) {
            $successCount = $this->incrementSuccessCount();
            
            if ($successCount >= $this->successThreshold) {
                $this->setState(self::STATE_CLOSED);
                $this->resetCounters();
                Logger::info('Circuit breaker closed after recovery', ['service' => $this->serviceName]);
            }
        } elseif ($state === self::STATE_CLOSED) {
            $this->resetFailureCount();
        }
    }

    /**
     * Record failed call
     */
    private function onFailure(): void
    {
        $state = $this->getState();

        if ($state === self::STATE_HALF_OPEN) {
            $this->setState(self::STATE_OPEN);
            $this->setLastFailureTime(time());
            Logger::warning('Circuit breaker reopened after failed recovery', ['service' => $this->serviceName]);
            return;
        }

        $failureCount = $this->incrementFailureCount();

        if ($failureCount >= $this->failureThreshold) {
            $this->setState(self::STATE_OPEN);
            $this->setLastFailureTime(time());
            Logger::error('Circuit breaker opened due to failures', [
                'service' => $this->serviceName,
                'failures' => $failureCount,
                'threshold' => $this->failureThreshold,
            ]);
        }
    }

    /**
     * Check if should attempt recovery
     */
    private function shouldAttemptRecovery(): bool
    {
        $lastFailureTime = $this->getLastFailureTime();
        if ($lastFailureTime === null) {
            return true;
        }

        return (time() - $lastFailureTime) >= $this->recoveryTimeout;
    }

    /**
     * Get current state
     */
    private function getState(): string
    {
        if ($this->stateManager) {
            return $this->stateManager->get("cb:{$this->serviceName}:state", self::STATE_CLOSED);
        }
        return self::STATE_CLOSED;
    }

    /**
     * Set state
     */
    private function setState(string $state): void
    {
        if ($this->stateManager) {
            $this->stateManager->set("cb:{$this->serviceName}:state", $state, 3600);
        }
    }

    /**
     * Get failure count
     */
    private function getFailureCount(): int
    {
        if ($this->stateManager) {
            return (int) $this->stateManager->get("cb:{$this->serviceName}:failures", 0);
        }
        return 0;
    }

    /**
     * Increment failure count
     */
    private function incrementFailureCount(): int
    {
        if ($this->stateManager) {
            return $this->stateManager->increment("cb:{$this->serviceName}:failures");
        }
        return 0;
    }

    /**
     * Reset failure count
     */
    private function resetFailureCount(): void
    {
        if ($this->stateManager) {
            $this->stateManager->delete("cb:{$this->serviceName}:failures");
        }
    }

    /**
     * Increment success count
     */
    private function incrementSuccessCount(): int
    {
        if ($this->stateManager) {
            return $this->stateManager->increment("cb:{$this->serviceName}:successes");
        }
        return 0;
    }

    /**
     * Get last failure time
     */
    private function getLastFailureTime(): ?int
    {
        if ($this->stateManager) {
            return $this->stateManager->get("cb:{$this->serviceName}:last_failure");
        }
        return null;
    }

    /**
     * Set last failure time
     */
    private function setLastFailureTime(int $timestamp): void
    {
        if ($this->stateManager) {
            $this->stateManager->set("cb:{$this->serviceName}:last_failure", $timestamp, 3600);
        }
    }

    /**
     * Reset all counters
     */
    private function resetCounters(): void
    {
        if ($this->stateManager) {
            $this->stateManager->delete("cb:{$this->serviceName}:failures");
            $this->stateManager->delete("cb:{$this->serviceName}:successes");
            $this->stateManager->delete("cb:{$this->serviceName}:last_failure");
        }
    }

    /**
     * Get circuit breaker status
     */
    public function getStatus(): array
    {
        return [
            'service' => $this->serviceName,
            'state' => $this->getState(),
            'failures' => $this->getFailureCount(),
            'last_failure' => $this->getLastFailureTime(),
        ];
    }
}
