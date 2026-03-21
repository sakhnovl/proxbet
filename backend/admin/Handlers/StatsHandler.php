<?php

declare(strict_types=1);

namespace Proxbet\Admin\Handlers;

use Proxbet\Security\InputValidator;
use Proxbet\Statistic\StatisticServiceFactory;

/**
 * Stats Handler - handles statistics operations.
 */
final class StatsHandler
{
    private \PDO $pdo;

    public function __construct(\PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * List matches with statistics.
     * 
     * @return array{rows:array<int,array<string,mixed>>,total:int}
     */
    public function listMatches(int $limit, int $offset, string $status, ?string $query): array
    {
        $where = [];
        $params = [];

        if ($query !== null) {
            $sanitized = InputValidator::sanitizeLike($query);
            $where[] = '(`home` LIKE :q ESCAPE \'\\\' OR `away` LIKE :q ESCAPE \'\\\' OR `liga` LIKE :q ESCAPE \'\\\')';
            $params[':q'] = '%' . $sanitized . '%';
        }

        if ($status === 'ok') {
            $where[] = '`stats_fetch_status` = \'ok\'';
        } elseif ($status === 'error') {
            $where[] = '`stats_fetch_status` = \'error\'';
        } elseif ($status === 'pending') {
            $where[] = '(`sgi` IS NOT NULL AND `sgi` <> \'\' AND (`stats_fetch_status` IS NULL OR `stats_fetch_status` NOT IN (\'ok\', \'error\') OR `stats_refresh_needed` = 1))';
        } elseif ($status === 'no_sgi') {
            $where[] = '(`sgi` IS NULL OR `sgi` = \'\')';
        }

        $whereSql = $where === [] ? '' : ('WHERE ' . implode(' AND ', $where));

        $countStmt = $this->pdo->prepare('SELECT COUNT(*) FROM `matches` ' . $whereSql);
        foreach ($params as $key => $value) {
            $countStmt->bindValue($key, $value, \PDO::PARAM_STR);
        }
        $countStmt->execute();
        $total = (int) $countStmt->fetchColumn();

        $sql = 'SELECT `id`,`evid`,`sgi`,`country`,`liga`,`home`,`away`,`start_time`,`stats_updated_at`,`stats_fetch_status`,`stats_error`,`stats_source`,`stats_version`,`stats_refresh_needed`,'
            . '`ht_match_goals_1`,`ht_match_goals_2`,`h2h_ht_match_goals_1`,`h2h_ht_match_goals_2` '
            . 'FROM `matches` '
            . $whereSql
            . ' ORDER BY `id` DESC LIMIT :limit OFFSET :offset';
        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value, \PDO::PARAM_STR);
        }
        $stmt->bindValue(':limit', $limit, \PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, \PDO::PARAM_INT);
        $stmt->execute();

        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        return [
            'rows' => is_array($rows) ? $rows : [],
            'total' => $total,
        ];
    }

    /**
     * Get match statistics by ID.
     * 
     * @return array<string,mixed>
     * @throws \RuntimeException
     */
    public function getMatch(int $matchId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM `matches` WHERE `id` = ?');
        $stmt->execute([$matchId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        
        if (!is_array($row)) {
            throw new \RuntimeException('Match not found.');
        }
        
        return $row;
    }

    /**
     * Refresh match statistics.
     * 
     * @return array<string,mixed>
     */
    public function refreshMatch(int $matchId): array
    {
        $service = StatisticServiceFactory::create();
        $result = $service->updateStatistics(1, 0, true, $matchId);
        return $result + ['match_id' => $matchId];
    }

    /**
     * Refresh statistics batch.
     * 
     * @return array<string,mixed>
     */
    public function refreshBatch(int $limit, int $offset, bool $force): array
    {
        $service = StatisticServiceFactory::create();
        $result = $service->updateStatistics($limit, $offset, $force);
        return $result + ['limit' => $limit, 'offset' => $offset, 'force' => $force];
    }

    /**
     * Get statistics overview.
     * 
     * @return array<string,mixed>
     */
    public function getOverview(): array
    {
        $sql = 'SELECT '
            . 'COUNT(*) AS total_matches, '
            . 'SUM(CASE WHEN `sgi` IS NOT NULL AND `sgi` <> \'\' THEN 1 ELSE 0 END) AS with_sgi, '
            . 'SUM(CASE WHEN `stats_fetch_status` = \'ok\' THEN 1 ELSE 0 END) AS stats_ok, '
            . 'SUM(CASE WHEN `stats_fetch_status` = \'error\' THEN 1 ELSE 0 END) AS stats_error, '
            . 'SUM(CASE WHEN `stats_refresh_needed` = 1 THEN 1 ELSE 0 END) AS pending_refresh, '
            . 'SUM(CASE WHEN `sgi` IS NULL OR `sgi` = \'\' THEN 1 ELSE 0 END) AS without_sgi '
            . 'FROM `matches`';
        $row = $this->pdo->query($sql)->fetch(\PDO::FETCH_ASSOC);
        return is_array($row) ? $row : [];
    }
}
