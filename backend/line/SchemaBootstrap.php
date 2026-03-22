<?php

declare(strict_types=1);

namespace Proxbet\Line;

use PDO;
use PDOException;

final class SchemaBootstrap
{
    /** @return array<int,string> */
    public static function requiredTables(): array
    {
        return [
            'matches',
            'live_match_snapshots',
            'bans',
            'bet_messages',
            'telegram_users',
            'ai_analysis_requests',
            'gemini_api_keys',
            'gemini_models',
        ];
    }

    /** @return array<string,string> */
    public static function requiredMatchesColumns(): array
    {
        return [
            'sgi' => 'VARCHAR(64) NULL',
            'sgi_json' => 'LONGTEXT NULL',
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
            'live_trend_shots_total_delta' => 'INT NULL',
            'live_trend_shots_on_target_delta' => 'INT NULL',
            'live_trend_danger_attacks_delta' => 'INT NULL',
            'live_trend_xg_delta' => 'DOUBLE NULL',
            'live_trend_window_seconds' => 'INT NULL',
            'live_trend_has_data' => 'TINYINT(1) NOT NULL DEFAULT 0',
            'stats_updated_at' => 'DATETIME NULL',
            'stats_fetch_status' => 'VARCHAR(32) NULL',
            'stats_error' => 'TEXT NULL',
            'stats_source' => 'VARCHAR(32) NULL',
            'stats_version' => 'VARCHAR(32) NULL',
            'stats_debug_json' => 'LONGTEXT NULL',
            'stats_refresh_needed' => 'TINYINT(1) NOT NULL DEFAULT 0',
            'live_updated_at' => 'TIMESTAMP NULL DEFAULT NULL',
            'created_at' => 'TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP',
            'updated_at' => 'TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP',
        ];
    }

    public static function ensure(PDO $pdo): void
    {
        self::ensureMatchesTable($pdo);
        self::ensureLiveMatchSnapshotsTable($pdo);
        self::ensureBansTable($pdo);
        self::ensureBetMessagesTable($pdo);
        self::ensureTelegramUsersTable($pdo);
        self::ensureAiAnalysisRequestsTable($pdo);
        self::ensureGeminiApiKeysTable($pdo);
        self::ensureGeminiModelsTable($pdo);
    }

    /** @return array<int,string> */
    public static function getTableColumns(PDO $pdo, string $table): array
    {
        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        
        if ($driver === 'sqlite') {
            // SQLite uses PRAGMA table_info
            try {
                $stmt = $pdo->prepare('PRAGMA table_info(' . $pdo->quote($table) . ')');
                $stmt->execute();
            } catch (\Throwable) {
                return [];
            }
            
            $cols = [];
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                if (is_array($row) && isset($row['name']) && is_string($row['name'])) {
                    $cols[] = $row['name'];
                }
            }
            return $cols;
        }
        
        // MySQL/MariaDB uses SHOW COLUMNS
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

    public static function hasTableIndex(PDO $pdo, string $table, string $index): bool
    {
        $stmt = $pdo->prepare('SHOW INDEX FROM `' . str_replace('`', '``', $table) . '` WHERE `Key_name` = ?');
        try {
            $stmt->execute([$index]);
        } catch (\Throwable) {
            return false;
        }

        return $stmt->fetch(PDO::FETCH_ASSOC) !== false;
    }

    private static function ensureMatchesTable(PDO $pdo): void
    {
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
            . '  KEY `idx_matches_start_time` (`start_time`),'
            . '  KEY `idx_matches_match_status` (`match_status`),'
            . '  KEY `idx_matches_country_liga` (`country`, `liga`),'
            . '  KEY `idx_matches_live_updated_at` (`live_updated_at`),'
            . '  KEY `idx_matches_stats_refresh_needed` (`stats_refresh_needed`),'
            . '  KEY `idx_matches_stats_fetch_status` (`stats_fetch_status`),'
            . '  KEY `idx_matches_stats_updated_at` (`stats_updated_at`),'
            . '  KEY `idx_matches_scanner_query` (`match_status`, `start_time`, `stats_fetch_status`),'
            . '  KEY `idx_matches_stats_batch` (`stats_refresh_needed`, `stats_fetch_status`, `stats_updated_at`, `id`),'
            . '  KEY `idx_matches_cleanup` (`match_status`, `time`)'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );

