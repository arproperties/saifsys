-- ============================================================================
-- ADD DEWA PREMISES AND PLOT NUMBER TO BUILDINGS TABLE
-- ============================================================================
-- This migration adds DEWA Premises and Plot Number fields to re_buildings
-- Database: bestsys
-- Safe to run multiple times (checks for column existence)
-- ============================================================================

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";

-- Check and add dewa_premises column
SET @col_exists = (
    SELECT COUNT(*) 
    FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 're_buildings' 
    AND COLUMN_NAME = 'dewa_premises'
);

SET @sql = IF(@col_exists = 0,
    'ALTER TABLE `re_buildings` ADD COLUMN `dewa_premises` VARCHAR(100) DEFAULT NULL AFTER `address`',
    'SELECT "Column dewa_premises already exists" as message'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Check and add plot_number column
SET @col_exists = (
    SELECT COUNT(*) 
    FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 're_buildings' 
    AND COLUMN_NAME = 'plot_number'
);

SET @sql = IF(@col_exists = 0,
    'ALTER TABLE `re_buildings` ADD COLUMN `plot_number` VARCHAR(100) DEFAULT NULL AFTER `dewa_premises`',
    'SELECT "Column plot_number already exists" as message'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ============================================================================
-- END OF MIGRATION
-- ============================================================================
