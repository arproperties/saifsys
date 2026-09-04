-- ============================================================================
-- MULTI-COMPANY ERP INTEGRATION - Add company_id to HR Tables
-- ============================================================================
-- This migration adds company_id to HR-related tables
-- Database: bestsys
-- Safe to run multiple times
-- ============================================================================

SET FOREIGN_KEY_CHECKS = 0;
SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";

-- ============================================================================
-- PART 1: ADD COMPANY_ID TO ATTENDANCE TABLE
-- ============================================================================

SET @col_exists = (
    SELECT COUNT(*) 
    FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() 
    AND TABLE_NAME = 'attendance' 
    AND COLUMN_NAME = 'company_id'
);

SET @sql = IF(@col_exists = 0,
    'ALTER TABLE `attendance` 
     ADD COLUMN `company_id` INT(11) NOT NULL DEFAULT 1 AFTER `id`,
     ADD KEY `idx_company_id` (`company_id`)',
    'SELECT "company_id column already exists in attendance table" AS message'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add foreign key separately
SET @fk_exists = (
    SELECT COUNT(*) 
    FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE 
    WHERE TABLE_SCHEMA = DATABASE() 
    AND TABLE_NAME = 'attendance' 
    AND COLUMN_NAME = 'company_id'
    AND REFERENCED_TABLE_NAME = 'companies'
);

SET @sql = IF(@col_exists = 0 AND @fk_exists = 0,
    'ALTER TABLE `attendance` 
     ADD CONSTRAINT `fk_attendance_company` 
     FOREIGN KEY (`company_id`) REFERENCES `companies`(`id`) ON DELETE RESTRICT',
    'SELECT "FK already exists or column does not exist" AS message'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Set company_id from employee for existing attendance records
UPDATE `attendance` a
SET `company_id` = COALESCE(
    (SELECT `company_id` FROM `employees` WHERE `id` = a.`employee_id` LIMIT 1),
    1
)
WHERE `company_id` IS NULL OR `company_id` = 0;

-- ============================================================================
-- PART 2: ADD COMPANY_ID TO EMPLOYEE_DOCUMENTS TABLE
-- ============================================================================

SET @col_exists = (
    SELECT COUNT(*) 
    FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() 
    AND TABLE_NAME = 'employee_documents' 
    AND COLUMN_NAME = 'company_id'
);

SET @sql = IF(@col_exists = 0,
    'ALTER TABLE `employee_documents` 
     ADD COLUMN `company_id` INT(11) NOT NULL DEFAULT 1 AFTER `id`,
     ADD FOREIGN KEY (`company_id`) REFERENCES `companies`(`id`) ON DELETE RESTRICT,
     ADD KEY `idx_company_id` (`company_id`)',
    'SELECT "company_id column already exists in employee_documents table" AS message'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Set company_id from employee for existing documents
UPDATE `employee_documents` ed
SET `company_id` = COALESCE(
    (SELECT `company_id` FROM `employees` WHERE `id` = ed.`employee_id` LIMIT 1),
    1
)
WHERE `company_id` IS NULL OR `company_id` = 0;

-- ============================================================================
-- PART 3: ADD COMPANY_ID TO LEAVE_REQUESTS TABLE
-- ============================================================================

SET @col_exists = (
    SELECT COUNT(*) 
    FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() 
    AND TABLE_NAME = 'leave_requests' 
    AND COLUMN_NAME = 'company_id'
);

SET @sql = IF(@col_exists = 0,
    'ALTER TABLE `leave_requests` 
     ADD COLUMN `company_id` INT(11) NOT NULL DEFAULT 1 AFTER `id`,
     ADD FOREIGN KEY (`company_id`) REFERENCES `companies`(`id`) ON DELETE RESTRICT,
     ADD KEY `idx_company_id` (`company_id`)',
    'SELECT "company_id column already exists in leave_requests table" AS message'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Set company_id from employee for existing leave requests
UPDATE `leave_requests` lr
SET `company_id` = COALESCE(
    (SELECT `company_id` FROM `employees` WHERE `id` = lr.`employee_id` LIMIT 1),
    1
)
WHERE `company_id` IS NULL OR `company_id` = 0;

-- ============================================================================
-- PART 4: ADD COMPANY_ID TO PAYROLL_RUNS TABLE
-- ============================================================================

SET @col_exists = (
    SELECT COUNT(*) 
    FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() 
    AND TABLE_NAME = 'payroll_runs' 
    AND COLUMN_NAME = 'company_id'
);

SET @sql = IF(@col_exists = 0,
    'ALTER TABLE `payroll_runs` 
     ADD COLUMN `company_id` INT(11) NOT NULL DEFAULT 1 AFTER `id`,
     ADD FOREIGN KEY (`company_id`) REFERENCES `companies`(`id`) ON DELETE RESTRICT,
     ADD KEY `idx_company_id` (`company_id`)',
    'SELECT "company_id column already exists in payroll_runs table" AS message'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Set all existing payroll runs to company_id=1
UPDATE `payroll_runs` SET `company_id` = 1 WHERE `company_id` IS NULL OR `company_id` = 0;

-- ============================================================================
-- PART 5: ADD COMPANY_ID TO OVERTIME_ENTRIES TABLE (if exists)
-- ============================================================================

SET @table_exists = (
    SELECT COUNT(*) 
    FROM INFORMATION_SCHEMA.TABLES 
    WHERE TABLE_SCHEMA = DATABASE() 
    AND TABLE_NAME = 'overtime_entries'
);

SET @col_exists = (
    SELECT COUNT(*) 
    FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() 
    AND TABLE_NAME = 'overtime_entries' 
    AND COLUMN_NAME = 'company_id'
);

SET @sql = IF(@table_exists > 0 AND @col_exists = 0,
    'ALTER TABLE `overtime_entries` 
     ADD COLUMN `company_id` INT(11) NOT NULL DEFAULT 1 AFTER `id`,
     ADD FOREIGN KEY (`company_id`) REFERENCES `companies`(`id`) ON DELETE RESTRICT,
     ADD KEY `idx_company_id` (`company_id`)',
    'SELECT "overtime_entries table does not exist or company_id already exists" AS message'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Set company_id from employee for existing overtime entries
UPDATE `overtime_entries` oe
SET `company_id` = COALESCE(
    (SELECT `company_id` FROM `employees` WHERE `id` = oe.`employee_id` LIMIT 1),
    1
)
WHERE EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_NAME = 'overtime_entries')
AND (`company_id` IS NULL OR `company_id` = 0);

SET FOREIGN_KEY_CHECKS = 1;

-- ============================================================================
-- MIGRATION COMPLETE - HR Tables
-- ============================================================================

