<?php

declare(strict_types=1);

namespace Proxbet\Statistic;

use PDO;

final class StatisticRepository
{
    /**
     * Columns in `matches` that store HT metrics (last5 + h2h5).
     *
     * Keep in sync with `backend/stat.php`.
     */
    private const HT_METRIC_COLUMNS = [
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
    public function listMatchesToUpdate(int $limit, int $offset, bool $force, int $staleAfterSeconds, string $statsVersion, ?int $matchId = null): array
    {
        $limit = max(1, min(1000, $limit));
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
            foreach (self::HT_METRIC_COLUMNS as $col) {
                $parts[] = '`' . $col . '` IS NULL';
            }

            $where .= ' AND (' . implode(' OR ', $parts) . ')';
            $params[':stats_version'] = $statsVersion;
            $params[':stale_after'] = max(300, $staleAfterSeconds);
        }

        $sql = 'SELECT `id` AS match_id, `sgi`, `home`, `away`, `sgi_json` FROM `matches` '
            . $where
            . ' ORDER BY `id` ASC LIMIT :limit OFFSET :offset';

        $stmt = $this->db->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!is_array($rows)) {
            return [];
        }

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
        $stmt = $this->db->prepare('UPDATE `matches` SET `sgi_json`=:json WHERE `id`=:id');
        $stmt->bindValue(':json', $rawJson, PDO::PARAM_STR);
        $stmt->bindValue(':id', $matchId, PDO::PARAM_INT);
        $stmt->execute();
    }

    /**
     * @param array<string,int|float|null> $metrics
     */
    public function saveHtMetrics(int $matchId, array $metrics): void
    {
        if ($metrics === []) {
            return;
        }

        $allowedSet = array_flip(self::HT_METRIC_COLUMNS);

        $pairs = [];
        $params = [':id' => $matchId];
        foreach ($metrics as $col => $val) {
            if (!isset($allowedSet[$col])) {
                continue;
            }

            $ph = ':' . $col;
            $pairs[] = '`' . $col . '`=' . $ph;
            $params[$ph] = $val;
        }

        if ($pairs === []) {
            return;
        }

        $sql = 'UPDATE `matches` SET ' . implode(', ', $pairs) . ' WHERE `id`=:id';
        $stmt = $this->db->prepare($sql);
        foreach ($params as $k => $v) {
            if ($v === null) {
                $type = PDO::PARAM_NULL;
            } elseif (is_int($v)) {
                $type = PDO::PARAM_INT;
            } else {
                $type = PDO::PARAM_STR;
            }
            $stmt->bindValue($k, $v, $type);
        }
        $stmt->execute();
    }

    /**
     * @param array<string,mixed> $data
     */
    public function saveStatMeta(int $matchId, array $data): void
    {
        $pairs = [];
        $params = [':id' => $matchId];
        $allowed = array_flip(self::STATUS_COLUMNS);

        foreach ($data as $column => $value) {
            if (!isset($allowed[$column])) {
                continue;
            }

            $pairs[] = '`' . $column . '`=:' . $column;
            $params[':' . $column] = $value;
        }

        if ($pairs === []) {
            return;
        }

        $sql = 'UPDATE `matches` SET ' . implode(', ', $pairs) . ' WHERE `id`=:id';
        $stmt = $this->db->prepare($sql);
        foreach ($params as $key => $value) {
            if ($value === null) {
                $type = PDO::PARAM_NULL;
            } elseif (is_int($value)) {
                $type = PDO::PARAM_INT;
            } else {
                $type = PDO::PARAM_STR;
            }
            $stmt->bindValue($key, $value, $type);
        }
        $stmt->execute();
    }
}
