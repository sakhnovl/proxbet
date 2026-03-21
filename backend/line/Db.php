<?php

declare(strict_types=1);

namespace Proxbet\Line;

require_once __DIR__ . '/logger.php';
require_once __DIR__ . '/SchemaBootstrap.php';

use PDO;
use PDOException;
use Proxbet\Line\Logger;
use Proxbet\Core\CacheManager;
use Proxbet\Core\Exceptions\DatabaseException;
use Proxbet\Core\Exceptions\ConfigurationException;

final class Db
{
    public static function connectFromEnv(): PDO
    {
        $host = getenv('DB_HOST') ?: '';
        $user = getenv('DB_USER') ?: '';
        $pass = getenv('DB_PASS') ?: '';
        $db = getenv('DB_NAME') ?: '';
        $port = getenv('DB_PORT') ?: '3306';

        if ($host === '') {
            throw new ConfigurationException('DB_HOST is not set');
        }

        if ($user === '') {
            throw new ConfigurationException('DB_USER is not set');
        }

        if ($db === '') {
            throw new ConfigurationException('DB_NAME is not set');
        }

        try {
            $pdo = self::connectToDatabase($host, $port, $db, $user, $pass);

            if ($pdo === null) {
                self::createDatabase($host, $port, $db, $user, $pass);
                $pdo = self::connectToDatabase($host, $port, $db, $user, $pass);
            }

            if ($pdo === null) {
                throw new DatabaseException('Database "' . $db . '" is not available');
            }

            SchemaBootstrap::ensure($pdo);

            return $pdo;
        } catch (PDOException $e) {
            throw new DatabaseException('DB connect failed: ' . $e->getMessage(), 0, $e);
        }
    }

    private static function connectToDatabase(string $host, string $port, string $db, string $user, string $pass): ?PDO
    {
        $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $host, $port, $db);

        try {
            return new PDO($dsn, $user, $pass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
        } catch (PDOException $e) {
            if (self::isUnknownDatabaseError($e)) {
                return null;
            }

            throw $e;
        }
    }

    private static function createDatabase(string $host, string $port, string $db, string $user, string $pass): void
    {
        $serverDsn = sprintf('mysql:host=%s;port=%s;charset=utf8mb4', $host, $port);
        $serverPdo = new PDO($serverDsn, $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);

        $serverPdo->exec(
            'CREATE DATABASE IF NOT EXISTS `'
            . str_replace('`', '``', $db)
            . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci'
        );
    }

    private static function isUnknownDatabaseError(PDOException $e): bool
    {
        $errorInfo = $e->errorInfo;
        $sqlState = is_array($errorInfo) ? (string) ($errorInfo[0] ?? '') : '';
        $driverCode = is_array($errorInfo) ? (int) ($errorInfo[1] ?? 0) : 0;
        $message = $e->getMessage();

        return $sqlState === 'HY000'
            && $driverCode === 1049
            && str_contains(strtolower($message), 'unknown database');
    }

    /** @return array<int,array<string,mixed>> */
    public static function getActiveBans(PDO $pdo): array
    {
        // Try to get from cache first
        try {
            if (class_exists('Proxbet\Core\CacheManager')) {
                $cache = new CacheManager();
                $cached = $cache->getActiveBans();
                if ($cached !== null) {
                    return $cached;
                }
            }
        } catch (\Throwable $e) {
            Logger::error('Cache read failed for active bans', ['error' => $e->getMessage()]);
        }

        // Fetch from database
        try {
            $stmt = $pdo->query('SELECT `id`,`country`,`liga`,`home`,`away`,`is_active`,`created_at`,`updated_at` FROM `bans` WHERE `is_active`=1 ORDER BY `id` ASC');
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $bans = is_array($rows) ? $rows : [];

            // Cache the result
            try {
                if (class_exists('Proxbet\Core\CacheManager')) {
                    $cache = new CacheManager();
                    $cache->cacheActiveBans($bans);
                }
            } catch (\Throwable $e) {
                Logger::error('Cache write failed for active bans', ['error' => $e->getMessage()]);
            }

            return $bans;
        } catch (\Throwable $e) {
            Logger::error('Failed to load bans', ['error' => $e->getMessage()]);
            return [];
        }
    }

