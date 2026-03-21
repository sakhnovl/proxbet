<?php

declare(strict_types=1);

namespace Proxbet\Telegram;

use Proxbet\Core\StructuredLogger;
use Proxbet\Core\DeadLetterQueue;

/**
 * Telegram Bot Error Handler
 * Handles errors gracefully without crashing the bot
 */
class TelegramErrorHandler
{
    private StructuredLogger $logger;
    private DeadLetterQueue $dlq;
    private array $errorCounts = [];
    private int $maxErrorsPerMinute = 10;

    public function __construct(StructuredLogger $logger, DeadLetterQueue $dlq)
    {
        $this->logger = $logger;
        $this->dlq = $dlq;
    }

    /**
     * Handle Telegram API error
     */
    public function handleApiError(\Throwable $e, array $context = []): void
    {
        $errorType = $this->classifyError($e);

        $this->logger->error('Telegram API error', [
            'error_type' => $errorType,
            'message' => $e->getMessage(),
            'code' => $e->getCode(),
            'context' => $context
        ]);

        // Add to dead letter queue for retry if recoverable
        if ($this->isRecoverableError($errorType)) {
            $this->dlq->add('telegram_api_call', $context, $e->getMessage(), $e);
        }

        // Check if we're getting too many errors
        if ($this->isErrorRateTooHigh()) {
            $this->logger->critical('Telegram bot error rate too high, consider emergency shutdown');
        }
    }

    /**
     * Handle message processing error
     */
    public function handleMessageError(\Throwable $e, array $update): void
    {
        $this->logger->error('Telegram message processing error', [
            'error' => $e->getMessage(),
            'update_id' => $update['update_id'] ?? null,
            'chat_id' => $update['message']['chat']['id'] ?? null
        ]);

        // Don't crash the bot, just log and continue
        $this->incrementErrorCount();
    }

    /**
     * Handle command execution error
     */
    public function handleCommandError(\Throwable $e, string $command, array $context): void
    {
        $this->logger->error('Telegram command execution error', [
            'command' => $command,
            'error' => $e->getMessage(),
            'context' => $context
        ]);

        // Add to DLQ for commands that should be retried
        if ($this->shouldRetryCommand($command)) {
            $this->dlq->add('telegram_command', [
                'command' => $command,
                'context' => $context
            ], $e->getMessage(), $e);
        }
    }

    /**
     * Classify error type
     */
    private function classifyError(\Throwable $e): string
    {
        $message = $e->getMessage();
        $code = $e->getCode();

        // Network errors
        if (str_contains($message, 'Connection') || str_contains($message, 'timeout')) {
            return 'network_error';
        }

        // Rate limiting
        if ($code === 429 || str_contains($message, 'Too Many Requests')) {
            return 'rate_limit';
        }

        // Bot blocked
        if ($code === 403 || str_contains($message, 'bot was blocked')) {
            return 'bot_blocked';
        }

        // Invalid request
        if ($code === 400) {
            return 'invalid_request';
        }

        // Server error
        if ($code >= 500) {
            return 'server_error';
        }

        return 'unknown_error';
    }

    /**
     * Check if error is recoverable
     */
    private function isRecoverableError(string $errorType): bool
    {
        return in_array($errorType, [
            'network_error',
            'rate_limit',
            'server_error'
        ], true);
    }

    /**
     * Check if command should be retried
     */
    private function shouldRetryCommand(string $command): bool
    {
        // Retry important commands
        $retryableCommands = ['send_signal', 'update_balance', 'process_bet'];
        return in_array($command, $retryableCommands, true);
    }

    /**
     * Increment error count
     */
    private function incrementErrorCount(): void
    {
        $minute = (int)(time() / 60);
        if (!isset($this->errorCounts[$minute])) {
            $this->errorCounts[$minute] = 0;
            // Clean old entries
            $this->errorCounts = array_filter(
                $this->errorCounts,
                fn($key) => $key >= $minute - 5,
                ARRAY_FILTER_USE_KEY
            );
        }
        $this->errorCounts[$minute]++;
    }

    /**
     * Check if error rate is too high
     */
    private function isErrorRateTooHigh(): bool
    {
        $minute = (int)(time() / 60);
        return ($this->errorCounts[$minute] ?? 0) > $this->maxErrorsPerMinute;
    }

    /**
     * Get error statistics
     */
    public function getErrorStats(): array
    {
        $totalErrors = array_sum($this->errorCounts);
        return [
            'total_errors_last_5min' => $totalErrors,
            'current_minute_errors' => $this->errorCounts[(int)(time() / 60)] ?? 0,
            'is_rate_too_high' => $this->isErrorRateTooHigh()
        ];
    }
}
