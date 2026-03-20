<?php

declare(strict_types=1);

namespace Proxbet\Statistic;

use PDO;
use Proxbet\Line\Logger;
use Proxbet\Statistic\Interfaces\StatisticServiceInterface;

final class StatisticService implements StatisticServiceInterface
{
    private const MAX_ERROR_LENGTH = 2000;
    private const BATCH_SIZE_MIN = 1;
    private const BATCH_SIZE_MAX = 1000;

    public function __construct(
        private Config $config,
        private EventsstatClient $client,
        private StatisticRepository $repo,
        private HtMetricsCalculator $htCalculator,
        private TableMetricsCalculator $tableCalculator,
        private PDO $db,
    ) {
    }

    /**
     * Update statistics for matches.
     *
     * @return array{selected:int, updated:int, errors:int}
     */
    public function updateStatistics(int $limit, int $offset, bool $force, ?int $matchId = null): array
    {
        $this->ensureStatColumns();

        $selected = 0;
        $updated = 0;
        $errors = 0;

        Logger::info('Statistic update started', ['limit' => $limit, 'offset' => $offset, 'force' => $force]);

        $items = $this->repo->listMatchesToUpdate(
            $limit,
            $offset,
            $force,
            $this->config->staleAfterSeconds,
            $this->config->statsVersion,
            $matchId
        );
        $selected = count($items);

        Logger::info('Statistic batch selected', ['count' => $selected, 'limit' => $limit, 'offset' => $offset, 'force' => $force]);

        foreach ($items as $it) {
            $matchId = (int) $it['match_id'];
            $sgi = (string) $it['sgi'];
            $home = (string) $it['home'];
            $away = (string) $it['away'];
            $sgiJsonDb = array_key_exists('sgi_json', $it) ? $it['sgi_json'] : null;
            $sgiJsonDb = is_string($sgiJsonDb) ? $sgiJsonDb : '';

            $source = 'db';
            $decoded = $this->decodeSgiJson($sgiJsonDb, $matchId, $sgi);
            $raw = $sgiJsonDb;

            if ($decoded === []) {
                $source = 'remote';
                $res = $this->client->fetchGameRawJson($sgi);
                
                if (!$res['ok']) {
                    $this->repo->saveStatMeta($matchId, [
                        'stats_fetch_status' => 'error',
                        'stats_error' => $this->truncateError((string) ($res['error'] ?? 'Unknown fetch error')),
                        'stats_source' => $source,
                        'stats_version' => $this->config->statsVersion,
                    ]);
                    $errors++;
                    Logger::error('Failed to fetch statistic', [
                        'match_id' => $matchId,
                        'sgi' => $sgi,
                        'status' => $res['status'],
                        'attempts' => $res['attempts'],
                        'error' => $res['error'],
                    ]);
                    $this->sleepBetween();
                    continue;
                }

                $raw = (string) $res['rawJson'];
                if (trim($raw) === '') {
                    $this->repo->saveStatMeta($matchId, [
                        'stats_fetch_status' => 'error',
                        'stats_error' => 'Empty statistic response body',
                        'stats_source' => $source,
                        'stats_version' => $this->config->statsVersion,
                    ]);
                    $errors++;
                    Logger::error('Empty statistic response body', ['match_id' => $matchId, 'sgi' => $sgi, 'status' => $res['status']]);
                    $this->sleepBetween();
                    continue;
                }

                $decoded = $this->decodeSgiJson($raw, $matchId, $sgi);
                if ($decoded === []) {
                    $this->repo->saveStatMeta($matchId, [
                        'stats_fetch_status' => 'error',
                        'stats_error' => 'Invalid statistic JSON (remote)',
                        'stats_source' => $source,
                        'stats_version' => $this->config->statsVersion,
                    ]);
                    $errors++;
                    Logger::error('Invalid statistic JSON (remote)', ['match_id' => $matchId, 'sgi' => $sgi]);
                    $this->sleepBetween();
                    continue;
                }
            }

            try {
                if ($source === 'remote') {
                    $this->repo->saveSgiJson($matchId, $raw);
                }

                $htDetails = ($decoded === [] || $home === '' || $away === '')
                    ? ['metrics' => $this->getEmptyHtMetrics(), 'debug' => []]
                    : $this->htCalculator->calculate($decoded, $home, $away);
                $tableDetails = ($decoded === [] || $home === '' || $away === '')
                    ? ['metrics' => $this->getEmptyTableMetrics(), 'debug' => []]
                    : $this->tableCalculator->calculate($decoded, $home, $away);

                $this->logDebugWarnings($matchId, $sgi, $htDetails['debug'], $tableDetails['debug']);

                $this->repo->saveMetrics($matchId, array_merge($htDetails['metrics'], $tableDetails['metrics']));
                $this->repo->saveStatMeta($matchId, [
                    'stats_updated_at' => gmdate('Y-m-d H:i:s'),
                    'stats_fetch_status' => 'ok',
                    'stats_error' => null,
                    'stats_source' => $source,
                    'stats_version' => $this->config->statsVersion,
                    'stats_debug_json' => $this->encodeDebugJson([
                        'match_id' => $matchId,
                        'sgi' => $sgi,
                        'source' => $source,
                        'home' => $home,
                        'away' => $away,
                        'ht_debug' => $htDetails['debug'],
                        'table_debug' => $tableDetails['debug'],
                    ]),
                    'stats_refresh_needed' => 0,
                ]);
                $updated++;

                Logger::info('Saved ht metrics', [
                    'match_id' => $matchId,
                    'sgi' => $sgi,
                    'home' => $home,
                    'away' => $away,
                    'sgi_json_source' => $source,
                    'metrics' => array_merge($htDetails['metrics'], $tableDetails['metrics']),
                    'ht_debug' => $htDetails['debug'],
                    'table_debug' => $tableDetails['debug'],
                ]);
            } catch (\Throwable $e) {
                $this->repo->saveStatMeta($matchId, [
                    'stats_fetch_status' => 'error',
                    'stats_error' => $this->truncateError($e->getMessage()),
                    'stats_source' => $source,
                    'stats_version' => $this->config->statsVersion,
                ]);
                $errors++;
                Logger::error('Failed to save statistic/metrics', [
                    'match_id' => $matchId,
                    'sgi' => $sgi,
                    'error' => $e->getMessage(),
                ]);
            }

            $this->sleepBetween();
        }

        return compact('selected', 'updated', 'errors');
    }

