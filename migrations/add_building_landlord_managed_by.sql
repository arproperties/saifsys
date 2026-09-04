-- ============================================================================
-- Add Landlord Name and Managed By fields to Buildings
-- ============================================================================
-- This migration adds landlord_name and managed_by columns to re_buildings table
-- Database: bestsys
-- Safe to run multiple times (uses IF NOT EXISTS checks)
-- ============================================================================

SET FOREIGN_KEY_CHECKS = 0;
SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";

-- Add landlord_name column
SET @col_exists = (
    SELECT COUNT(*) 
    FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() 
    AND TABLE_NAME = 're_buildings' 
    AND COLUMN_NAME = 'landlord_name'
);

SET @sql = IF(@col_exists = 0,
    'ALTER TABLE `re_buildings` 
     ADD COLUMN `landlord_name` VARCHAR(200) NULL',
    'SELECT "landlord_name column already exists" AS message'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add managed_by column (foreign key to companies table)
SET @col_exists = (
    SELECT COUNT(*) 
    FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() 
    AND TABLE_NAME = 're_buildings' 
    AND COLUMN_NAME = 'managed_by'
);

SET @sql = IF(@col_exists = 0,
    'ALTER TABLE `re_buildings` 
     ADD COLUMN `managed_by` INT(11) NULL,
     ADD KEY `idx_managed_by` (`managed_by`),
     ADD CONSTRAINT `fk_buildings_managed_by` FOREIGN KEY (`managed_by`) REFERENCES `companies`(`id`) ON DELETE SET NULL',
    'SELECT "managed_by column already exists" AS message'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET FOREIGN_KEY_CHECKS = 1;