        $cols = array_flip(self::getTableColumns($pdo, 'matches'));
        if (!isset($cols['evid'])) {
            throw new \RuntimeException('Invalid schema: matches.evid column is missing');
        }

        $want = self::requiredMatchesColumns();

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

        try {
            $pdo->exec('ALTER TABLE `matches` MODIFY COLUMN `time` VARCHAR(16) NULL AFTER `start_time`');
        } catch (PDOException) {
        }

        try {
            $pdo->exec(
                'UPDATE `matches` '
                . 'SET `time`=CONCAT('
                . 'LPAD(FLOOR(CAST(`time` AS UNSIGNED)/60),2,\'0\'),\':\',LPAD(MOD(CAST(`time` AS UNSIGNED),60),2,\'0\')'
                . ') '
                . 'WHERE `time` REGEXP \'^[0-9]+$\''
            );
        } catch (PDOException) {
        }

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

        self::tryAddIndex($pdo, 'ALTER TABLE `matches` ADD UNIQUE KEY `uniq_matches_evid` (`evid`)');
        self::tryAddIndex($pdo, 'ALTER TABLE `matches` ADD KEY `idx_matches_sgi` (`sgi`)');
        self::tryAddIndex($pdo, 'ALTER TABLE `matches` ADD KEY `idx_matches_start_time` (`start_time`)');
        self::tryAddIndex($pdo, 'ALTER TABLE `matches` ADD KEY `idx_matches_match_status` (`match_status`)');
        self::tryAddIndex($pdo, 'ALTER TABLE `matches` ADD KEY `idx_matches_country_liga` (`country`, `liga`)');
        self::tryAddIndex($pdo, 'ALTER TABLE `matches` ADD KEY `idx_matches_live_updated_at` (`live_updated_at`)');
        self::tryAddIndex($pdo, 'ALTER TABLE `matches` ADD KEY `idx_matches_stats_refresh_needed` (`stats_refresh_needed`)');
        self::tryAddIndex($pdo, 'ALTER TABLE `matches` ADD KEY `idx_matches_stats_fetch_status` (`stats_fetch_status`)');
        self::tryAddIndex($pdo, 'ALTER TABLE `matches` ADD KEY `idx_matches_stats_updated_at` (`stats_updated_at`)');
        self::tryAddIndex($pdo, 'ALTER TABLE `matches` ADD KEY `idx_matches_scanner_query` (`match_status`, `start_time`, `stats_fetch_status`)');
        self::tryAddIndex($pdo, 'ALTER TABLE `matches` ADD KEY `idx_matches_stats_batch` (`stats_refresh_needed`, `stats_fetch_status`, `stats_updated_at`, `id`)');
        self::tryAddIndex($pdo, 'ALTER TABLE `matches` ADD KEY `idx_matches_cleanup` (`match_status`, `time`)');
    }

    private static function ensureLiveMatchSnapshotsTable(PDO $pdo): void
    {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS `live_match_snapshots` ('
            . '  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,'
            . '  `match_id` BIGINT UNSIGNED NOT NULL,'
            . '  `evid` VARCHAR(64) NOT NULL,'
            . '  `minute` INT NOT NULL,'
            . '  `time` VARCHAR(16) NULL,'
            . '  `match_status` VARCHAR(255) NULL,'
            . '  `live_ht_hscore` INT NULL,'
            . '  `live_ht_ascore` INT NULL,'
            . '  `live_hscore` INT NULL,'
            . '  `live_ascore` INT NULL,'
            . '  `live_xg_home` DOUBLE NULL,'
            . '  `live_xg_away` DOUBLE NULL,'
            . '  `live_att_home` DOUBLE NULL,'
            . '  `live_att_away` DOUBLE NULL,'
            . '  `live_danger_att_home` DOUBLE NULL,'
            . '  `live_danger_att_away` DOUBLE NULL,'
            . '  `live_shots_on_target_home` DOUBLE NULL,'
            . '  `live_shots_on_target_away` DOUBLE NULL,'
            . '  `live_shots_off_target_home` DOUBLE NULL,'
            . '  `live_shots_off_target_away` DOUBLE NULL,'
            . '  `live_yellow_cards_home` DOUBLE NULL,'
            . '  `live_yellow_cards_away` DOUBLE NULL,'
            . '  `live_safe_home` DOUBLE NULL,'
            . '  `live_safe_away` DOUBLE NULL,'
            . '  `live_corner_home` DOUBLE NULL,'
            . '  `live_corner_away` DOUBLE NULL,'
            . '  `captured_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,'
            . '  PRIMARY KEY (`id`),'
            . '  UNIQUE KEY `uniq_live_snapshot_match_minute_status` (`match_id`, `minute`, `match_status`),'
            . '  KEY `idx_live_snapshots_match_captured` (`match_id`, `captured_at`),'
            . '  KEY `idx_live_snapshots_evid_captured` (`evid`, `captured_at`)'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );

        self::tryAddIndex(
            $pdo,
            'ALTER TABLE `live_match_snapshots` ADD UNIQUE KEY `uniq_live_snapshot_match_minute_status` (`match_id`, `minute`, `match_status`)'
        );
        self::tryAddIndex(
            $pdo,
            'ALTER TABLE `live_match_snapshots` ADD KEY `idx_live_snapshots_match_captured` (`match_id`, `captured_at`)'
        );
        self::tryAddIndex(
            $pdo,
            'ALTER TABLE `live_match_snapshots` ADD KEY `idx_live_snapshots_evid_captured` (`evid`, `captured_at`)'
        );
    }

    private static function ensureBansTable(PDO $pdo): void
    {
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

        self::tryAddIndex($pdo, 'ALTER TABLE `bans` ADD KEY `idx_bans_created_at` (`created_at`)');
        self::tryAddIndex($pdo, 'ALTER TABLE `bans` ADD KEY `idx_bans_is_active` (`is_active`)');
    }

    private static function ensureBetMessagesTable(PDO $pdo): void
    {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS `bet_messages` ('
            . '  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,'
            . '  `match_id` BIGINT UNSIGNED NOT NULL,'
            . '  `message_id` BIGINT NOT NULL,'
            . '  `chat_id` VARCHAR(64) NOT NULL,'
            . '  `message_text` TEXT NOT NULL,'
            . '  `algorithm_id` TINYINT UNSIGNED NOT NULL DEFAULT 1,'
            . '  `algorithm_name` VARCHAR(64) NOT NULL DEFAULT \'РђР»РіРѕСЂРёС‚Рј 1\','
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

        $betMsgCols = array_flip(self::getTableColumns($pdo, 'bet_messages'));
        if (count($betMsgCols) > 0 && !isset($betMsgCols['id'])) {
            throw new \RuntimeException('Invalid schema: bet_messages.id column is missing');
        }

        if (count($betMsgCols) === 0) {
            return;
        }

        $wantBetMsg = [
            'match_id' => 'BIGINT UNSIGNED NOT NULL',
            'message_id' => 'BIGINT NOT NULL',
            'chat_id' => 'VARCHAR(64) NOT NULL',
            'message_text' => 'TEXT NOT NULL',
            'algorithm_id' => 'TINYINT UNSIGNED NOT NULL DEFAULT 1',
            'algorithm_name' => 'VARCHAR(64) NOT NULL DEFAULT \'РђР»РіРѕСЂРёС‚Рј 1\'',
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

        self::tryAddIndex($pdo, 'ALTER TABLE `bet_messages` ADD KEY `idx_match_id` (`match_id`)');
        self::tryAddIndex($pdo, 'ALTER TABLE `bet_messages` ADD KEY `idx_match_algorithm` (`match_id`, `algorithm_id`)');
        self::tryAddIndex($pdo, 'ALTER TABLE `bet_messages` ADD KEY `idx_bet_status` (`bet_status`)');
        self::tryAddIndex($pdo, 'ALTER TABLE `bet_messages` ADD KEY `idx_sent_at` (`sent_at`)');
        self::tryAddIndex($pdo, 'ALTER TABLE `bet_messages` ADD KEY `idx_bet_messages_checked_at` (`checked_at`)');
        self::tryAddIndex($pdo, 'ALTER TABLE `bet_messages` ADD KEY `idx_bet_messages_status_sent` (`bet_status`, `sent_at`)');
    }

    private static function ensureTelegramUsersTable(PDO $pdo): void
    {
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

        if (count($telegramUserCols) === 0) {
            return;
        }

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

        self::tryAddIndex($pdo, 'ALTER TABLE `telegram_users` ADD UNIQUE KEY `uniq_telegram_user_id` (`telegram_user_id`)');
        self::tryAddIndex($pdo, 'ALTER TABLE `telegram_users` ADD KEY `idx_telegram_users_last_interaction` (`last_interaction_at`)');

        if (self::hasTableIndex($pdo, 'telegram_users', 'idx_subscription_until')) {
            try {
                $pdo->exec('ALTER TABLE `telegram_users` DROP INDEX `idx_subscription_until`');
            } catch (PDOException) {
            }
        }

        if (isset($telegramUserCols['subscription_until'])) {
            try {
                $pdo->exec('ALTER TABLE `telegram_users` DROP COLUMN `subscription_until`');
            } catch (PDOException) {
            }
        }
    }

    private static function ensureAiAnalysisRequestsTable(PDO $pdo): void
    {
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

        if (count($analysisCols) === 0) {
            return;
        }

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

        self::tryAddIndex($pdo, 'ALTER TABLE `ai_analysis_requests` ADD UNIQUE KEY `uniq_ai_user_match` (`telegram_user_id`, `match_id`)');
        self::tryAddIndex($pdo, 'ALTER TABLE `ai_analysis_requests` ADD KEY `idx_ai_match_id` (`match_id`)');
        self::tryAddIndex($pdo, 'ALTER TABLE `ai_analysis_requests` ADD KEY `idx_ai_status` (`status`)');
        self::tryAddIndex($pdo, 'ALTER TABLE `ai_analysis_requests` ADD KEY `idx_ai_analysis_created_at` (`created_at`)');
        self::tryAddIndex($pdo, 'ALTER TABLE `ai_analysis_requests` ADD KEY `idx_ai_analysis_status_created` (`status`, `created_at`)');
    }

    private static function ensureGeminiApiKeysTable(PDO $pdo): void
    {
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

        if (count($geminiKeyCols) === 0) {
            return;
        }

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

        self::tryAddIndex($pdo, 'ALTER TABLE `gemini_api_keys` ADD UNIQUE KEY `uniq_gemini_api_key` (`api_key`)');
        self::tryAddIndex($pdo, 'ALTER TABLE `gemini_api_keys` ADD KEY `idx_gemini_keys_active` (`is_active`)');
        self::tryAddIndex($pdo, 'ALTER TABLE `gemini_api_keys` ADD KEY `idx_gemini_keys_active_last_used` (`is_active`, `last_used_at`)');
    }

    private static function ensureGeminiModelsTable(PDO $pdo): void
    {
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

        if (count($geminiModelCols) === 0) {
            return;
        }

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

        self::tryAddIndex($pdo, 'ALTER TABLE `gemini_models` ADD UNIQUE KEY `uniq_gemini_model_name` (`model_name`)');
        self::tryAddIndex($pdo, 'ALTER TABLE `gemini_models` ADD KEY `idx_gemini_models_active` (`is_active`)');
        self::tryAddIndex($pdo, 'ALTER TABLE `gemini_models` ADD KEY `idx_gemini_models_active_last_used` (`is_active`, `last_used_at`)');
    }

    private static function tryAddIndex(PDO $pdo, string $sql): void
    {
        try {
            $pdo->exec($sql);
        } catch (PDOException) {
        }
    }
}
