-- Migration 003: Add performance indexes
-- Date: 2026-03-21
-- Purpose: Optimize database queries for Phase 3 performance improvements

ALTER TABLE `matches` ADD INDEX `idx_matches_start_time` (`start_time`);
ALTER TABLE `matches` ADD INDEX `idx_matches_match_status` (`match_status`);
ALTER TABLE `matches` ADD INDEX `idx_matches_country_liga` (`country`, `liga`);
ALTER TABLE `matches` ADD INDEX `idx_matches_live_updated_at` (`live_updated_at`);
ALTER TABLE `matches` ADD INDEX `idx_matches_scanner_query` (`match_status`, `start_time`, `stats_fetch_status`);
ALTER TABLE `matches` ADD INDEX `idx_matches_stats_batch` (`stats_refresh_needed`, `stats_fetch_status`, `stats_updated_at`, `id`);
ALTER TABLE `matches` ADD INDEX `idx_matches_cleanup` (`match_status`, `time`);

ALTER TABLE `bet_messages` ADD INDEX `idx_bet_messages_checked_at` (`checked_at`);
ALTER TABLE `bet_messages` ADD INDEX `idx_bet_messages_status_sent` (`bet_status`, `sent_at`);

ALTER TABLE `telegram_users` ADD INDEX `idx_telegram_users_last_interaction` (`last_interaction_at`);

ALTER TABLE `ai_analysis_requests` ADD INDEX `idx_ai_analysis_created_at` (`created_at`);
ALTER TABLE `ai_analysis_requests` ADD INDEX `idx_ai_analysis_status_created` (`status`, `created_at`);

ALTER TABLE `gemini_api_keys` ADD INDEX `idx_gemini_keys_active_last_used` (`is_active`, `last_used_at`);
ALTER TABLE `gemini_models` ADD INDEX `idx_gemini_models_active_last_used` (`is_active`, `last_used_at`);
