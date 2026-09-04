-- ============================================================================
-- MULTI-COMPANY ERP INTEGRATION - Phase 1: Core Infrastructure
-- ============================================================================
-- This migration adds multi-company support to the system
-- Database: bestsys
-- Safe to run multiple times (uses IF NOT EXISTS and checks)
-- ============================================================================

SET FOREIGN_KEY_CHECKS = 0;
SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";

-- ============================================================================
-- PART 1: CREATE COMPANIES MASTER TABLE
-- ============================================================================

CREATE TABLE IF NOT EXISTS `companies` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(200) NOT NULL,
  `code` VARCHAR(50) DEFAULT NULL,
  `business_type` ENUM('cleaning', 'realestate', 'supermarket', 'restaurant') NOT NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_company_code` (`code`),
  KEY `idx_business_type` (`business_type`),
  KEY `idx_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Insert default company for existing cleaning business
INSERT IGNORE INTO `companies` (`id`, `name`, `code`, `business_type`, `is_active`) 
VALUES (1, 'Best Maids Buildings Cleaning', 'BMC', 'cleaning', 1);

-- ============================================================================
-- PART 2: EXTEND COMPANY_SETTINGS TABLE
-- ============================================================================

-- Check if company_id column already exists
SET @col_exists = (
    SELECT COUNT(*) 
    FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() 
    AND TABLE_NAME = 'company_settings' 
    AND COLUMN_NAME = 'company_id'
);

-- Add company_id column if it doesn't exist
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE `company_settings` 
     ADD COLUMN `company_id` INT(11) NOT NULL DEFAULT 1 AFTER `id`,
     ADD FOREIGN KEY (`company_id`) REFERENCES `companies`(`id`) ON DELETE CASCADE',
    'SELECT "company_id column already exists" AS message'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Update existing company_settings to link to company_id=1
UPDATE `company_settings` SET `company_id` = 1 WHERE `company_id` IS NULL OR `company_id` = 0;

-- Modify primary key if needed (only if not already modified)
SET @pk_check = (
    SELECT COUNT(*) 
    FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS 
    WHERE TABLE_SCHEMA = DATABASE() 
    AND TABLE_NAME = 'company_settings' 
    AND CONSTRAINT_TYPE = 'PRIMARY KEY'
    AND CONSTRAINT_NAME = 'PRIMARY'
);

-- Note: We'll keep the existing primary key structure for now to avoid breaking changes
-- The unique constraint on company_id will ensure one settings record per company

-- Add unique constraint on company_id if it doesn't exist
SET @constraint_exists = (
    SELECT COUNT(*) 
    FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS 
    WHERE TABLE_SCHEMA = DATABASE() 
    AND TABLE_NAME = 'company_settings' 
    AND CONSTRAINT_NAME = 'uq_company_settings'
);

SET @sql = IF(@constraint_exists = 0,
    'ALTER TABLE `company_settings` ADD UNIQUE KEY `uq_company_settings` (`company_id`)',
    'SELECT "uq_company_settings constraint already exists" AS message'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ============================================================================
-- PART 3: CREATE USER_COMPANIES JUNCTION TABLE
-- ============================================================================

CREATE TABLE IF NOT EXISTS `user_companies` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `user_id` INT(11) NOT NULL,
  `company_id` INT(11) NOT NULL,
  `is_primary` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_user_company` (`user_id`, `company_id`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_company_id` (`company_id`),
  FOREIGN KEY (`user_id`) REFERENCES `user`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`company_id`) REFERENCES `companies`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Migrate existing users to company_id=1
INSERT IGNORE INTO `user_companies` (`user_id`, `company_id`, `is_primary`)
SELECT `id`, 1, 1 FROM `user` WHERE `id` NOT IN (SELECT `user_id` FROM `user_companies`);

-- Set first user-company relationship as primary if none exists
UPDATE `user_companies` uc1
SET `is_primary` = 1
WHERE NOT EXISTS (
    SELECT 1 FROM `user_companies` uc2 
    WHERE uc2.user_id = uc1.user_id AND uc2.is_primary = 1 AND uc2.id != uc1.id
)
AND uc1.id = (SELECT MIN(id) FROM `user_companies` uc3 WHERE uc3.user_id = uc1.user_id);

-- ============================================================================
-- PART 4: ADD DEFAULT_COMPANY_ID TO USER TABLE
-- ============================================================================

SET @col_exists = (
    SELECT COUNT(*) 
    FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() 
    AND TABLE_NAME = 'user' 
    AND COLUMN_NAME = 'default_company_id'
);

SET @sql = IF(@col_exists = 0,
    'ALTER TABLE `user` 
     ADD COLUMN `default_company_id` INT(11) NULL AFTER `employee_id`,
     ADD FOREIGN KEY (`default_company_id`) REFERENCES `companies`(`id`) ON DELETE SET NULL,
     ADD KEY `idx_default_company` (`default_company_id`)',
    'SELECT "default_company_id column already exists" AS message'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Set default company for users who have a primary company
UPDATE `user` u
SET `default_company_id` = (
    SELECT `company_id` FROM `user_companies` 
    WHERE `user_id` = u.`id` AND `is_primary` = 1 
    LIMIT 1
)
WHERE `default_company_id` IS NULL;

-- ============================================================================
-- PART 5: EXTEND ROLES TABLE FOR MODULES
-- ============================================================================

SET @col_exists = (
    SELECT COUNT(*) 
    FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() 
    AND TABLE_NAME = 'roles' 
    AND COLUMN_NAME = 'module'
);

SET @sql = IF(@col_exists = 0,
    'ALTER TABLE `roles` 
     ADD COLUMN `module` VARCHAR(50) NULL COMMENT "cleaning, realestate, hr, finance, core",
     ADD COLUMN `is_system` TINYINT(1) NOT NULL DEFAULT 0 COMMENT "System roles cannot be deleted",
     ADD KEY `idx_module` (`module`)',
    'SELECT "module column already exists" AS message'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Migrate existing roles to 'cleaning' module
UPDATE `roles` SET `module` = 'cleaning' WHERE `module` IS NULL;
UPDATE `roles` SET `is_system` = 1 WHERE `name` IN ('Owner', 'Admin');

-- ============================================================================
-- PART 6: CREATE ROLE_MODULES JUNCTION TABLE
-- ============================================================================

CREATE TABLE IF NOT EXISTS `role_modules` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `role_id` INT(11) NOT NULL,
  `module` VARCHAR(50) NOT NULL,
  `permissions` JSON DEFAULT NULL COMMENT 'Module-specific permissions',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_role_module` (`role_id`, `module`),
  KEY `idx_role_id` (`role_id`),
  KEY `idx_module` (`module`),
  FOREIGN KEY (`role_id`) REFERENCES `roles`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Migrate existing role-module relationships
INSERT IGNORE INTO `role_modules` (`role_id`, `module`)
SELECT `id`, `module` FROM `roles` WHERE `module` IS NOT NULL;

SET FOREIGN_KEY_CHECKS = 1;

-- ============================================================================
-- MIGRATION COMPLETE - Phase 1
-- ============================================================================
-- Next steps:
-- 1. Run add_company_id_to_core_tables.sql
-- 2. Run add_company_id_to_hr_tables.sql
-- 3. Run add_company_id_to_finance_tables.sql
-- ============================================================================

