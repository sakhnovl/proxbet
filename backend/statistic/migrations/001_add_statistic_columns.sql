-- Migration: Add statistic columns to matches table
-- Version: ht-v2
-- Date: 2026-03-20

-- Half-time metrics for last 5 matches (Q data)
ALTER TABLE `matches`
    ADD COLUMN IF NOT EXISTS `ht_match_goals_1` INT NULL COMMENT 'Home: matches with goals in last 5',
    ADD COLUMN IF NOT EXISTS `ht_match_missed_goals_1` INT NULL COMMENT 'Home: matches with missed goals in last 5',
    ADD COLUMN IF NOT EXISTS `ht_match_goals_1_avg` DOUBLE NULL COMMENT 'Home: average goals scored in last 5',
    ADD COLUMN IF NOT EXISTS `ht_match_missed_1_avg` DOUBLE NULL COMMENT 'Home: average goals missed in last 5',
    ADD COLUMN IF NOT EXISTS `ht_match_goals_2` INT NULL COMMENT 'Away: matches with goals in last 5',
    ADD COLUMN IF NOT EXISTS `ht_match_missed_goals_2` INT NULL COMMENT 'Away: matches with missed goals in last 5',
    ADD COLUMN IF NOT EXISTS `ht_match_goals_2_avg` DOUBLE NULL COMMENT 'Away: average goals scored in last 5',
    ADD COLUMN IF NOT EXISTS `ht_match_missed_2_avg` DOUBLE NULL COMMENT 'Away: average goals missed in last 5';

-- Half-time metrics for head-to-head last 5 matches (G data)
ALTER TABLE `matches`
    ADD COLUMN IF NOT EXISTS `h2h_ht_match_goals_1` INT NULL COMMENT 'Home: H2H matches with goals in last 5',
    ADD COLUMN IF NOT EXISTS `h2h_ht_match_missed_goals_1` INT NULL COMMENT 'Home: H2H matches with missed goals in last 5',
    ADD COLUMN IF NOT EXISTS `h2h_ht_match_goals_1_avg` DOUBLE NULL COMMENT 'Home: H2H average goals scored in last 5',
    ADD COLUMN IF NOT EXISTS `h2h_ht_match_missed_1_avg` DOUBLE NULL COMMENT 'Home: H2H average goals missed in last 5',
    ADD COLUMN IF NOT EXISTS `h2h_ht_match_goals_2` INT NULL COMMENT 'Away: H2H matches with goals in last 5',
    ADD COLUMN IF NOT EXISTS `h2h_ht_match_missed_goals_2` INT NULL COMMENT 'Away: H2H matches with missed goals in last 5',
    ADD COLUMN IF NOT EXISTS `h2h_ht_match_goals_2_avg` DOUBLE NULL COMMENT 'Away: H2H average goals scored in last 5',
    ADD COLUMN IF NOT EXISTS `h2h_ht_match_missed_2_avg` DOUBLE NULL COMMENT 'Away: H2H average goals missed in last 5';

-- Tournament table statistics (S data)
ALTER TABLE `matches`
    ADD COLUMN IF NOT EXISTS `table_games_1` INT NULL COMMENT 'Home: games played in tournament',
    ADD COLUMN IF NOT EXISTS `table_goals_1` INT NULL COMMENT 'Home: goals scored in tournament',
    ADD COLUMN IF NOT EXISTS `table_missed_1` INT NULL COMMENT 'Home: goals missed in tournament',
    ADD COLUMN IF NOT EXISTS `table_games_2` INT NULL COMMENT 'Away: games played in tournament',
    ADD COLUMN IF NOT EXISTS `table_goals_2` INT NULL COMMENT 'Away: goals scored in tournament',
    ADD COLUMN IF NOT EXISTS `table_missed_2` INT NULL COMMENT 'Away: goals missed in tournament',
    ADD COLUMN IF NOT EXISTS `table_avg` DECIMAL(10,2) NULL COMMENT 'Tournament average goals per game';

-- Metadata columns for tracking statistics updates
ALTER TABLE `matches`
    ADD COLUMN IF NOT EXISTS `stats_updated_at` DATETIME NULL COMMENT 'Last statistics update timestamp',
    ADD COLUMN IF NOT EXISTS `stats_fetch_status` VARCHAR(32) NULL COMMENT 'Status: ok, error',
    ADD COLUMN IF NOT EXISTS `stats_error` TEXT NULL COMMENT 'Error message if fetch failed',
    ADD COLUMN IF NOT EXISTS `stats_source` VARCHAR(32) NULL COMMENT 'Source: db, remote',
    ADD COLUMN IF NOT EXISTS `stats_version` VARCHAR(32) NULL COMMENT 'Statistics version (e.g., ht-v2)',
    ADD COLUMN IF NOT EXISTS `stats_debug_json` LONGTEXT NULL COMMENT 'Debug information in JSON format',
    ADD COLUMN IF NOT EXISTS `stats_refresh_needed` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Flag to force refresh';

-- Add indexes for performance
CREATE INDEX IF NOT EXISTS `idx_stats_updated_at` ON `matches` (`stats_updated_at`);
CREATE INDEX IF NOT EXISTS `idx_stats_fetch_status` ON `matches` (`stats_fetch_status`);
CREATE INDEX IF NOT EXISTS `idx_stats_version` ON `matches` (`stats_version`);
CREATE INDEX IF NOT EXISTS `idx_stats_refresh_needed` ON `matches` (`stats_refresh_needed`);
CREATE INDEX IF NOT EXISTS `idx_sgi` ON `matches` (`sgi`);