    /**
     * @return array{rows:array<int,array<string,mixed>>, total:int}
     */
    public static function listBans(PDO $pdo, int $limit, int $offset): array
    {
        $limit = max(1, min(50, $limit));
        $offset = max(0, $offset);

        $total = 0;
        try {
            $res = $pdo->query('SELECT COUNT(*) AS c FROM `bans`');
            $total = (int) (($res->fetch(PDO::FETCH_ASSOC)['c']) ?? 0);
        } catch (\Throwable) {
            $total = 0;
        }

        $stmt = $pdo->prepare('SELECT `id`,`country`,`liga`,`home`,`away`,`is_active`,`created_at`,`updated_at` FROM `bans` ORDER BY `id` DESC LIMIT ? OFFSET ?');
        $stmt->bindValue(1, $limit, PDO::PARAM_INT);
        $stmt->bindValue(2, $offset, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return ['rows' => (is_array($rows) ? $rows : []), 'total' => $total];
    }

    /** @return array<string,mixed>|null */
    public static function getBanById(PDO $pdo, int $id): ?array
    {
        $stmt = $pdo->prepare('SELECT `id`,`country`,`liga`,`home`,`away`,`is_active`,`created_at`,`updated_at` FROM `bans` WHERE `id`=?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    /**
     * @param array<string,mixed> $data
     */
    public static function addBan(PDO $pdo, array $data): int
    {
        $isActive = isset($data['is_active']) ? (int) ((bool) $data['is_active']) : 1;

        $stmt = $pdo->prepare('INSERT INTO `bans` (`country`,`liga`,`home`,`away`,`is_active`) VALUES (?,?,?,?,?)');
        $stmt->execute([
            $data['country'] ?? null,
            $data['liga'] ?? null,
            $data['home'] ?? null,
            $data['away'] ?? null,
            $isActive,
        ]);

        // Invalidate bans cache
        try {
            if (class_exists('Proxbet\Core\CacheManager')) {
                $cache = new CacheManager();
                $cache->invalidateBans();
            }
        } catch (\Throwable $e) {
            Logger::error('Cache invalidation failed for bans', ['error' => $e->getMessage()]);
        }

        return (int) $pdo->lastInsertId();
    }

    /**
     * @param array<string,?string> $data
     */
    public static function updateBan(PDO $pdo, int $id, array $data): bool
    {
        $stmt = $pdo->prepare('UPDATE `bans` SET `country`=?,`liga`=?,`home`=?,`away`=? WHERE `id`=?');
        $stmt->execute([
            $data['country'] ?? null,
            $data['liga'] ?? null,
            $data['home'] ?? null,
            $data['away'] ?? null,
            $id,
        ]);

        $updated = $stmt->rowCount() > 0;

        // Invalidate bans cache if updated
        if ($updated) {
            try {
                if (class_exists('Proxbet\Core\CacheManager')) {
                    $cache = new CacheManager();
                    $cache->invalidateBans();
                }
            } catch (\Throwable $e) {
                Logger::error('Cache invalidation failed for bans', ['error' => $e->getMessage()]);
            }
        }

        return $updated;
    }

    public static function deleteBan(PDO $pdo, int $id): bool
    {
        $stmt = $pdo->prepare('DELETE FROM `bans` WHERE `id`=?');
        $stmt->execute([$id]);

        $deleted = $stmt->rowCount() > 0;

        // Invalidate bans cache if deleted
        if ($deleted) {
            try {
                if (class_exists('Proxbet\Core\CacheManager')) {
                    $cache = new CacheManager();
                    $cache->invalidateBans();
                }
            } catch (\Throwable $e) {
                Logger::error('Cache invalidation failed for bans', ['error' => $e->getMessage()]);
            }
        }

        return $deleted;
    }

    /**
     * @param array<int,array<string,mixed>> $matches
     * @return array<string,int>
     */
    public static function upsertMatches(PDO $pdo, array $matches): array
    {
        $inserted = 0;
        $updated = 0;
        $skipped = 0;

        if ($matches === []) {
            return compact('inserted', 'updated', 'skipped');
        }

        $columns = SchemaBootstrap::getTableColumns($pdo, 'matches');
        if ($columns === []) {
            throw new DatabaseException('Table matches not found or no columns');
        }

        $available = array_flip($columns);
        $allFields = [
            'evid',
            'sgi',
            'start_time', 'time', 'match_status', 'country', 'liga', 'home', 'away',
            'home_cf', 'draw_cf', 'away_cf',
            'total_line', 'total_line_tb', 'total_line_tm',
            'btts_yes', 'btts_no',
            'itb1', 'itb1cf', 'itb2', 'itb2cf',
            'fm1', 'fm1cf', 'fm2', 'fm2cf',
        ];

        $fields = [];
        foreach ($allFields as $field) {
            if (isset($available[$field])) {
                $fields[] = $field;
            }
        }

        if (!in_array('evid', $fields, true)) {
            throw new DatabaseException('matches.evid column is required');
        }

        $immutable = [
            'id',
            'evid',
            'start_time',
            'time',
            'match_status',
            'country',
            'liga',
            'home',
            'away',
            'created_at',
        ];

        $updateFields = array_values(array_filter(
            $fields,
            static fn(string $field): bool => !in_array($field, $immutable, true)
        ));

        // Build batch INSERT query with multiple value sets
        $colList = implode(',', array_map(static fn(string $column): string => '`' . $column . '`', $fields));
        $placeholders = '(' . implode(',', array_fill(0, count($fields), '?')) . ')';

        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $updateSql = '';
        
        if ($updateFields !== []) {
            if ($driver === 'sqlite') {
                // SQLite uses ON CONFLICT ... DO UPDATE
                $pairs = [];
                foreach ($updateFields as $column) {
                    if ($column === 'sgi') {
                        $pairs[] = '`sgi`=COALESCE(`sgi`, excluded.`sgi`)';
                        continue;
                    }
                    $pairs[] = sprintf('`%s`=excluded.`%s`', $column, $column);
                }
                $updateSql = ' ON CONFLICT(`evid`) DO UPDATE SET ' . implode(',', $pairs);
            } else {
                // MySQL uses ON DUPLICATE KEY UPDATE
                $pairs = [];
                foreach ($updateFields as $column) {
                    if ($column === 'sgi') {
                        $pairs[] = '`sgi`=IF(`sgi` IS NULL, VALUES(`sgi`), `sgi`)';
                        continue;
                    }
                    $pairs[] = sprintf('`%s`=VALUES(`%s`)', $column, $column);
                }
                $updateSql = ' ON DUPLICATE KEY UPDATE ' . implode(',', $pairs);
            }
        }

        // Process in batches of 100 for optimal performance
        $batchSize = 100;
        $batches = array_chunk($matches, $batchSize);

        $pdo->beginTransaction();
        try {
            foreach ($batches as $batch) {
                $validRows = [];
                $batchData = [];

                foreach ($batch as $match) {
                    if (!is_array($match) || !isset($match['evid'])) {
                        $skipped++;
                        continue;
                    }

                    $row = [];
                    foreach ($fields as $field) {
                        $row[] = $match[$field] ?? null;
                    }

                    $validRows[] = $row;
                    $batchData = array_merge($batchData, $row);
                }

                if ($validRows === []) {
                    continue;
                }

                // For SQLite, check which rows exist BEFORE insert
                $existingEvids = [];
                if ($driver === 'sqlite') {
                    foreach ($validRows as $row) {
                        $evid = $row[0]; // evid is first field
                        $checkStmt = $pdo->prepare('SELECT COUNT(*) as cnt FROM matches WHERE evid = ?');
                        $checkStmt->execute([$evid]);
                        $exists = (int) $checkStmt->fetch(PDO::FETCH_ASSOC)['cnt'];
                        if ($exists > 0) {
                            $existingEvids[$evid] = true;
                        }
                    }
                }

                // Build multi-row INSERT
                $valueSets = implode(',', array_fill(0, count($validRows), $placeholders));
                $sql = sprintf('INSERT INTO `matches` (%s) VALUES %s%s', $colList, $valueSets, $updateSql);

                $stmt = $pdo->prepare($sql);
                $stmt->execute($batchData);

                // Calculate inserted vs updated based on affected rows
                $affected = $stmt->rowCount();
                $rowCount = count($validRows);

                if ($driver === 'sqlite') {
                    // SQLite: Use pre-checked existence
                    foreach ($validRows as $row) {
                        $evid = $row[0];
                        if (isset($existingEvids[$evid])) {
                            $updated++;
                        } else {
                            $inserted++;
                        }
                    }
                } else {
                    // MySQL: Use rowCount behavior
                    // If affected rows equals row count, all were inserted
                    // If affected rows is 2x row count, all were updated (MySQL returns 2 for updated rows)
                    // Otherwise, it's a mix
                    if ($affected === $rowCount) {
                        $inserted += $rowCount;
                    } elseif ($affected === $rowCount * 2) {
                        $updated += $rowCount;
                    } else {
                        // Mixed case: estimate based on affected rows
                        $updatedInBatch = (int) floor($affected / 2);
                        $insertedInBatch = $rowCount - $updatedInBatch;
                        $inserted += max(0, $insertedInBatch);
                        $updated += $updatedInBatch;
                    }
                }
            }

            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            Logger::error('Batch upsert transaction failed', ['error' => $e->getMessage()]);
            throw $e;
        }

        return compact('inserted', 'updated', 'skipped');
    }
}
