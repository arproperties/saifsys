-- ============================================================================
-- Rename monthly_rent to annual_rent in re_units table
-- ============================================================================
-- This migration renames the column to match the actual data meaning
-- ============================================================================

-- Check if column exists and rename it
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() 
    AND TABLE_NAME = 're_units' 
    AND COLUMN_NAME = 'monthly_rent');

SET @sql = IF(@col_exists > 0, 
    'ALTER TABLE `re_units` CHANGE COLUMN `monthly_rent` `annual_rent` DECIMAL(12,2) DEFAULT NULL COMMENT ''Annual rent amount in AED''', 
    'SELECT "Column monthly_rent does not exist or already renamed" AS message');

PREPARE stmt FROM @sql; 
EXECUTE stmt; 
DEALLOCATE PREPARE stmt;

SELECT 'Column renamed from monthly_rent to annual_rent successfully!' AS message;