    /**
     * Decode SGI JSON string.
     *
     * @return array<string,mixed>
     */
    private function decodeSgiJson(?string $json, int $matchId, string $sgi): array
    {
        $json = $json === null ? '' : trim($json);
        if ($json === '') {
            return [];
        }

        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            Logger::error('Invalid sgi_json', [
                'match_id' => $matchId,
                'sgi' => $sgi,
                'json_error' => json_last_error_msg(),
            ]);
            return [];
        }

        return $decoded;
    }

    /**
     * Get empty metrics array.
     *
     * @return array<string,null>
     */
    private function getEmptyHtMetrics(): array
    {
        return array_fill_keys([
            'ht_match_goals_1', 'ht_match_missed_goals_1', 'ht_match_goals_1_avg', 'ht_match_missed_1_avg',
            'ht_match_goals_2', 'ht_match_missed_goals_2', 'ht_match_goals_2_avg', 'ht_match_missed_2_avg',
            'h2h_ht_match_goals_1', 'h2h_ht_match_missed_goals_1', 'h2h_ht_match_goals_1_avg', 'h2h_ht_match_missed_1_avg',
            'h2h_ht_match_goals_2', 'h2h_ht_match_missed_goals_2', 'h2h_ht_match_goals_2_avg', 'h2h_ht_match_missed_2_avg',
        ], null);
    }

    /**
     * @return array<string,null>
     */
    private function getEmptyTableMetrics(): array
    {
        return array_fill_keys([
            'table_games_1',
            'table_goals_1',
            'table_missed_1',
            'table_games_2',
            'table_goals_2',
            'table_missed_2',
            'table_avg',
        ], null);
    }

    /**
     * Ensure statistic columns exist in database.
     */
    private function ensureStatColumns(): void
    {
        $columns = [
            'ht_match_goals_1' => 'INT NULL',
            'ht_match_missed_goals_1' => 'INT NULL',
            'ht_match_goals_1_avg' => 'DOUBLE NULL',
            'ht_match_missed_1_avg' => 'DOUBLE NULL',
            'ht_match_goals_2' => 'INT NULL',
            'ht_match_missed_goals_2' => 'INT NULL',
            'ht_match_goals_2_avg' => 'DOUBLE NULL',
            'ht_match_missed_2_avg' => 'DOUBLE NULL',
            'h2h_ht_match_goals_1' => 'INT NULL',
            'h2h_ht_match_missed_goals_1' => 'INT NULL',
            'h2h_ht_match_goals_1_avg' => 'DOUBLE NULL',
            'h2h_ht_match_missed_1_avg' => 'DOUBLE NULL',
            'h2h_ht_match_goals_2' => 'INT NULL',
            'h2h_ht_match_missed_goals_2' => 'INT NULL',
            'h2h_ht_match_goals_2_avg' => 'DOUBLE NULL',
            'h2h_ht_match_missed_2_avg' => 'DOUBLE NULL',
            'table_games_1' => 'INT NULL',
            'table_goals_1' => 'INT NULL',
            'table_missed_1' => 'INT NULL',
            'table_games_2' => 'INT NULL',
            'table_goals_2' => 'INT NULL',
            'table_missed_2' => 'INT NULL',
            'table_avg' => 'DECIMAL(10,2) NULL',
            'stats_updated_at' => 'DATETIME NULL',
            'stats_fetch_status' => 'VARCHAR(32) NULL',
            'stats_error' => 'TEXT NULL',
            'stats_source' => 'VARCHAR(32) NULL',
            'stats_version' => 'VARCHAR(32) NULL',
            'stats_debug_json' => 'LONGTEXT NULL',
            'stats_refresh_needed' => 'TINYINT(1) NOT NULL DEFAULT 0',
        ];

        $dbName = (string) $this->db->query('SELECT DATABASE()')->fetchColumn();
        if ($dbName === '') {
            Logger::error('No database selected');
            return;
        }

        try {
            $placeholders = implode(',', array_fill(0, count($columns), '?'));
            $sql = 'SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS '
                . 'WHERE TABLE_SCHEMA=? AND TABLE_NAME=? AND COLUMN_NAME IN (' . $placeholders . ')';
            $stmt = $this->db->prepare($sql);
            $stmt->execute(array_merge([$dbName, 'matches'], array_keys($columns)));

            $existing = $stmt->fetchAll(PDO::FETCH_COLUMN, 0);
            $existingSet = array_flip(array_map('strval', is_array($existing) ? $existing : []));

            $missing = [];
            foreach ($columns as $name => $typeSql) {
                if (!isset($existingSet[$name])) {
                    $missing[$name] = $typeSql;
                }
            }

            if ($missing !== []) {
                $adds = [];
                foreach ($missing as $name => $typeSql) {
                    $adds[] = 'ADD COLUMN `' . str_replace('`', '``', $name) . '` ' . $typeSql;
                }

                $this->db->exec('ALTER TABLE `matches` ' . implode(', ', $adds));
                Logger::info('Added missing statistic columns', ['table' => 'matches', 'columns' => array_keys($missing)]);
            }

            $this->db->exec('ALTER TABLE `matches` MODIFY COLUMN `table_avg` DECIMAL(10,2) NULL');
        } catch (\Throwable $e) {
            Logger::error('Failed to ensure statistic columns', ['table' => 'matches', 'error' => $e->getMessage()]);
        }
    }

    private function sleepBetween(): void
    {
        if ($this->config->sleepMs <= 0) {
            return;
        }
        usleep($this->config->sleepMs * 1000);
    }

    private function encodeDebugJson(array $payload): string
    {
        return (string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function truncateError(string $message): string
    {
        return mb_substr(trim($message), 0, self::MAX_ERROR_LENGTH);
    }

    /**
     * @param array<string,mixed> $htDebug
     * @param array<string,mixed> $tableDebug
     */
    private function logDebugWarnings(int $matchId, string $sgi, array $htDebug, array $tableDebug): void
    {
        foreach (['home_last5', 'away_last5', 'home_h2h5', 'away_h2h5'] as $bucket) {
            $item = $htDebug[$bucket] ?? null;
            if (!is_array($item)) {
                continue;
            }

            $considered = isset($item['considered']) ? (int) $item['considered'] : 0;
            $skipped = isset($item['skipped']) ? (int) $item['skipped'] : 0;
            if ($considered === 0 && $skipped > 0) {
                Logger::info('Statistic metrics skipped all candidate matches', [
                    'match_id' => $matchId,
                    'sgi' => $sgi,
                    'bucket' => $bucket,
                    'considered' => $considered,
                    'skipped' => $skipped,
                ]);
            }
        }

        $warnings = $tableDebug['warnings'] ?? null;
        if (!is_array($warnings) || $warnings === []) {
            return;
        }

        Logger::info('Table metrics warnings', [
            'match_id' => $matchId,
            'sgi' => $sgi,
            'warnings' => array_values($warnings),
            'home_found' => (bool) ($tableDebug['home_found'] ?? false),
            'away_found' => (bool) ($tableDebug['away_found'] ?? false),
        ]);
    }
}
