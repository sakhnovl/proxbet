<?php

declare(strict_types=1);

namespace Proxbet\Telegram;

use PDO;

/**
 * Кэширование результатов AI-анализа для повторного использования
 */
final class AnalysisCache
{
    public function __construct(
        private PDO $pdo,
        private int $ttlSeconds = 3600
    ) {
    }

    /**
     * Получить закэшированный анализ
     * 
     * @return array{response:string,created_at:string}|null
     */
    public function get(int $matchId, int $algorithmId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT `response_text`, `created_at` '
            . 'FROM `ai_analysis_cache` '
            . 'WHERE `match_id` = ? AND `algorithm_id` = ? '
            . 'AND `created_at` > DATE_SUB(NOW(), INTERVAL ? SECOND) '
            . 'LIMIT 1'
        );
        $stmt->execute([$matchId, $algorithmId, $this->ttlSeconds]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }

        return [
            'response' => (string) ($row['response_text'] ?? ''),
            'created_at' => (string) ($row['created_at'] ?? ''),
        ];
    }

    /**
     * Сохранить результат анализа в кэш
     */
    public function set(int $matchId, int $algorithmId, string $response): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO `ai_analysis_cache` (`match_id`, `algorithm_id`, `response_text`, `created_at`) '
            . 'VALUES (?, ?, ?, NOW()) '
            . 'ON DUPLICATE KEY UPDATE '
            . '`response_text` = VALUES(`response_text`), '
            . '`created_at` = NOW()'
        );
        $stmt->execute([$matchId, $algorithmId, $response]);
    }

    /**
     * Инвалидировать кэш для матча (например, при обновлении live-данных)
     */
    public function invalidate(int $matchId, ?int $algorithmId = null): void
    {
        if ($algorithmId !== null) {
            $stmt = $this->pdo->prepare(
                'DELETE FROM `ai_analysis_cache` WHERE `match_id` = ? AND `algorithm_id` = ?'
            );
            $stmt->execute([$matchId, $algorithmId]);
        } else {
            $stmt = $this->pdo->prepare(
                'DELETE FROM `ai_analysis_cache` WHERE `match_id` = ?'
            );
            $stmt->execute([$matchId]);
        }
    }

    /**
     * Очистить устаревший кэш
     */
    public function cleanup(): int
    {
        $stmt = $this->pdo->prepare(
            'DELETE FROM `ai_analysis_cache` '
            . 'WHERE `created_at` < DATE_SUB(NOW(), INTERVAL ? SECOND)'
        );
        $stmt->execute([$this->ttlSeconds]);

        return $stmt->rowCount();
    }

    /**
     * Получить статистику кэша
     * 
     * @return array{total:int,fresh:int,stale:int}
     */
    public function getStats(): array
    {
        $stmt = $this->pdo->query(
            'SELECT '
            . 'COUNT(*) as total, '
            . 'SUM(CASE WHEN `created_at` > DATE_SUB(NOW(), INTERVAL ' . $this->ttlSeconds . ' SECOND) THEN 1 ELSE 0 END) as fresh, '
            . 'SUM(CASE WHEN `created_at` <= DATE_SUB(NOW(), INTERVAL ' . $this->ttlSeconds . ' SECOND) THEN 1 ELSE 0 END) as stale '
            . 'FROM `ai_analysis_cache`'
        );

        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return ['total' => 0, 'fresh' => 0, 'stale' => 0];
        }

        return [
            'total' => (int) ($row['total'] ?? 0),
            'fresh' => (int) ($row['fresh'] ?? 0),
            'stale' => (int) ($row['stale'] ?? 0),
        ];
    }
}
