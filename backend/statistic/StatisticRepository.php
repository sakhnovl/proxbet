<?php

declare(strict_types=1);

namespace Proxbet\Statistic;

use PDO;
use Proxbet\Core\Database\PdoQueryHelper;
use Proxbet\Statistic\Interfaces\StatisticRepositoryInterface;

final class StatisticRepository implements StatisticRepositoryInterface
{
    /**
     * Columns in `matches` that store calculated statistics.
     *
     * Keep in sync with `backend/stat.php`.
     */
    private const METRIC_COLUMNS = [
        // last5 (Q)
        'ht_match_goals_1',
        'ht_match_missed_goals_1',
        'ht_match_goals_1_avg',
        'ht_match_missed_1_avg',
        'ht_match_goals_2',
        'ht_match_missed_goals_2',
        'ht_match_goals_2_avg',
        'ht_match_missed_2_avg',
        // h2h5 (G)
        'h2h_ht_match_goals_1',
        'h2h_ht_match_missed_goals_1',
        'h2h_ht_match_goals_1_avg',
        'h2h_ht_match_missed_1_avg',
        'h2h_ht_match_goals_2',
        'h2h_ht_match_missed_goals_2',
        'h2h_ht_match_goals_2_avg',
        'h2h_ht_match_missed_2_avg',
        // tournament table stats
        'table_games_1',
        'table_goals_1',
        'table_missed_1',
        'table_games_2',
        'table_goals_2',
        'table_missed_2',
        'table_avg',
    ];

    private const STATUS_COLUMNS = [
        'stats_updated_at',
        'stats_fetch_status',
        'stats_error',
        'stats_source',
        'stats_version',
        'stats_debug_json',
        'stats_refresh_needed',
    ];

    public function __construct(private PDO $db)
    {
    }

    /**
     * @return array<int,array{match_id:int, sgi:string, home:string, away:string, sgi_json:?string}>
     */
    private const BATCH_SIZE_MIN = 1;
    private const BATCH_SIZE_MAX = 1000;
    private const STALE_AFTER_MIN = 300;

    public function listMatchesToUpdate(int $limit, int $offset, bool $force, int $staleAfterSeconds, string $statsVersion, ?int $matchId = null): array
    {
        $limit = max(self::BATCH_SIZE_MIN, min(self::BATCH_SIZE_MAX, $limit));
        $offset = max(0, $offset);

        $where = 'WHERE `sgi` IS NOT NULL AND `sgi` <> \'\'';
        $params = [];
        if ($matchId !== null && $matchId > 0) {
            $where .= ' AND `id` = :match_id';
            $params[':match_id'] = $matchId;
        }
        if (!$force) {
            $parts = [
                '`sgi_json` IS NULL',
                '`sgi_json` = \'\'',
                '`stats_refresh_needed` = 1',
                '`stats_updated_at` IS NULL',
                '`stats_fetch_status` IS NULL',
                '`stats_fetch_status` <> \'ok\'',
                '`stats_version` IS NULL',
                '`stats_version` <> :stats_version',
                '(`stats_updated_at` IS NOT NULL AND `stats_updated_at` < DATE_SUB(UTC_TIMESTAMP(), INTERVAL :stale_after SECOND))',
            ];
            foreach (self::METRIC_COLUMNS as $col) {
                $parts[] = '`' . $col . '` IS NULL';
            }

            $where .= ' AND (' . implode(' OR ', $parts) . ')';
            $params[':stats_version'] = $statsVersion;
            $params[':stale_after'] = max(self::STALE_AFTER_MIN, $staleAfterSeconds);
        }

        $sql = 'SELECT `id` AS match_id, `sgi`, `home`, `away`, `sgi_json` FROM `matches` '
            . $where
            . ' ORDER BY `id` ASC LIMIT :limit OFFSET :offset';

        $rows = PdoQueryHelper::fetchAll(
            $this->db,
            $sql,
            $params + [':limit' => $limit, ':offset' => $offset],
            $this->detectTypes($params) + [':limit' => PDO::PARAM_INT, ':offset' => PDO::PARAM_INT]
        );

        $out = [];
        foreach ($rows as $r) {
            $id = isset($r['match_id']) ? (int) $r['match_id'] : 0;
            $sgi = isset($r['sgi']) ? (string) $r['sgi'] : '';
            $sgi = trim($sgi);
            $home = isset($r['home']) ? (string) $r['home'] : '';
            $away = isset($r['away']) ? (string) $r['away'] : '';
            $sgiJson = array_key_exists('sgi_json', $r) ? $r['sgi_json'] : null;
            $sgiJson = is_string($sgiJson) ? $sgiJson : null;

            if ($id <= 0 || $sgi === '') {
                continue;
            }

            $out[] = ['match_id' => $id, 'sgi' => $sgi, 'home' => $home, 'away' => $away, 'sgi_json' => $sgiJson];
        }

        return $out;
    }

    public function saveSgiJson(int $matchId, string $rawJson): void
    {
        PdoQueryHelper::execute(
            $this->db,
            'UPDATE `matches` SET `sgi_json` = :json WHERE `id` = :id',
            [':json' => $rawJson, ':id' => $matchId],
            [':json' => PDO::PARAM_STR, ':id' => PDO::PARAM_INT]
        );
    }

    /**
     * @param array<string,int|float|null> $metrics
     */
    public function saveMetrics(int $matchId, array $metrics): void
    {
        if ($metrics === []) {
            return;
        }

        $update = PdoQueryHelper::buildUpdatePairs($metrics, self::METRIC_COLUMNS, 'metric');
        if ($update['sql'] === '') {
            return;
        }

        PdoQueryHelper::execute(
            $this->db,
            'UPDATE `matches` SET ' . $update['sql'] . ' WHERE `id` = :id',
            $update['params'] + [':id' => $matchId],
            $update['types'] + [':id' => PDO::PARAM_INT]
        );
    }

    /**
     * @param array<string,mixed> $data
     */
    public function saveStatMeta(int $matchId, array $data): void
    {
        $update = PdoQueryHelper::buildUpdatePairs($data, self::STATUS_COLUMNS, 'status');
        if ($update['sql'] === '') {
            return;
        }

        PdoQueryHelper::execute(
            $this->db,
            'UPDATE `matches` SET ' . $update['sql'] . ' WHERE `id` = :id',
            $update['params'] + [':id' => $matchId],
            $update['types'] + [':id' => PDO::PARAM_INT]
        );
    }

    /**
     * @param array<string,mixed> $params
     * @return array<string,int>
     */
    private function detectTypes(array $params): array
    {
        $types = [];
        foreach ($params as $key => $value) {
            $types[$key] = is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR;
        }

        return $types;
    }
}
