-- ============================================================================
-- Audit Log System Migration
-- ============================================================================
-- Purpose: Create comprehensive audit logging table for tracking all system 
--          events including authentication, CRUD operations, and file uploads
-- Created: 2025-10-21
-- ============================================================================

CREATE TABLE IF NOT EXISTS `audit_log` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT NULL DEFAULT NULL COMMENT 'User who performed the action (NULL for system events)',
  `user_name` VARCHAR(255) NULL DEFAULT NULL COMMENT 'Username for display',
  `user_role` VARCHAR(255) NULL DEFAULT NULL COMMENT 'Comma-separated roles at time of action',
  `action` VARCHAR(50) NOT NULL COMMENT 'Action type: login, logout, insert, update, delete, upload, status_change, etc.',
  `object_type` VARCHAR(100) NOT NULL COMMENT 'Table name, auth, file, settings, etc.',
  `object_id` VARCHAR(100) NULL DEFAULT NULL COMMENT 'ID of affected record',
  `summary` TEXT NOT NULL COMMENT 'Human-readable description of the action',
  `old_data` LONGTEXT NULL DEFAULT NULL COMMENT 'JSON snapshot before change (for updates/deletes)',
  `new_data` LONGTEXT NULL DEFAULT NULL COMMENT 'JSON snapshot after change (for inserts/updates)',
  `ip_address` VARCHAR(45) NULL DEFAULT NULL COMMENT 'IPv4 or IPv6 address',
  `user_agent` VARCHAR(500) NULL DEFAULT NULL COMMENT 'Browser/client user agent',
  `success` TINYINT(1) NOT NULL DEFAULT 1 COMMENT '1=success, 0=failure',
  `error_message` TEXT NULL DEFAULT NULL COMMENT 'Error details if success=0',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'When the action occurred',
  PRIMARY KEY (`id`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_action` (`action`),
  KEY `idx_object_type` (`object_type`),
  KEY `idx_created_at` (`created_at`),
  KEY `idx_success` (`success`),
  KEY `idx_composite_user_date` (`user_id`, `created_at`),
  KEY `idx_composite_type_date` (`object_type`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='System-wide audit log for tracking all important events';

-- ============================================================================
-- Sample Data (for testing - remove in production)
-- ============================================================================
-- INSERT INTO `audit_log` 
-- (`user_id`, `user_name`, `user_role`, `action`, `object_type`, `object_id`, `summary`, `ip_address`, `success`)
-- VALUES
-- (1, 'admin', 'Owner', 'login', 'auth', NULL, 'User admin logged in', '127.0.0.1', 1);

-- ============================================================================
-- Migration Complete
-- ============================================================================
-- Next Steps:
-- 1. Run this migration: mysql -u root -p bestsys < migrations/create_audit_log.sql
-- 2. Verify table created: SHOW TABLES LIKE 'audit_log';
-- 3. Check structure: DESCRIBE audit_log;
-- ============================================================================

