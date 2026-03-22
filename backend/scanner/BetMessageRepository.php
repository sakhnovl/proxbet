<?php

declare(strict_types=1);

namespace Proxbet\Scanner;

require_once __DIR__ . '/../line/logger.php';

use PDO;
use Proxbet\Line\Logger;

/**
 * Repository for bet_messages table operations.
 */
final class BetMessageRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    /**
     * @param array<string,mixed>|null $algorithmPayload
     */
    public function saveBetMessage(
        int $matchId,
        int $messageId,
        string $chatId,
        string $messageText,
        int $algorithmId,
        string $algorithmName,
        ?array $algorithmPayload = null
    ): int {
        try {
            $stmt = $this->pdo->prepare(
                'INSERT INTO `bet_messages` (`match_id`, `message_id`, `chat_id`, `message_text`, `algorithm_id`, `algorithm_name`, `algorithm_payload_json`, `bet_status`) '
                . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                $matchId,
                $messageId,
                $chatId,
                $messageText,
                $algorithmId,
                $algorithmName,
                $this->encodePayload($algorithmPayload),
                'pending',
            ]);

            $id = (int) $this->pdo->lastInsertId();

            Logger::info('Bet message saved', [
                'bet_message_id' => $id,
                'match_id' => $matchId,
                'message_id' => $messageId,
                'algorithm_id' => $algorithmId,
            ]);

            return $id;
        } catch (\Throwable $e) {
            Logger::error('Failed to save bet message', [
                'match_id' => $matchId,
                'message_id' => $messageId,
                'algorithm_id' => $algorithmId,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function getPendingBets(): array
    {
        try {
            $stmt = $this->pdo->query(
                'SELECT '
                . 'bm.`id` AS bet_id, '
                . 'bm.`match_id`, '
                . 'bm.`message_id`, '
                . 'bm.`chat_id`, '
                . 'bm.`message_text`, '
                . 'bm.`algorithm_id`, '
                . 'bm.`algorithm_name`, '
                . 'bm.`algorithm_payload_json`, '
                . 'bm.`bet_status`, '
                . 'bm.`sent_at`, '
                . 'm.`time`, '
                . 'm.`match_status`, '
                . 'm.`live_ht_hscore`, '
                . 'm.`live_ht_ascore`, '
                . 'm.`live_hscore`, '
                . 'm.`live_ascore`, '
                . 'm.`home`, '
                . 'm.`away` '
                . 'FROM `bet_messages` bm '
                . 'INNER JOIN `matches` m ON bm.`match_id` = m.`id` '
                . 'WHERE bm.`bet_status` = \'pending\' '
                . 'ORDER BY bm.`sent_at` ASC'
            );

            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            return $rows;
        } catch (\Throwable $e) {
            Logger::error('Failed to get pending bets', ['error' => $e->getMessage()]);
            return [];
        }
    }

    public function updateBetStatus(int $betId, string $status): bool
    {
        if (!in_array($status, ['won', 'lost'], true)) {
            Logger::error('Invalid bet status', ['bet_id' => $betId, 'status' => $status]);
            return false;
        }

        try {
            $stmt = $this->pdo->prepare(
                'UPDATE `bet_messages` '
                . 'SET `bet_status` = ?, `checked_at` = CURRENT_TIMESTAMP '
                . 'WHERE `id` = ?'
            );
            $stmt->execute([$status, $betId]);

            $updated = $stmt->rowCount() > 0;
            if ($updated) {
                Logger::info('Bet status updated', [
                    'bet_id' => $betId,
                    'status' => $status,
                ]);
            }

            return $updated;
        } catch (\Throwable $e) {
            Logger::error('Failed to update bet status', [
                'bet_id' => $betId,
                'status' => $status,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * @return array<string,mixed>|null
     */
    public function getBetByMatchId(int $matchId): ?array
    {
        try {
            $stmt = $this->pdo->prepare(
                'SELECT `id`, `match_id`, `message_id`, `chat_id`, `message_text`, `algorithm_id`, `algorithm_name`, `algorithm_payload_json`, `bet_status`, `sent_at`, `checked_at` '
                . 'FROM `bet_messages` '
                . 'WHERE `match_id` = ? '
                . 'ORDER BY `sent_at` DESC '
                . 'LIMIT 1'
            );
            $stmt->execute([$matchId]);

            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return is_array($row) ? $row : null;
        } catch (\Throwable $e) {
            Logger::error('Failed to get bet by match ID', [
                'match_id' => $matchId,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * @return array{total:int,pending:int,won:int,lost:int,win_rate:float,loss_rate:float}
     */
    public function getStatistics(): array
    {
        try {
            $stmt = $this->pdo->query(
                'SELECT '
                . 'COUNT(*) AS total, '
                . 'SUM(CASE WHEN `bet_status` = \'pending\' THEN 1 ELSE 0 END) AS pending, '
                . 'SUM(CASE WHEN `bet_status` = \'won\' THEN 1 ELSE 0 END) AS won, '
                . 'SUM(CASE WHEN `bet_status` = \'lost\' THEN 1 ELSE 0 END) AS lost '
                . 'FROM `bet_messages`'
            );

            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!is_array($row)) {
                return [
                    'total' => 0,
                    'pending' => 0,
                    'won' => 0,
                    'lost' => 0,
                    'win_rate' => 0.0,
                    'loss_rate' => 0.0,
                ];
            }

            $total = (int) ($row['total'] ?? 0);
            $pending = (int) ($row['pending'] ?? 0);
            $won = (int) ($row['won'] ?? 0);
            $lost = (int) ($row['lost'] ?? 0);
            $completed = $won + $lost;

            return [
                'total' => $total,
                'pending' => $pending,
                'won' => $won,
                'lost' => $lost,
                'win_rate' => $completed > 0 ? ($won / $completed) * 100 : 0.0,
                'loss_rate' => $completed > 0 ? ($lost / $completed) * 100 : 0.0,
            ];
        } catch (\Throwable $e) {
            Logger::error('Failed to get statistics', ['error' => $e->getMessage()]);
            return [
                'total' => 0,
                'pending' => 0,
                'won' => 0,
                'lost' => 0,
                'win_rate' => 0.0,
                'loss_rate' => 0.0,
            ];
        }
    }

    /**
     * @return array{total:int,pending:int,won:int,lost:int,win_rate:float,loss_rate:float,period:string}
     */
    public function getStatisticsByPeriod(string $period = 'all'): array
    {
        $whereClause = '';

        switch ($period) {
            case 'today':
                $whereClause = 'WHERE DATE(`sent_at`) = CURDATE()';
                break;
            case 'week':
                $whereClause = 'WHERE `sent_at` >= DATE_SUB(NOW(), INTERVAL 7 DAY)';
                break;
            case 'month':
                $whereClause = 'WHERE `sent_at` >= DATE_SUB(NOW(), INTERVAL 30 DAY)';
                break;
            default:
                $period = 'all';
        }

        try {
            $stmt = $this->pdo->query(
                'SELECT '
                . 'COUNT(*) AS total, '
                . 'SUM(CASE WHEN `bet_status` = \'pending\' THEN 1 ELSE 0 END) AS pending, '
                . 'SUM(CASE WHEN `bet_status` = \'won\' THEN 1 ELSE 0 END) AS won, '
                . 'SUM(CASE WHEN `bet_status` = \'lost\' THEN 1 ELSE 0 END) AS lost '
                . 'FROM `bet_messages` '
                . $whereClause
            );

            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!is_array($row)) {
                return [
                    'total' => 0,
                    'pending' => 0,
                    'won' => 0,
                    'lost' => 0,
                    'win_rate' => 0.0,
                    'loss_rate' => 0.0,
                    'period' => $period,
                ];
            }

            $total = (int) ($row['total'] ?? 0);
            $pending = (int) ($row['pending'] ?? 0);
            $won = (int) ($row['won'] ?? 0);
            $lost = (int) ($row['lost'] ?? 0);
            $completed = $won + $lost;

            return [
                'total' => $total,
                'pending' => $pending,
                'won' => $won,
                'lost' => $lost,
                'win_rate' => $completed > 0 ? ($won / $completed) * 100 : 0.0,
                'loss_rate' => $completed > 0 ? ($lost / $completed) * 100 : 0.0,
                'period' => $period,
            ];
        } catch (\Throwable $e) {
            Logger::error('Failed to get statistics by period', [
                'period' => $period,
                'error' => $e->getMessage(),
            ]);
            return [
                'total' => 0,
                'pending' => 0,
                'won' => 0,
                'lost' => 0,
                'win_rate' => 0.0,
                'loss_rate' => 0.0,
                'period' => $period,
            ];
        }
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function getRecentBets(int $limit = 10): array
    {
        try {
            $stmt = $this->pdo->prepare(
                'SELECT '
                . 'bm.`id` AS bet_id, '
                . 'bm.`match_id`, '
                . 'bm.`algorithm_id`, '
                . 'bm.`bet_status`, '
                . 'bm.`sent_at`, '
                . 'bm.`checked_at`, '
                . 'm.`home`, '
                . 'm.`away`, '
                . 'm.`live_ht_hscore`, '
                . 'm.`live_ht_ascore`, '
                . 'm.`live_hscore`, '
                . 'm.`live_ascore` '
                . 'FROM `bet_messages` bm '
                . 'INNER JOIN `matches` m ON bm.`match_id` = m.`id` '
                . 'ORDER BY bm.`sent_at` DESC '
                . 'LIMIT ?'
            );
            $stmt->bindValue(1, $limit, PDO::PARAM_INT);
            $stmt->execute();

            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            return $rows;
        } catch (\Throwable $e) {
            Logger::error('Failed to get recent bets', ['error' => $e->getMessage()]);
            return [];
        }
    }

    /**
     * @param array<string,mixed>|null $payload
     */
    private function encodePayload(?array $payload): ?string
    {
        if ($payload === null) {
            return null;
        }

        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return $json === false ? null : $json;
    }
}
