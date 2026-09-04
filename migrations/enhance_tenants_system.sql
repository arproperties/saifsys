-- ============================================================================
-- Enhance Tenants System - Company/Individual & Tenant History
-- ============================================================================
-- This migration adds:
-- 1. Company/Individual distinction to re_tenants
-- 2. Tenant history tracking (previous units, past contracts, payment behavior, issues)
-- ============================================================================

-- Add tenant_type and company_name to re_tenants
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() 
    AND TABLE_NAME = 're_tenants' 
    AND COLUMN_NAME = 'tenant_type');
SET @sql = IF(@col_exists = 0, 
    'ALTER TABLE `re_tenants` ADD COLUMN `tenant_type` ENUM(\'individual\', \'company\') NOT NULL DEFAULT \'individual\' AFTER `company_id`', 
    'SELECT "Column tenant_type already exists" AS message');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() 
    AND TABLE_NAME = 're_tenants' 
    AND COLUMN_NAME = 'company_name');
SET @sql = IF(@col_exists = 0, 
    'ALTER TABLE `re_tenants` ADD COLUMN `company_name` VARCHAR(200) DEFAULT NULL AFTER `tenant_type`', 
    'SELECT "Column company_name already exists" AS message');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Create tenant_unit_history table (previous units tracking)
CREATE TABLE IF NOT EXISTS `re_tenant_unit_history` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `tenant_id` INT(11) NOT NULL,
  `unit_id` INT(11) NOT NULL,
  `lease_id` INT(11) DEFAULT NULL,
  `move_in_date` DATE NOT NULL,
  `move_out_date` DATE DEFAULT NULL,
  `notes` TEXT DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_tenant` (`tenant_id`),
  KEY `idx_unit` (`unit_id`),
  KEY `idx_lease` (`lease_id`),
  FOREIGN KEY (`tenant_id`) REFERENCES `re_tenants`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`unit_id`) REFERENCES `re_units`(`id`) ON DELETE RESTRICT,
  FOREIGN KEY (`lease_id`) REFERENCES `re_leases`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Create tenant_payment_behavior table (payment tracking)
CREATE TABLE IF NOT EXISTS `re_tenant_payment_behavior` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `tenant_id` INT(11) NOT NULL,
  `lease_id` INT(11) DEFAULT NULL,
  `period_start` DATE NOT NULL,
  `period_end` DATE NOT NULL,
  `total_due` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `total_paid` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `on_time_payments` INT(11) NOT NULL DEFAULT 0,
  `late_payments` INT(11) NOT NULL DEFAULT 0,
  `missed_payments` INT(11) NOT NULL DEFAULT 0,
  `average_days_late` DECIMAL(5,2) DEFAULT NULL,
  `notes` TEXT DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_tenant` (`tenant_id`),
  KEY `idx_lease` (`lease_id`),
  FOREIGN KEY (`tenant_id`) REFERENCES `re_tenants`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`lease_id`) REFERENCES `re_leases`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Create tenant_issues_violations table (issues/violations log)
CREATE TABLE IF NOT EXISTS `re_tenant_issues_violations` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `tenant_id` INT(11) NOT NULL,
  `lease_id` INT(11) DEFAULT NULL,
  `unit_id` INT(11) DEFAULT NULL,
  `issue_type` ENUM('violation', 'complaint', 'warning', 'notice', 'other') NOT NULL DEFAULT 'other',
  `severity` ENUM('low', 'medium', 'high', 'critical') NOT NULL DEFAULT 'medium',
  `title` VARCHAR(200) NOT NULL,
  `description` TEXT NOT NULL,
  `reported_date` DATE NOT NULL,
  `resolved_date` DATE DEFAULT NULL,
  `resolution_notes` TEXT DEFAULT NULL,
  `reported_by` INT(11) DEFAULT NULL,
  `resolved_by` INT(11) DEFAULT NULL,
  `status` ENUM('open', 'in_progress', 'resolved', 'closed') NOT NULL DEFAULT 'open',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_tenant` (`tenant_id`),
  KEY `idx_lease` (`lease_id`),
  KEY `idx_unit` (`unit_id`),
  KEY `idx_status` (`status`),
  KEY `idx_issue_type` (`issue_type`),
  KEY `idx_reported_by` (`reported_by`),
  FOREIGN KEY (`tenant_id`) REFERENCES `re_tenants`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`lease_id`) REFERENCES `re_leases`(`id`) ON DELETE SET NULL,
  FOREIGN KEY (`unit_id`) REFERENCES `re_units`(`id`) ON DELETE SET NULL,
  FOREIGN KEY (`reported_by`) REFERENCES `user`(`id`) ON DELETE SET NULL,
  FOREIGN KEY (`resolved_by`) REFERENCES `user`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Backfill tenant_unit_history from existing leases
INSERT INTO re_tenant_unit_history (tenant_id, unit_id, lease_id, move_in_date, move_out_date, notes)
SELECT 
    l.tenant_id,
    l.unit_id,
    l.id as lease_id,
    COALESCE(l.move_in_date, l.start_date) as move_in_date,
    COALESCE(l.move_out_date, l.end_date) as move_out_date,
    CONCAT('Auto-imported from lease ', COALESCE(l.lease_number, CONCAT('L-', l.id))) as notes
FROM re_leases l
WHERE l.status IN ('expired', 'terminated', 'renewed')
AND NOT EXISTS (
    SELECT 1 FROM re_tenant_unit_history tuh 
    WHERE tuh.lease_id = l.id
);

SELECT 'Tenant system enhancement completed successfully!' AS message;

