<?php

declare(strict_types=1);

namespace Proxbet\Telegram;

use PDO;

/**
 * Сбор и анализ метрик использования AI-анализа
 */
final class AnalysisMetrics
{
    public function __construct(private PDO $pdo)
    {
    }

    /**
     * Записать метрику использования
     */
    public function track(
        int $telegramUserId,
        int $matchId,
        int $algorithmId,
        string $provider,
        string $model,
        bool $success,
        int $responseTimeMs,
        ?string $errorType = null
    ): void {
        $stmt = $this->pdo->prepare(
            'INSERT INTO `ai_analysis_metrics` '
            . '(`telegram_user_id`, `match_id`, `algorithm_id`, `provider`, `model_name`, '
            . '`success`, `response_time_ms`, `error_type`, `created_at`) '
            . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())'
        );
        $stmt->execute([
            $telegramUserId,
            $matchId,
            $algorithmId,
            $provider,
            $model,
            $success ? 1 : 0,
            $responseTimeMs,
            $errorType,
        ]);
    }

    /**
     * Получить статистику по алгоритмам
     * 
     * @return array<int,array{algorithm_id:int,total:int,success:int,failed:int,avg_time_ms:float,success_rate:float}>
     */
    public function getAlgorithmStats(int $days = 7): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT '
            . '`algorithm_id`, '
            . 'COUNT(*) as total, '
            . 'SUM(CASE WHEN `success` = 1 THEN 1 ELSE 0 END) as success, '
            . 'SUM(CASE WHEN `success` = 0 THEN 1 ELSE 0 END) as failed, '
            . 'AVG(`response_time_ms`) as avg_time_ms, '
            . 'ROUND(SUM(CASE WHEN `success` = 1 THEN 1 ELSE 0 END) * 100.0 / COUNT(*), 2) as success_rate '
            . 'FROM `ai_analysis_metrics` '
            . 'WHERE `created_at` > DATE_SUB(NOW(), INTERVAL ? DAY) '
            . 'GROUP BY `algorithm_id` '
            . 'ORDER BY `algorithm_id` ASC'
        );
        $stmt->execute([$days]);

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!is_array($rows)) {
            return [];
        }

        return array_map(static fn($row) => [
            'algorithm_id' => (int) ($row['algorithm_id'] ?? 0),
            'total' => (int) ($row['total'] ?? 0),
            'success' => (int) ($row['success'] ?? 0),
            'failed' => (int) ($row['failed'] ?? 0),
            'avg_time_ms' => (float) ($row['avg_time_ms'] ?? 0),
            'success_rate' => (float) ($row['success_rate'] ?? 0),
        ], $rows);
    }

    /**
     * Получить статистику по моделям
     * 
     * @return array<int,array{model:string,total:int,success:int,failed:int,avg_time_ms:float,success_rate:float}>
     */
    public function getModelStats(int $days = 7): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT '
            . '`model_name`, '
            . 'COUNT(*) as total, '
            . 'SUM(CASE WHEN `success` = 1 THEN 1 ELSE 0 END) as success, '
            . 'SUM(CASE WHEN `success` = 0 THEN 1 ELSE 0 END) as failed, '
            . 'AVG(`response_time_ms`) as avg_time_ms, '
            . 'ROUND(SUM(CASE WHEN `success` = 1 THEN 1 ELSE 0 END) * 100.0 / COUNT(*), 2) as success_rate '
            . 'FROM `ai_analysis_metrics` '
            . 'WHERE `created_at` > DATE_SUB(NOW(), INTERVAL ? DAY) '
            . 'GROUP BY `model_name` '
            . 'ORDER BY total DESC'
        );
        $stmt->execute([$days]);

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!is_array($rows)) {
            return [];
        }

        return array_map(static fn($row) => [
            'model' => (string) ($row['model_name'] ?? ''),
            'total' => (int) ($row['total'] ?? 0),
            'success' => (int) ($row['success'] ?? 0),
            'failed' => (int) ($row['failed'] ?? 0),
            'avg_time_ms' => (float) ($row['avg_time_ms'] ?? 0),
            'success_rate' => (float) ($row['success_rate'] ?? 0),
        ], $rows);
    }

    /**
     * Получить топ пользователей по использованию
     * 
     * @return array<int,array{telegram_user_id:int,username:string|null,total:int,success:int,failed:int}>
     */
    public function getTopUsers(int $limit = 10, int $days = 7): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT '
            . 'm.`telegram_user_id`, '
            . 'u.`username`, '
            . 'COUNT(*) as total, '
            . 'SUM(CASE WHEN m.`success` = 1 THEN 1 ELSE 0 END) as success, '
            . 'SUM(CASE WHEN m.`success` = 0 THEN 1 ELSE 0 END) as failed '
            . 'FROM `ai_analysis_metrics` m '
            . 'LEFT JOIN `telegram_users` u ON u.`telegram_user_id` = m.`telegram_user_id` '
            . 'WHERE m.`created_at` > DATE_SUB(NOW(), INTERVAL ? DAY) '
            . 'GROUP BY m.`telegram_user_id`, u.`username` '
            . 'ORDER BY total DESC '
            . 'LIMIT ?'
        );
        $stmt->execute([$days, $limit]);

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!is_array($rows)) {
            return [];
        }

        return array_map(static fn($row) => [
            'telegram_user_id' => (int) ($row['telegram_user_id'] ?? 0),
            'username' => isset($row['username']) && $row['username'] !== '' ? (string) $row['username'] : null,
            'total' => (int) ($row['total'] ?? 0),
            'success' => (int) ($row['success'] ?? 0),
            'failed' => (int) ($row['failed'] ?? 0),
        ], $rows);
    }

    /**
     * Получить распределение ошибок
     * 
     * @return array<int,array{error_type:string,count:int,percentage:float}>
     */
    public function getErrorDistribution(int $days = 7): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT '
            . 'COALESCE(`error_type`, \'unknown\') as error_type, '
            . 'COUNT(*) as count, '
            . 'ROUND(COUNT(*) * 100.0 / (SELECT COUNT(*) FROM `ai_analysis_metrics` WHERE `success` = 0 AND `created_at` > DATE_SUB(NOW(), INTERVAL ? DAY)), 2) as percentage '
            . 'FROM `ai_analysis_metrics` '
            . 'WHERE `success` = 0 AND `created_at` > DATE_SUB(NOW(), INTERVAL ? DAY) '
            . 'GROUP BY error_type '
            . 'ORDER BY count DESC'
        );
        $stmt->execute([$days, $days]);

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!is_array($rows)) {
            return [];
        }

        return array_map(static fn($row) => [
            'error_type' => (string) ($row['error_type'] ?? 'unknown'),
            'count' => (int) ($row['count'] ?? 0),
            'percentage' => (float) ($row['percentage'] ?? 0),
        ], $rows);
    }

    /**
     * Получить общую статистику
     * 
     * @return array{total:int,success:int,failed:int,success_rate:float,avg_time_ms:float,unique_users:int}
     */
    public function getOverallStats(int $days = 7): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT '
            . 'COUNT(*) as total, '
            . 'SUM(CASE WHEN `success` = 1 THEN 1 ELSE 0 END) as success, '
            . 'SUM(CASE WHEN `success` = 0 THEN 1 ELSE 0 END) as failed, '
            . 'ROUND(SUM(CASE WHEN `success` = 1 THEN 1 ELSE 0 END) * 100.0 / COUNT(*), 2) as success_rate, '
            . 'AVG(`response_time_ms`) as avg_time_ms, '
            . 'COUNT(DISTINCT `telegram_user_id`) as unique_users '
            . 'FROM `ai_analysis_metrics` '
            . 'WHERE `created_at` > DATE_SUB(NOW(), INTERVAL ? DAY)'
        );
        $stmt->execute([$days]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return [
                'total' => 0,
                'success' => 0,
                'failed' => 0,
                'success_rate' => 0.0,
                'avg_time_ms' => 0.0,
                'unique_users' => 0,
            ];
        }

        return [
            'total' => (int) ($row['total'] ?? 0),
            'success' => (int) ($row['success'] ?? 0),
            'failed' => (int) ($row['failed'] ?? 0),
            'success_rate' => (float) ($row['success_rate'] ?? 0),
            'avg_time_ms' => (float) ($row['avg_time_ms'] ?? 0),
            'unique_users' => (int) ($row['unique_users'] ?? 0),
        ];
    }

    /**
     * Очистить старые метрики
     */
    public function cleanup(int $keepDays = 30): int
    {
        $stmt = $this->pdo->prepare(
            'DELETE FROM `ai_analysis_metrics` '
            . 'WHERE `created_at` < DATE_SUB(NOW(), INTERVAL ? DAY)'
        );
        $stmt->execute([$keepDays]);

        return $stmt->rowCount();
    }
}
