<?php

declare(strict_types=1);

namespace Proxbet\Telegram;

use PDO;

/**
 * Rate limiting для защиты от злоупотреблений AI-анализом
 */
final class RateLimiter
{
    public function __construct(
        private PDO $pdo,
        private int $maxRequests = 10,
        private int $windowSeconds = 3600
    ) {
    }

    /**
     * Проверить, может ли пользователь сделать запрос
     * 
     * @return array{allowed:bool,current:int,limit:int,reset_at:string|null}
     */
    public function check(int $telegramUserId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) as count, MAX(`created_at`) as last_request '
            . 'FROM `ai_analysis_requests` '
            . 'WHERE `telegram_user_id` = ? '
            . 'AND `created_at` > DATE_SUB(NOW(), INTERVAL ? SECOND)'
        );
        $stmt->execute([$telegramUserId, $this->windowSeconds]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return [
                'allowed' => true,
                'current' => 0,
                'limit' => $this->maxRequests,
                'reset_at' => null,
            ];
        }

        $current = (int) ($row['count'] ?? 0);
        $lastRequest = $row['last_request'] ?? null;

        $resetAt = null;
        if ($lastRequest !== null && is_string($lastRequest)) {
            $resetTimestamp = strtotime($lastRequest) + $this->windowSeconds;
            $resetAt = date('Y-m-d H:i:s', $resetTimestamp);
        }

        return [
            'allowed' => $current < $this->maxRequests,
            'current' => $current,
            'limit' => $this->maxRequests,
            'reset_at' => $resetAt,
        ];
    }

    /**
     * Получить информацию о лимитах для пользователя
     */
    public function getInfo(int $telegramUserId): string
    {
        $status = $this->check($telegramUserId);

        if ($status['allowed']) {
            $remaining = $status['limit'] - $status['current'];
            return "Доступно запросов: {$remaining}/{$status['limit']}";
        }

        $resetAt = $status['reset_at'] ?? 'неизвестно';
        return "Лимит исчерпан ({$status['current']}/{$status['limit']}). Сброс: {$resetAt}";
    }

    /**
     * Получить статистику по rate limiting
     * 
     * @return array{total_users:int,blocked_users:int,avg_requests:float}
     */
    public function getStats(): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT '
            . 'COUNT(DISTINCT `telegram_user_id`) as total_users, '
            . 'SUM(CASE WHEN request_count >= ? THEN 1 ELSE 0 END) as blocked_users, '
            . 'AVG(request_count) as avg_requests '
            . 'FROM ('
            . '  SELECT `telegram_user_id`, COUNT(*) as request_count '
            . '  FROM `ai_analysis_requests` '
            . '  WHERE `created_at` > DATE_SUB(NOW(), INTERVAL ? SECOND) '
            . '  GROUP BY `telegram_user_id`'
            . ') as user_requests'
        );
        $stmt->execute([$this->maxRequests, $this->windowSeconds]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return [
                'total_users' => 0,
                'blocked_users' => 0,
                'avg_requests' => 0.0,
            ];
        }

        return [
            'total_users' => (int) ($row['total_users'] ?? 0),
            'blocked_users' => (int) ($row['blocked_users'] ?? 0),
            'avg_requests' => (float) ($row['avg_requests'] ?? 0),
        ];
    }

    /**
     * Получить список пользователей, превысивших лимит
     * 
     * @return array<int,array{telegram_user_id:int,username:string|null,request_count:int,last_request:string}>
     */
    public function getBlockedUsers(int $limit = 20): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT '
            . 'r.`telegram_user_id`, '
            . 'u.`username`, '
            . 'COUNT(*) as request_count, '
            . 'MAX(r.`created_at`) as last_request '
            . 'FROM `ai_analysis_requests` r '
            . 'LEFT JOIN `telegram_users` u ON u.`telegram_user_id` = r.`telegram_user_id` '
            . 'WHERE r.`created_at` > DATE_SUB(NOW(), INTERVAL ? SECOND) '
            . 'GROUP BY r.`telegram_user_id`, u.`username` '
            . 'HAVING request_count >= ? '
            . 'ORDER BY request_count DESC '
            . 'LIMIT ?'
        );
        $stmt->execute([$this->windowSeconds, $this->maxRequests, $limit]);

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!is_array($rows)) {
            return [];
        }

        return array_map(static fn($row) => [
            'telegram_user_id' => (int) ($row['telegram_user_id'] ?? 0),
            'username' => isset($row['username']) && $row['username'] !== '' ? (string) $row['username'] : null,
            'request_count' => (int) ($row['request_count'] ?? 0),
            'last_request' => (string) ($row['last_request'] ?? ''),
        ], $rows);
    }
}
