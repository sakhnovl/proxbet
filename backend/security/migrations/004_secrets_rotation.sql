-- Migration: Secrets Rotation Tracking
-- Description: Create table for tracking secret rotation history
-- Date: 2026-03-21

CREATE TABLE IF NOT EXISTS secrets_rotation_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    secret_type VARCHAR(50) NOT NULL COMMENT 'Type of secret (api_key, gemini_key, etc)',
    secret_name VARCHAR(255) NOT NULL COMMENT 'Name/identifier of the secret',
    rotated_at DATETIME NOT NULL,
    rotated_by VARCHAR(100) NULL COMMENT 'Who performed the rotation',
    notes TEXT NULL COMMENT 'Additional notes about rotation',
    UNIQUE KEY unique_secret (secret_type, secret_name),
    INDEX idx_secret_type (secret_type),
    INDEX idx_rotated_at (rotated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Add last_rotated_at column to gemini_api_keys if not exists
ALTER TABLE gemini_api_keys 
ADD COLUMN IF NOT EXISTS last_rotated_at DATETIME NULL COMMENT 'Last time key was rotated';

-- Initialize last_rotated_at with created_at for existing keys
UPDATE gemini_api_keys 
SET last_rotated_at = created_at 
WHERE last_rotated_at IS NULL;
