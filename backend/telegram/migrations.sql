-- Миграции для модуля Telegram AI Analysis
-- Выполнить после основной схемы БД

-- Таблица для кэширования результатов AI-анализа
CREATE TABLE IF NOT EXISTS `ai_analysis_cache` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `match_id` INT UNSIGNED NOT NULL,
    `algorithm_id` TINYINT UNSIGNED NOT NULL DEFAULT 1,
    `response_text` TEXT NOT NULL,
    `created_at` DATETIME NOT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `unique_match_algorithm` (`match_id`, `algorithm_id`),
    KEY `idx_created_at` (`created_at`),
    KEY `idx_match_id` (`match_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Таблица для метрик использования AI-анализа
CREATE TABLE IF NOT EXISTS `ai_analysis_metrics` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `telegram_user_id` BIGINT NOT NULL,
    `match_id` INT UNSIGNED NOT NULL,
    `algorithm_id` TINYINT UNSIGNED NOT NULL DEFAULT 1,
    `provider` VARCHAR(50) NOT NULL DEFAULT 'gemini',
    `model_name` VARCHAR(100) NOT NULL,
    `success` TINYINT(1) NOT NULL DEFAULT 0,
    `response_time_ms` INT UNSIGNED NOT NULL DEFAULT 0,
    `error_type` VARCHAR(100) NULL,
    `created_at` DATETIME NOT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_telegram_user_id` (`telegram_user_id`),
    KEY `idx_match_id` (`match_id`),
    KEY `idx_algorithm_id` (`algorithm_id`),
    KEY `idx_created_at` (`created_at`),
    KEY `idx_success` (`success`),
    KEY `idx_model_name` (`model_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Индексы для оптимизации запросов статистики
CREATE INDEX `idx_metrics_stats` ON `ai_analysis_metrics` (`created_at`, `success`, `algorithm_id`);
CREATE INDEX `idx_metrics_user_time` ON `ai_analysis_metrics` (`telegram_user_id`, `created_at`);

-- Добавить поля в существующую таблицу telegram_users (если их нет)
ALTER TABLE `telegram_users` 
ADD COLUMN IF NOT EXISTS `rate_limit_reset_at` DATETIME NULL AFTER `ai_balance`,
ADD COLUMN IF NOT EXISTS `is_banned` TINYINT(1) NOT NULL DEFAULT 0 AFTER `rate_limit_reset_at`,
ADD COLUMN IF NOT EXISTS `ban_reason` VARCHAR(255) NULL AFTER `is_banned`;

-- Добавить индексы для rate limiting
CREATE INDEX IF NOT EXISTS `idx_user_rate_limit` ON `telegram_users` (`telegram_user_id`, `rate_limit_reset_at`);
CREATE INDEX IF NOT EXISTS `idx_user_banned` ON `telegram_users` (`is_banned`);

-- Добавить поля в ai_analysis_requests для улучшенного трекинга
ALTER TABLE `ai_analysis_requests`
ADD COLUMN IF NOT EXISTS `cache_hit` TINYINT(1) NOT NULL DEFAULT 0 AFTER `error_text`,
ADD COLUMN IF NOT EXISTS `response_time_ms` INT UNSIGNED NULL AFTER `cache_hit`;

CREATE INDEX IF NOT EXISTS `idx_requests_user_time` ON `ai_analysis_requests` (`telegram_user_id`, `created_at`);
CREATE INDEX IF NOT EXISTS `idx_requests_status` ON `ai_analysis_requests` (`status`, `created_at`);
