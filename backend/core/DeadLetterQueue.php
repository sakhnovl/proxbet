<?php

declare(strict_types=1);

namespace Proxbet\Core;

/**
 * Dead Letter Queue for failed tasks
 * Stores failed tasks for later reprocessing
 */
class DeadLetterQueue
{
    private \PDO $pdo;
    private StructuredLogger $logger;
    private int $maxRetries;
    private int $retryDelaySeconds;

    public function __construct(
        \PDO $pdo,
        StructuredLogger $logger,
        int $maxRetries = 3,
        int $retryDelaySeconds = 300
    ) {
        $this->pdo = $pdo;
        $this->logger = $logger;
        $this->maxRetries = $maxRetries;
        $this->retryDelaySeconds = $retryDelaySeconds;
        $this->ensureTableExists();
    }

    /**
     * Add failed task to dead letter queue
     */
    public function add(string $taskType, array $payload, string $errorMessage, ?\Throwable $exception = null): void
    {
        $stmt = $this->pdo->prepare("
            INSERT INTO dead_letter_queue (task_type, payload, error_message, stack_trace, retry_count, next_retry_at)
            VALUES (:task_type, :payload, :error_message, :stack_trace, 0, :next_retry_at)
        ");

        $stmt->execute([
            'task_type' => $taskType,
            'payload' => json_encode($payload),
            'error_message' => $errorMessage,
            'stack_trace' => $exception ? $exception->getTraceAsString() : null,
            'next_retry_at' => date('Y-m-d H:i:s', time() + $this->retryDelaySeconds)
        ]);

        $this->logger->warning('Task added to dead letter queue', [
            'task_type' => $taskType,
            'error' => $errorMessage,
            'dlq_id' => $this->pdo->lastInsertId()
        ]);
    }

    /**
     * Get tasks ready for retry
     */
    public function getRetryableTasks(int $limit = 10): array
    {
        $stmt = $this->pdo->prepare("
            SELECT * FROM dead_letter_queue
            WHERE retry_count < :max_retries
            AND next_retry_at <= NOW()
            AND status = 'pending'
            ORDER BY created_at ASC
            LIMIT :limit
        ");

        $stmt->execute([
            'max_retries' => $this->maxRetries,
            'limit' => $limit
        ]);

        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * Mark task as retrying
     */
    public function markRetrying(int $id): void
    {
        $stmt = $this->pdo->prepare("
            UPDATE dead_letter_queue
            SET retry_count = retry_count + 1,
                next_retry_at = :next_retry_at,
                status = 'retrying',
                updated_at = NOW()
            WHERE id = :id
        ");

        $backoffSeconds = $this->retryDelaySeconds * pow(2, $this->getRetryCount($id));
        $stmt->execute([
            'id' => $id,
            'next_retry_at' => date('Y-m-d H:i:s', time() + $backoffSeconds)
        ]);
    }

    /**
     * Mark task as completed
     */
    public function markCompleted(int $id): void
    {
        $stmt = $this->pdo->prepare("
            UPDATE dead_letter_queue
            SET status = 'completed',
                updated_at = NOW()
            WHERE id = :id
        ");

        $stmt->execute(['id' => $id]);

        $this->logger->info('Task recovered from dead letter queue', ['dlq_id' => $id]);
    }

    /**
     * Mark task as permanently failed
     */
    public function markFailed(int $id, string $finalError): void
    {
        $stmt = $this->pdo->prepare("
            UPDATE dead_letter_queue
            SET status = 'failed',
                error_message = :error_message,
                updated_at = NOW()
            WHERE id = :id
        ");

        $stmt->execute([
            'id' => $id,
            'error_message' => $finalError
        ]);

        $this->logger->error('Task permanently failed', [
            'dlq_id' => $id,
            'error' => $finalError
        ]);
    }

    /**
     * Get retry count for task
     */
    private function getRetryCount(int $id): int
    {
        $stmt = $this->pdo->prepare("SELECT retry_count FROM dead_letter_queue WHERE id = :id");
        $stmt->execute(['id' => $id]);
        return (int)$stmt->fetchColumn();
    }

    /**
     * Ensure table exists
     */
    private function ensureTableExists(): void
    {
        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS dead_letter_queue (
                id INT AUTO_INCREMENT PRIMARY KEY,
                task_type VARCHAR(100) NOT NULL,
                payload TEXT NOT NULL,
                error_message TEXT NOT NULL,
                stack_trace TEXT,
                retry_count INT DEFAULT 0,
                next_retry_at DATETIME NOT NULL,
                status ENUM('pending', 'retrying', 'completed', 'failed') DEFAULT 'pending',
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_retry (status, next_retry_at),
                INDEX idx_task_type (task_type)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    }

    /**
     * Clean up old completed/failed tasks
     */
    public function cleanup(int $daysOld = 30): int
    {
        $stmt = $this->pdo->prepare("
            DELETE FROM dead_letter_queue
            WHERE status IN ('completed', 'failed')
            AND updated_at < DATE_SUB(NOW(), INTERVAL :days DAY)
        ");

        $stmt->execute(['days' => $daysOld]);
        return $stmt->rowCount();
    }
}
