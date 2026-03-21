-- Migration 003: Add performance indexes
-- Date: 2026-03-21
-- Purpose: Optimize database queries for Phase 3 performance improvements

-- Add indexes for frequently queried columns in matches table
ALTER TABLE `matches` 
ADD INDEX IF NOT EXISTS `idx_matches_start_time` (`start_time`),
ADD INDEX IF NOT EXISTS `idx_matches_match_status` (`match_status`),
ADD INDEX IF NOT EXISTS `idx_matches_country_liga` (`country`, `liga`),
ADD INDEX IF NOT EXISTS `idx_matches_live_updated_at` (`live_updated_at`);

-- Add composite index for scanner queries
ALTER TABLE `matches`
ADD INDEX IF NOT EXISTS `idx_matches_scanner_query` (`match_status`, `start_time`, `stats_fetch_status`);

-- Add index for bet_messages queries
ALTER TABLE `bet_messages`
ADD INDEX IF NOT EXISTS `idx_bet_messages_checked_at` (`checked_at`),
ADD INDEX IF NOT EXISTS `idx_bet_messages_status_sent` (`bet_status`, `sent_at`);

-- Add index for telegram_users queries
ALTER TABLE `telegram_users`
ADD INDEX IF NOT EXISTS `idx_telegram_users_last_interaction` (`last_interaction_at`);

-- Add index for ai_analysis_requests queries
ALTER TABLE `ai_analysis_requests`
ADD INDEX IF NOT EXISTS `idx_ai_analysis_created_at` (`created_at`),
ADD INDEX IF NOT EXISTS `idx_ai_analysis_status_created` (`status`, `created_at`);

-- Optimize gemini_api_keys queries
ALTER TABLE `gemini_api_keys`
ADD INDEX IF NOT EXISTS `idx_gemini_keys_active_last_used` (`is_active`, `last_used_at`);

-- Optimize gemini_models queries
ALTER TABLE `gemini_models`
ADD INDEX IF NOT EXISTS `idx_gemini_models_active_last_used` (`is_active`, `last_used_at`);
