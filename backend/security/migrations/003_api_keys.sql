-- Migration: API Keys Table
-- Description: Create table for API key authentication system
-- Date: 2026-03-21

CREATE TABLE IF NOT EXISTS api_keys (
    id INT AUTO_INCREMENT PRIMARY KEY,
    key_hash VARCHAR(64) NOT NULL UNIQUE COMMENT 'SHA-256 hash of API key',
    name VARCHAR(255) NOT NULL COMMENT 'Descriptive name for the key',
    permissions JSON NOT NULL COMMENT 'Array of permissions',
    rate_limit INT NOT NULL DEFAULT 100 COMMENT 'Requests per minute',
    is_active TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'Active status',
    created_at DATETIME NOT NULL,
    last_used_at DATETIME NULL COMMENT 'Last time key was used',
    revoked_at DATETIME NULL COMMENT 'When key was revoked',
    INDEX idx_key_hash (key_hash),
    INDEX idx_is_active (is_active),
    INDEX idx_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Add usage tracking table
CREATE TABLE IF NOT EXISTS api_key_usage (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    api_key_id INT NOT NULL,
    endpoint VARCHAR(255) NOT NULL,
    method VARCHAR(10) NOT NULL,
    ip_address VARCHAR(45) NOT NULL,
    user_agent TEXT,
    response_code INT NOT NULL,
    response_time_ms INT NOT NULL,
    created_at DATETIME NOT NULL,
    FOREIGN KEY (api_key_id) REFERENCES api_keys(id) ON DELETE CASCADE,
    INDEX idx_api_key_id (api_key_id),
    INDEX idx_created_at (created_at),
    INDEX idx_endpoint (endpoint)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
