<?php

declare(strict_types=1);

namespace Proxbet\Line;

require_once __DIR__ . '/logger.php';

use PDO;
use PDOException;
use Proxbet\Line\Logger;

final class Db
{
    /**
     * Connects to MySQL using .env and ensures:
     * - database exists
     * - required schema exists (table/columns/indexes)
     */
    public static function connectFromEnv(): PDO
    {
        $host = getenv('DB_HOST') ?: '';
        $user = getenv('DB_USER') ?: '';
        $pass = getenv('DB_PASS') ?: '';
        $db = getenv('DB_NAME') ?: '';
        $port = getenv('DB_PORT') ?: '3306';

        if ($host === '') {
            throw new \RuntimeException('DB_HOST is not set');
        }

        if ($user === '') {
            throw new \RuntimeException('DB_USER is not set');
        }

        if ($db === '') {
            throw new \RuntimeException('DB_NAME is not set');
        }

        try {
            $pdo = self::connectToDatabase($host, $port, $db, $user, $pass);

            if ($pdo === null) {
                self::createDatabase($host, $port, $db, $user, $pass);
                $pdo = self::connectToDatabase($host, $port, $db, $user, $pass);
            }

            if ($pdo === null) {
                throw new \RuntimeException('Database "' . $db . '" is not available');
            }

            self::ensureSchema($pdo);

            return $pdo;
        } catch (PDOException $e) {
            throw new \RuntimeException('DB connect failed: ' . $e->getMessage(), 0, $e);
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

    /** Create/upgrade required tables/columns/indexes for the parser. */
    private static function ensureSchema(PDO $pdo): void
    {
        // Create base table if missing
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS `matches` ('
            . '  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,'
            . '  `evid` VARCHAR(64) NOT NULL,'
            . '  `sgi` VARCHAR(64) NULL,'
            . '  `sgi_json` LONGTEXT NULL,'
            . '  `start_time` DATETIME NULL,'
            . '  `time` VARCHAR(16) NULL,'
            . '  `match_status` VARCHAR(255) NULL,'
            . '  `country` VARCHAR(128) NULL,'
            . '  `liga` VARCHAR(255) NULL,'
            . '  `home` VARCHAR(255) NULL,'
            . '  `away` VARCHAR(255) NULL,'
            . '  `home_cf` DECIMAL(10,2) NULL,'
            . '  `draw_cf` DECIMAL(10,2) NULL,'
            . '  `away_cf` DECIMAL(10,2) NULL,'
            . '  `total_line` DECIMAL(10,2) NULL,'
            . '  `total_line_tb` DECIMAL(10,2) NULL,'
            . '  `total_line_tm` DECIMAL(10,2) NULL,'
            . '  `btts_yes` DECIMAL(10,2) NULL,'
            . '  `btts_no` DECIMAL(10,2) NULL,'
            . '  `itb1` DECIMAL(10,2) NULL,'
            . '  `itb1cf` DECIMAL(10,2) NULL,'
            . '  `itb2` DECIMAL(10,2) NULL,'
            . '  `itb2cf` DECIMAL(10,2) NULL,'
            . '  `fm1` DECIMAL(10,2) NULL,'
            . '  `fm1cf` DECIMAL(10,2) NULL,'
            . '  `fm2` DECIMAL(10,2) NULL,'
            . '  `fm2cf` DECIMAL(10,2) NULL,'
            . '  `stats_updated_at` DATETIME NULL,'
            . '  `stats_fetch_status` VARCHAR(32) NULL,'
            . '  `stats_error` TEXT NULL,'
            . '  `stats_source` VARCHAR(32) NULL,'
            . '  `stats_version` VARCHAR(32) NULL,'
            . '  `stats_debug_json` LONGTEXT NULL,'
            . '  `stats_refresh_needed` TINYINT(1) NOT NULL DEFAULT 0,'
            . '  `live_updated_at` TIMESTAMP NULL DEFAULT NULL,'
            . '  `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,'
            . '  `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,'
            . '  PRIMARY KEY (`id`),'
            . '  UNIQUE KEY `uniq_matches_evid` (`evid`),'
            . '  KEY `idx_matches_sgi` (`sgi`),'
            . '  KEY `idx_matches_stats_refresh_needed` (`stats_refresh_needed`),'
            . '  KEY `idx_matches_stats_fetch_status` (`stats_fetch_status`),'
            . '  KEY `idx_matches_stats_updated_at` (`stats_updated_at`)'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );

        // Add missing columns if schema is partial
        $cols = array_flip(self::getTableColumns($pdo, 'matches'));
        if (!isset($cols['evid'])) {
            throw new \RuntimeException('Invalid schema: matches.evid column is missing');
        }

        $want = [
            'sgi' => 'VARCHAR(64) NULL',
            'sgi_json' => 'LONGTEXT NULL',
            // Live time/status (must be before `country`)
            'time' => 'VARCHAR(16) NULL',
            'match_status' => 'VARCHAR(255) NULL',
            'home_cf' => 'DECIMAL(10,2) NULL',
            'draw_cf' => 'DECIMAL(10,2) NULL',
            'away_cf' => 'DECIMAL(10,2) NULL',
            'total_line' => 'DECIMAL(10,2) NULL',
            'total_line_tb' => 'DECIMAL(10,2) NULL',
            'total_line_tm' => 'DECIMAL(10,2) NULL',
            'btts_yes' => 'DECIMAL(10,2) NULL',
            'btts_no' => 'DECIMAL(10,2) NULL',
            'itb1' => 'DECIMAL(10,2) NULL',
            'itb1cf' => 'DECIMAL(10,2) NULL',
            'itb2' => 'DECIMAL(10,2) NULL',
            'itb2cf' => 'DECIMAL(10,2) NULL',
            'fm1' => 'DECIMAL(10,2) NULL',
            'fm1cf' => 'DECIMAL(10,2) NULL',
            'fm2' => 'DECIMAL(10,2) NULL',
            'fm2cf' => 'DECIMAL(10,2) NULL',
            // HT metrics (last5 + h2h5).
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
            // Live match fields
            'live_evid' => 'VARCHAR(64) NULL',
            'live_ht_hscore' => 'INT NULL',
            'live_ht_ascore' => 'INT NULL',
            'live_hscore' => 'INT NULL',
            'live_ascore' => 'INT NULL',
            'live_xg_home' => 'DOUBLE NULL',
            'live_xg_away' => 'DOUBLE NULL',
            'live_att_home' => 'DOUBLE NULL',
            'live_att_away' => 'DOUBLE NULL',
            'live_danger_att_home' => 'DOUBLE NULL',
            'live_danger_att_away' => 'DOUBLE NULL',
            'live_shots_on_target_home' => 'DOUBLE NULL',
            'live_shots_on_target_away' => 'DOUBLE NULL',
            'live_shots_off_target_home' => 'DOUBLE NULL',
            'live_shots_off_target_away' => 'DOUBLE NULL',
            'live_yellow_cards_home' => 'DOUBLE NULL',
            'live_yellow_cards_away' => 'DOUBLE NULL',
            'live_safe_home' => 'DOUBLE NULL',
            'live_safe_away' => 'DOUBLE NULL',
            'live_corner_home' => 'DOUBLE NULL',
            'live_corner_away' => 'DOUBLE NULL',
            'stats_updated_at' => 'DATETIME NULL',
            'stats_fetch_status' => 'VARCHAR(32) NULL',
            'stats_error' => 'TEXT NULL',
            'stats_source' => 'VARCHAR(32) NULL',
            'stats_version' => 'VARCHAR(32) NULL',
            'stats_debug_json' => 'LONGTEXT NULL',
            'stats_refresh_needed' => 'TINYINT(1) NOT NULL DEFAULT 0',
            // Last moment when any live field (time/status/scores/stats) changed.
            'live_updated_at' => 'TIMESTAMP NULL DEFAULT NULL',
            'created_at' => 'TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP',
            'updated_at' => 'TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP',
        ];

        $add = [];
        foreach ($want as $name => $typeSql) {
            if (!isset($cols[$name])) {
                if ($name === 'time') {
                    $add[] = 'ADD COLUMN `time` ' . $typeSql . ' AFTER `start_time`';
                    continue;
                }
                if ($name === 'match_status') {
                    $add[] = 'ADD COLUMN `match_status` ' . $typeSql . ' AFTER `time`';
                    continue;
                }

                $add[] = 'ADD COLUMN `' . $name . '` ' . $typeSql;
            }
        }
        if ($add !== []) {
            $pdo->exec('ALTER TABLE `matches` ' . implode(', ', $add));
        }

        // Ensure `time` column has desired type and convert old numeric seconds to mm:ss.
        try {
            $pdo->exec('ALTER TABLE `matches` MODIFY COLUMN `time` VARCHAR(16) NULL AFTER `start_time`');
        } catch (PDOException) {
            // ignore
        }

        // Convert pure numeric values (seconds) to "mm:ss" (safe to run repeatedly).
        try {
            $pdo->exec(
                'UPDATE `matches` '
                . 'SET `time`=CONCAT('
                . 'LPAD(FLOOR(CAST(`time` AS UNSIGNED)/60),2,\'0\'),\':\',LPAD(MOD(CAST(`time` AS UNSIGNED),60),2,\'0\')'
                . ') '
                . 'WHERE `time` REGEXP \'^[0-9]+$\''
            );
        } catch (PDOException) {
            // ignore
        }

        // Ensure odds columns have 2 digits after decimal.
        // If they were created earlier as DECIMAL(10,3), MySQL will round/truncate to 2 decimals.
        $pdo->exec(
            'ALTER TABLE `matches` '
            . 'MODIFY COLUMN `home_cf` DECIMAL(10,2) NULL, '
            . 'MODIFY COLUMN `draw_cf` DECIMAL(10,2) NULL, '
            . 'MODIFY COLUMN `away_cf` DECIMAL(10,2) NULL, '
            . 'MODIFY COLUMN `total_line` DECIMAL(10,2) NULL, '
            . 'MODIFY COLUMN `total_line_tb` DECIMAL(10,2) NULL, '
            . 'MODIFY COLUMN `total_line_tm` DECIMAL(10,2) NULL, '
            . 'MODIFY COLUMN `btts_yes` DECIMAL(10,2) NULL, '
            . 'MODIFY COLUMN `btts_no` DECIMAL(10,2) NULL, '
            . 'MODIFY COLUMN `itb1` DECIMAL(10,2) NULL, '
            . 'MODIFY COLUMN `itb1cf` DECIMAL(10,2) NULL, '
            . 'MODIFY COLUMN `itb2` DECIMAL(10,2) NULL, '
            . 'MODIFY COLUMN `itb2cf` DECIMAL(10,2) NULL, '
            . 'MODIFY COLUMN `fm1` DECIMAL(10,2) NULL, '
            . 'MODIFY COLUMN `fm1cf` DECIMAL(10,2) NULL, '
            . 'MODIFY COLUMN `fm2` DECIMAL(10,2) NULL, '
            . 'MODIFY COLUMN `fm2cf` DECIMAL(10,2) NULL, '
            . 'MODIFY COLUMN `table_avg` DECIMAL(10,2) NULL'
        );

        // Ensure UNIQUE on evid
        try {
            $pdo->exec('ALTER TABLE `matches` ADD UNIQUE KEY `uniq_matches_evid` (`evid`)');
        } catch (PDOException) {
            // already exists
        }
        try {
            $pdo->exec('ALTER TABLE `matches` ADD KEY `idx_matches_sgi` (`sgi`)');
        } catch (PDOException) {
            // already exists
        }
        try {
            $pdo->exec('ALTER TABLE `matches` ADD KEY `idx_matches_stats_refresh_needed` (`stats_refresh_needed`)');
        } catch (PDOException) {
            // already exists
        }
        try {
            $pdo->exec('ALTER TABLE `matches` ADD KEY `idx_matches_stats_fetch_status` (`stats_fetch_status`)');
        } catch (PDOException) {
            // already exists
        }
        try {
            $pdo->exec('ALTER TABLE `matches` ADD KEY `idx_matches_stats_updated_at` (`stats_updated_at`)');
        } catch (PDOException) {
            // already exists
        }

        // Create bans table if missing
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS `bans` ('
            . '  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,'
            . '  `country` VARCHAR(128) NULL,'
            . '  `liga` VARCHAR(255) NULL,'
            . '  `home` VARCHAR(255) NULL,'
            . '  `away` VARCHAR(255) NULL,'
            . '  `is_active` TINYINT(1) NOT NULL DEFAULT 1,'
            . '  `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,'
            . '  `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,'
            . '  PRIMARY KEY (`id`),'
            . '  KEY `idx_bans_created_at` (`created_at`),'
            . '  KEY `idx_bans_is_active` (`is_active`)'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );

        // Ensure bans schema for older installs
        $bansCols = array_flip(self::getTableColumns($pdo, 'bans'));
        if (!isset($bansCols['id'])) {
            throw new \RuntimeException('Invalid schema: bans.id column is missing');
        }

        $wantBans = [
            'country' => 'VARCHAR(128) NULL',
            'liga' => 'VARCHAR(255) NULL',
            'home' => 'VARCHAR(255) NULL',
            'away' => 'VARCHAR(255) NULL',
            'is_active' => 'TINYINT(1) NOT NULL DEFAULT 1',
            'created_at' => 'TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP',
            'updated_at' => 'TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP',
        ];

        $addBans = [];
        foreach ($wantBans as $name => $typeSql) {
            if (!isset($bansCols[$name])) {
                $addBans[] = 'ADD COLUMN `' . $name . '` ' . $typeSql;
            }
        }
        if ($addBans !== []) {
            $pdo->exec('ALTER TABLE `bans` ' . implode(', ', $addBans));
        }

        try {
            $pdo->exec('ALTER TABLE `bans` ADD KEY `idx_bans_created_at` (`created_at`)');
        } catch (PDOException) {
            // already exists
        }
        try {
            $pdo->exec('ALTER TABLE `bans` ADD KEY `idx_bans_is_active` (`is_active`)');
        } catch (PDOException) {
            // already exists
        }

        // Create bet_messages table if missing
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS `bet_messages` ('
            . '  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,'
            . '  `match_id` BIGINT UNSIGNED NOT NULL,'
            . '  `message_id` BIGINT NOT NULL,'
            . '  `chat_id` VARCHAR(64) NOT NULL,'
            . '  `message_text` TEXT NOT NULL,'
            . '  `algorithm_id` TINYINT UNSIGNED NOT NULL DEFAULT 1,'
            . '  `algorithm_name` VARCHAR(64) NOT NULL DEFAULT \'Алгоритм 1\','
            . '  `algorithm_payload_json` LONGTEXT NULL,'
            . '  `bet_status` ENUM(\'pending\', \'won\', \'lost\') NOT NULL DEFAULT \'pending\','
            . '  `sent_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,'
            . '  `updated_at` TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,'
            . '  `checked_at` TIMESTAMP NULL DEFAULT NULL,'
            . '  PRIMARY KEY (`id`),'
            . '  KEY `idx_match_id` (`match_id`),'
            . '  KEY `idx_match_algorithm` (`match_id`, `algorithm_id`),'
            . '  KEY `idx_bet_status` (`bet_status`),'
            . '  KEY `idx_sent_at` (`sent_at`)'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );

        // Ensure bet_messages schema for older installs
        $betMsgCols = array_flip(self::getTableColumns($pdo, 'bet_messages'));
        if (count($betMsgCols) > 0 && !isset($betMsgCols['id'])) {
            throw new \RuntimeException('Invalid schema: bet_messages.id column is missing');
        }

        if (count($betMsgCols) > 0) {
            $wantBetMsg = [
                'match_id' => 'BIGINT UNSIGNED NOT NULL',
                'message_id' => 'BIGINT NOT NULL',
                'chat_id' => 'VARCHAR(64) NOT NULL',
                'message_text' => 'TEXT NOT NULL',
                'algorithm_id' => 'TINYINT UNSIGNED NOT NULL DEFAULT 1',
                'algorithm_name' => 'VARCHAR(64) NOT NULL DEFAULT \'Алгоритм 1\'',
                'algorithm_payload_json' => 'LONGTEXT NULL',
                'bet_status' => 'ENUM(\'pending\', \'won\', \'lost\') NOT NULL DEFAULT \'pending\'',
                'sent_at' => 'TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP',
                'updated_at' => 'TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP',
                'checked_at' => 'TIMESTAMP NULL DEFAULT NULL',
            ];

            $addBetMsg = [];
            foreach ($wantBetMsg as $name => $typeSql) {
                if (!isset($betMsgCols[$name])) {
                    $addBetMsg[] = 'ADD COLUMN `' . $name . '` ' . $typeSql;
                }
            }
            if ($addBetMsg !== []) {
                $pdo->exec('ALTER TABLE `bet_messages` ' . implode(', ', $addBetMsg));
            }

            try {
                $pdo->exec('ALTER TABLE `bet_messages` ADD KEY `idx_match_id` (`match_id`)');
            } catch (PDOException) {
                // already exists
            }
            try {
                $pdo->exec('ALTER TABLE `bet_messages` ADD KEY `idx_match_algorithm` (`match_id`, `algorithm_id`)');
            } catch (PDOException) {
                // already exists
            }
            try {
                $pdo->exec('ALTER TABLE `bet_messages` ADD KEY `idx_bet_status` (`bet_status`)');
            } catch (PDOException) {
                // already exists
            }
            try {
                $pdo->exec('ALTER TABLE `bet_messages` ADD KEY `idx_sent_at` (`sent_at`)');
            } catch (PDOException) {
                // already exists
            }
        }

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS `telegram_users` ('
            . '  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,'
            . '  `telegram_user_id` BIGINT NOT NULL,'
            . '  `username` VARCHAR(255) NULL,'
            . '  `first_name` VARCHAR(255) NULL,'
            . '  `last_name` VARCHAR(255) NULL,'
            . '  `ai_balance` INT NOT NULL DEFAULT 0,'
            . '  `last_interaction_at` DATETIME NULL,'
            . '  `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,'
            . '  `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,'
            . '  PRIMARY KEY (`id`),'
            . '  UNIQUE KEY `uniq_telegram_user_id` (`telegram_user_id`)'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );

        $telegramUserCols = array_flip(self::getTableColumns($pdo, 'telegram_users'));
        if (count($telegramUserCols) > 0 && !isset($telegramUserCols['id'])) {
            throw new \RuntimeException('Invalid schema: telegram_users.id column is missing');
        }

        if (count($telegramUserCols) > 0) {
            $wantTelegramUsers = [
                'telegram_user_id' => 'BIGINT NOT NULL',
                'username' => 'VARCHAR(255) NULL',
                'first_name' => 'VARCHAR(255) NULL',
                'last_name' => 'VARCHAR(255) NULL',
                'ai_balance' => 'INT NOT NULL DEFAULT 0',
                'last_interaction_at' => 'DATETIME NULL',
                'created_at' => 'TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP',
                'updated_at' => 'TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP',
            ];

            $addTelegramUsers = [];
            foreach ($wantTelegramUsers as $name => $typeSql) {
                if (!isset($telegramUserCols[$name])) {
                    $addTelegramUsers[] = 'ADD COLUMN `' . $name . '` ' . $typeSql;
                }
            }
            if ($addTelegramUsers !== []) {
                $pdo->exec('ALTER TABLE `telegram_users` ' . implode(', ', $addTelegramUsers));
            }

            try {
                $pdo->exec('ALTER TABLE `telegram_users` ADD UNIQUE KEY `uniq_telegram_user_id` (`telegram_user_id`)');
            } catch (PDOException) {
                // already exists
            }

            if (self::hasTableIndex($pdo, 'telegram_users', 'idx_subscription_until')) {
                try {
                    $pdo->exec('ALTER TABLE `telegram_users` DROP INDEX `idx_subscription_until`');
                } catch (PDOException) {
                    // ignore
                }
            }

            if (isset($telegramUserCols['subscription_until'])) {
                try {
                    $pdo->exec('ALTER TABLE `telegram_users` DROP COLUMN `subscription_until`');
                } catch (PDOException) {
                    // ignore
                }
            }
        }

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS `ai_analysis_requests` ('
            . '  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,'
            . '  `telegram_user_id` BIGINT NOT NULL,'
            . '  `match_id` BIGINT UNSIGNED NOT NULL,'
            . '  `bet_message_id` BIGINT UNSIGNED NULL,'
            . '  `provider` VARCHAR(32) NOT NULL DEFAULT \'gemini\','
            . '  `model_name` VARCHAR(128) NULL,'
            . '  `status` ENUM(\'pending\', \'completed\', \'failed\') NOT NULL DEFAULT \'pending\','
            . '  `cost_charged` INT NOT NULL DEFAULT 0,'
            . '  `prompt_text` LONGTEXT NOT NULL,'
            . '  `response_text` LONGTEXT NULL,'
            . '  `error_text` TEXT NULL,'
            . '  `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,'
            . '  `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,'
            . '  PRIMARY KEY (`id`),'
            . '  UNIQUE KEY `uniq_ai_user_match` (`telegram_user_id`, `match_id`),'
            . '  KEY `idx_ai_match_id` (`match_id`),'
            . '  KEY `idx_ai_status` (`status`)'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );

        $analysisCols = array_flip(self::getTableColumns($pdo, 'ai_analysis_requests'));
        if (count($analysisCols) > 0 && !isset($analysisCols['id'])) {
            throw new \RuntimeException('Invalid schema: ai_analysis_requests.id column is missing');
        }

        if (count($analysisCols) > 0) {
            $wantAnalysis = [
                'telegram_user_id' => 'BIGINT NOT NULL',
                'match_id' => 'BIGINT UNSIGNED NOT NULL',
                'bet_message_id' => 'BIGINT UNSIGNED NULL',
                'provider' => 'VARCHAR(32) NOT NULL DEFAULT \'gemini\'',
                'model_name' => 'VARCHAR(128) NULL',
                'status' => 'ENUM(\'pending\', \'completed\', \'failed\') NOT NULL DEFAULT \'pending\'',
                'cost_charged' => 'INT NOT NULL DEFAULT 0',
                'prompt_text' => 'LONGTEXT NOT NULL',
                'response_text' => 'LONGTEXT NULL',
                'error_text' => 'TEXT NULL',
                'created_at' => 'TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP',
                'updated_at' => 'TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP',
            ];

            $addAnalysis = [];
            foreach ($wantAnalysis as $name => $typeSql) {
                if (!isset($analysisCols[$name])) {
                    $addAnalysis[] = 'ADD COLUMN `' . $name . '` ' . $typeSql;
                }
            }
            if ($addAnalysis !== []) {
                $pdo->exec('ALTER TABLE `ai_analysis_requests` ' . implode(', ', $addAnalysis));
            }

            try {
                $pdo->exec('ALTER TABLE `ai_analysis_requests` ADD UNIQUE KEY `uniq_ai_user_match` (`telegram_user_id`, `match_id`)');
            } catch (PDOException) {
                // already exists
            }
            try {
                $pdo->exec('ALTER TABLE `ai_analysis_requests` ADD KEY `idx_ai_match_id` (`match_id`)');
            } catch (PDOException) {
                // already exists
            }
            try {
                $pdo->exec('ALTER TABLE `ai_analysis_requests` ADD KEY `idx_ai_status` (`status`)');
            } catch (PDOException) {
                // already exists
            }
        }

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS `gemini_api_keys` ('
            . '  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,'
            . '  `api_key` VARCHAR(255) NOT NULL,'
            . '  `is_active` TINYINT(1) NOT NULL DEFAULT 1,'
            . '  `last_error` TEXT NULL,'
            . '  `fail_count` INT NOT NULL DEFAULT 0,'
            . '  `last_used_at` DATETIME NULL,'
            . '  `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,'
            . '  `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,'
            . '  PRIMARY KEY (`id`),'
            . '  UNIQUE KEY `uniq_gemini_api_key` (`api_key`),'
            . '  KEY `idx_gemini_keys_active` (`is_active`)'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );

        $geminiKeyCols = array_flip(self::getTableColumns($pdo, 'gemini_api_keys'));
        if (count($geminiKeyCols) > 0 && !isset($geminiKeyCols['id'])) {
            throw new \RuntimeException('Invalid schema: gemini_api_keys.id column is missing');
        }

        if (count($geminiKeyCols) > 0) {
            $wantGeminiKeys = [
                'api_key' => 'VARCHAR(255) NOT NULL',
                'is_active' => 'TINYINT(1) NOT NULL DEFAULT 1',
                'last_error' => 'TEXT NULL',
                'fail_count' => 'INT NOT NULL DEFAULT 0',
                'last_used_at' => 'DATETIME NULL',
                'created_at' => 'TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP',
                'updated_at' => 'TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP',
            ];

            $addGeminiKeys = [];
            foreach ($wantGeminiKeys as $name => $typeSql) {
                if (!isset($geminiKeyCols[$name])) {
                    $addGeminiKeys[] = 'ADD COLUMN `' . $name . '` ' . $typeSql;
                }
            }
            if ($addGeminiKeys !== []) {
                $pdo->exec('ALTER TABLE `gemini_api_keys` ' . implode(', ', $addGeminiKeys));
            }

            try {
                $pdo->exec('ALTER TABLE `gemini_api_keys` ADD UNIQUE KEY `uniq_gemini_api_key` (`api_key`)');
            } catch (PDOException) {
                // already exists
            }
            try {
                $pdo->exec('ALTER TABLE `gemini_api_keys` ADD KEY `idx_gemini_keys_active` (`is_active`)');
            } catch (PDOException) {
                // already exists
            }
        }

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS `gemini_models` ('
            . '  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,'
            . '  `model_name` VARCHAR(128) NOT NULL,'
            . '  `is_active` TINYINT(1) NOT NULL DEFAULT 1,'
            . '  `last_error` TEXT NULL,'
            . '  `fail_count` INT NOT NULL DEFAULT 0,'
            . '  `last_used_at` DATETIME NULL,'
            . '  `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,'
            . '  `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,'
            . '  PRIMARY KEY (`id`),'
            . '  UNIQUE KEY `uniq_gemini_model_name` (`model_name`),'
            . '  KEY `idx_gemini_models_active` (`is_active`)'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );

        $geminiModelCols = array_flip(self::getTableColumns($pdo, 'gemini_models'));
        if (count($geminiModelCols) > 0 && !isset($geminiModelCols['id'])) {
            throw new \RuntimeException('Invalid schema: gemini_models.id column is missing');
        }

        if (count($geminiModelCols) > 0) {
            $wantGeminiModels = [
                'model_name' => 'VARCHAR(128) NOT NULL',
                'is_active' => 'TINYINT(1) NOT NULL DEFAULT 1',
                'last_error' => 'TEXT NULL',
                'fail_count' => 'INT NOT NULL DEFAULT 0',
                'last_used_at' => 'DATETIME NULL',
                'created_at' => 'TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP',
                'updated_at' => 'TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP',
            ];

            $addGeminiModels = [];
            foreach ($wantGeminiModels as $name => $typeSql) {
                if (!isset($geminiModelCols[$name])) {
                    $addGeminiModels[] = 'ADD COLUMN `' . $name . '` ' . $typeSql;
                }
            }
            if ($addGeminiModels !== []) {
                $pdo->exec('ALTER TABLE `gemini_models` ' . implode(', ', $addGeminiModels));
            }

            try {
                $pdo->exec('ALTER TABLE `gemini_models` ADD UNIQUE KEY `uniq_gemini_model_name` (`model_name`)');
            } catch (PDOException) {
                // already exists
            }
            try {
                $pdo->exec('ALTER TABLE `gemini_models` ADD KEY `idx_gemini_models_active` (`is_active`)');
            } catch (PDOException) {
                // already exists
            }
        }
    }

    /** @return array<int,array<string,mixed>> */
    public static function getActiveBans(PDO $pdo): array
    {
        try {
            $stmt = $pdo->query('SELECT `id`,`country`,`liga`,`home`,`away`,`is_active`,`created_at`,`updated_at` FROM `bans` WHERE `is_active`=1 ORDER BY `id` ASC');
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            return is_array($rows) ? $rows : [];
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
     * @param array<string,mixed> $data keys: country,liga,home,away,is_active?
     * @return int inserted id
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

        return (int) $pdo->lastInsertId();
    }

    /**
     * @param array<string,?string> $data keys: country,liga,home,away
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

        return $stmt->rowCount() > 0;
    }

    public static function deleteBan(PDO $pdo, int $id): bool
    {
        $stmt = $pdo->prepare('DELETE FROM `bans` WHERE `id`=?');
        $stmt->execute([$id]);

        return $stmt->rowCount() > 0;
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

        // Detect schema and map available columns.
        $columns = self::getTableColumns($pdo, 'matches');
        if ($columns === []) {
            throw new \RuntimeException('Table matches not found or no columns');
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
        foreach ($allFields as $f) {
            if (isset($available[$f])) {
                $fields[] = $f;
            }
        }

        if (!in_array('evid', $fields, true)) {
            throw new \RuntimeException('matches.evid column is required');
        }

        // On повторных запусках обновляем только коэффициенты/линейные поля,
        // а поля идентификации/справочники/служебные не трогаем.
        // SGI: обновляем ТОЛЬКО если в базе NULL.
        $immutable = [
            'id',
            'evid',
            'start_time',
            // updated by live parser
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
            static fn($f) => !in_array($f, $immutable, true)
        ));

        $placeholders = implode(',', array_fill(0, count($fields), '?'));
        $colList = implode(',', array_map(static fn($c) => '`' . $c . '`', $fields));

        $updateSql = '';
        if ($updateFields !== []) {
            $pairs = [];
            foreach ($updateFields as $c) {
                if ($c === 'sgi') {
                    // Update SGI only if it was NULL in DB.
                    $pairs[] = '`sgi`=IF(`sgi` IS NULL, VALUES(`sgi`), `sgi`)';
                    continue;
                }
                $pairs[] = sprintf('`%s`=VALUES(`%s`)', $c, $c);
            }
            $updateSql = ' ON DUPLICATE KEY UPDATE ' . implode(',', $pairs);
        }

        $sql = sprintf('INSERT INTO `matches` (%s) VALUES (%s)%s', $colList, $placeholders, $updateSql);
        $stmt = $pdo->prepare($sql);

        $pdo->beginTransaction();
        try {
            foreach ($matches as $m) {
                if (!is_array($m) || !isset($m['evid'])) {
                    $skipped++;
                    continue;
                }

                $row = [];
                foreach ($fields as $f) {
                    $row[] = $m[$f] ?? null;
                }

                $stmt->execute($row);

                $affected = $stmt->rowCount();
                if ($affected === 1) {
                    $inserted++;
                } elseif ($affected === 2) {
                    $updated++;
                } else {
                    $updated++;
                }
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            Logger::error('Transaction failed', ['error' => $e->getMessage()]);
            throw $e;
        }

        return compact('inserted', 'updated', 'skipped');
    }

    /** @return array<int,string> */
    private static function getTableColumns(PDO $pdo, string $table): array
    {
        $stmt = $pdo->prepare('SHOW COLUMNS FROM `' . str_replace('`', '``', $table) . '`');
        try {
            $stmt->execute();
        } catch (\Throwable) {
            return [];
        }

        $cols = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            if (is_array($row) && isset($row['Field']) && is_string($row['Field'])) {
                $cols[] = $row['Field'];
            }
        }

        return $cols;
    }

    private static function hasTableIndex(PDO $pdo, string $table, string $index): bool
    {
        $stmt = $pdo->prepare('SHOW INDEX FROM `' . str_replace('`', '``', $table) . '` WHERE `Key_name` = ?');
        try {
            $stmt->execute([$index]);
        } catch (\Throwable) {
            return false;
        }

        return $stmt->fetch(PDO::FETCH_ASSOC) !== false;
    }
}
