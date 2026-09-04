-- ============================================================================
-- MULTI-COMPANY ERP INTEGRATION - Add company_id to Core Tables
-- ============================================================================
-- This migration adds company_id to core business tables
-- Database: bestsys
-- Safe to run multiple times
-- ============================================================================

SET FOREIGN_KEY_CHECKS = 0;
SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";

-- ============================================================================
-- PART 1: ADD COMPANY_ID TO USER TABLE
-- ============================================================================

SET @col_exists = (
    SELECT COUNT(*) 
    FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() 
    AND TABLE_NAME = 'user' 
    AND COLUMN_NAME = 'company_id'
);

-- Check if default_company_id exists to determine position
SET @default_company_exists = (
    SELECT COUNT(*) 
    FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() 
    AND TABLE_NAME = 'user' 
    AND COLUMN_NAME = 'default_company_id'
);

SET @sql = IF(@col_exists = 0,
    IF(@default_company_exists > 0,
        'ALTER TABLE `user` 
         ADD COLUMN `company_id` INT(11) NOT NULL DEFAULT 1 AFTER `default_company_id`,
         ADD KEY `idx_company_id` (`company_id`)',
        'ALTER TABLE `user` 
         ADD COLUMN `company_id` INT(11) NOT NULL DEFAULT 1 AFTER `employee_id`,
         ADD KEY `idx_company_id` (`company_id`)'
    ),
    'SELECT "company_id column already exists in user table" AS message'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add foreign key separately (after column exists)
SET @fk_exists = (
    SELECT COUNT(*) 
    FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE 
    WHERE TABLE_SCHEMA = DATABASE() 
    AND TABLE_NAME = 'user' 
    AND COLUMN_NAME = 'company_id'
    AND REFERENCED_TABLE_NAME = 'companies'
);

SET @sql = IF(@col_exists = 0 AND @fk_exists = 0,
    'ALTER TABLE `user` 
     ADD CONSTRAINT `fk_user_company` 
     FOREIGN KEY (`company_id`) REFERENCES `companies`(`id`) ON DELETE RESTRICT',
    'SELECT "FK already exists or column does not exist" AS message'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Set company_id from user_companies for existing users
UPDATE `user` u
SET `company_id` = COALESCE(
    (SELECT `company_id` FROM `user_companies` WHERE `user_id` = u.`id` AND `is_primary` = 1 LIMIT 1),
    1
)
WHERE `company_id` IS NULL OR `company_id` = 0;

-- ============================================================================
-- PART 2: ADD COMPANY_ID TO EMPLOYEES TABLE
-- ============================================================================

SET @col_exists = (
    SELECT COUNT(*) 
    FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() 
    AND TABLE_NAME = 'employees' 
    AND COLUMN_NAME = 'company_id'
);

SET @sql = IF(@col_exists = 0,
    'ALTER TABLE `employees` 
     ADD COLUMN `company_id` INT(11) NOT NULL DEFAULT 1 AFTER `id`,
     ADD FOREIGN KEY (`company_id`) REFERENCES `companies`(`id`) ON DELETE RESTRICT,
     ADD KEY `idx_company_id` (`company_id`)',
    'SELECT "company_id column already exists in employees table" AS message'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Set company_id from user's company for existing employees
UPDATE `employees` e
SET `company_id` = COALESCE(
    (SELECT `company_id` FROM `user` WHERE `id` = e.`user_id` LIMIT 1),
    1
)
WHERE `company_id` IS NULL OR `company_id` = 0;

-- ============================================================================
-- PART 3: ADD COMPANY_ID TO CLIENT TABLE
-- ============================================================================

SET @col_exists = (
    SELECT COUNT(*) 
    FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() 
    AND TABLE_NAME = 'client' 
    AND COLUMN_NAME = 'company_id'
);

SET @sql = IF(@col_exists = 0,
    'ALTER TABLE `client` 
     ADD COLUMN `company_id` INT(11) NOT NULL DEFAULT 1 AFTER `id`,
     ADD FOREIGN KEY (`company_id`) REFERENCES `companies`(`id`) ON DELETE RESTRICT,
     ADD KEY `idx_company_id` (`company_id`)',
    'SELECT "company_id column already exists in client table" AS message'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Set all existing clients to company_id=1
UPDATE `client` SET `company_id` = 1 WHERE `company_id` IS NULL OR `company_id` = 0;

-- ============================================================================
-- PART 4: ADD COMPANY_ID TO MAKE_ORDER TABLE
-- ============================================================================

SET @col_exists = (
    SELECT COUNT(*) 
    FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() 
    AND TABLE_NAME = 'make_order' 
    AND COLUMN_NAME = 'company_id'
);

SET @sql = IF(@col_exists = 0,
    'ALTER TABLE `make_order` 
     ADD COLUMN `company_id` INT(11) NOT NULL DEFAULT 1 AFTER `id`,
     ADD KEY `idx_company_id` (`company_id`)',
    'SELECT "company_id column already exists in make_order table" AS message'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add foreign key separately
SET @fk_exists = (
    SELECT COUNT(*) 
    FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE 
    WHERE TABLE_SCHEMA = DATABASE() 
    AND TABLE_NAME = 'make_order' 
    AND COLUMN_NAME = 'company_id'
    AND REFERENCED_TABLE_NAME = 'companies'
);

SET @sql = IF(@col_exists = 0 AND @fk_exists = 0,
    'ALTER TABLE `make_order` 
     ADD CONSTRAINT `fk_make_order_company` 
     FOREIGN KEY (`company_id`) REFERENCES `companies`(`id`) ON DELETE RESTRICT',
    'SELECT "FK already exists or column does not exist" AS message'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Set company_id from client for existing orders
UPDATE `make_order` mo
SET `company_id` = COALESCE(
    (SELECT `company_id` FROM `client` WHERE `id` = mo.`client_id` LIMIT 1),
    1
)
WHERE `company_id` IS NULL OR `company_id` = 0;

-- ============================================================================
-- PART 5: ADD COMPANY_ID TO SERVICES TABLE
-- ============================================================================

SET @col_exists = (
    SELECT COUNT(*) 
    FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() 
    AND TABLE_NAME = 'services' 
    AND COLUMN_NAME = 'company_id'
);

SET @sql = IF(@col_exists = 0,
    'ALTER TABLE `services` 
     ADD COLUMN `company_id` INT(11) NOT NULL DEFAULT 1 AFTER `id`,
     ADD FOREIGN KEY (`company_id`) REFERENCES `companies`(`id`) ON DELETE RESTRICT,
     ADD KEY `idx_company_id` (`company_id`)',
    'SELECT "company_id column already exists in services table" AS message'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Set all existing services to company_id=1
UPDATE `services` SET `company_id` = 1 WHERE `company_id` IS NULL OR `company_id` = 0;

-- ============================================================================
-- PART 6: ADD COMPANY_ID TO SERVICE_CATEGORIES TABLE
-- ============================================================================

SET @col_exists = (
    SELECT COUNT(*) 
    FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() 
    AND TABLE_NAME = 'service_categories' 
    AND COLUMN_NAME = 'company_id'
);

SET @sql = IF(@col_exists = 0,
    'ALTER TABLE `service_categories` 
     ADD COLUMN `company_id` INT(11) NOT NULL DEFAULT 1 AFTER `id`,
     ADD FOREIGN KEY (`company_id`) REFERENCES `companies`(`id`) ON DELETE RESTRICT,
     ADD KEY `idx_company_id` (`company_id`)',
    'SELECT "company_id column already exists in service_categories table" AS message'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Set all existing service categories to company_id=1
UPDATE `service_categories` SET `company_id` = 1 WHERE `company_id` IS NULL OR `company_id` = 0;

SET FOREIGN_KEY_CHECKS = 1;

-- ============================================================================
-- MIGRATION COMPLETE - Core Tables
-- ============================================================================

