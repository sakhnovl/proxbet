-- Audit logs table for tracking admin actions and security events

CREATE TABLE IF NOT EXISTS `audit_logs` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `event_type` VARCHAR(50) NOT NULL COMMENT 'admin_action, security_event, auth_attempt',
    `action` VARCHAR(100) NOT NULL COMMENT 'Specific action performed',
    `user_id` VARCHAR(100) NULL COMMENT 'User who performed the action',
    `resource_type` VARCHAR(50) NULL COMMENT 'Type of resource affected (ban, match, etc)',
    `resource_id` INT NULL COMMENT 'ID of affected resource',
    `metadata` JSON NULL COMMENT 'Additional context data',
    `ip_address` VARCHAR(45) NULL COMMENT 'IP address of requester',
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_event_type` (`event_type`),
    INDEX `idx_user_id` (`user_id`),
    INDEX `idx_created_at` (`created_at`),
    INDEX `idx_resource` (`resource_type`, `resource_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
