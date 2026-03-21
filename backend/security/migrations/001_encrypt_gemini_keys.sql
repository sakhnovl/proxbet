-- Migration: Encrypt Gemini API keys
-- Phase 1: Security fixes - encrypt sensitive data in database

-- Add column to track if key is encrypted
ALTER TABLE `gemini_api_keys` 
ADD COLUMN `is_encrypted` TINYINT(1) NOT NULL DEFAULT 0 AFTER `api_key`;

-- Add index for better performance
ALTER TABLE `gemini_api_keys`
ADD INDEX `idx_is_active` (`is_active`);

-- Note: Actual encryption of existing keys must be done via PHP script
-- See: scripts/encrypt_existing_gemini_keys.php
