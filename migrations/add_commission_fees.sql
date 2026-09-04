-- ============================================================================
-- Add Commission Fees to Lease
-- ============================================================================
-- This migration adds commission_fees column to re_leases table
-- ============================================================================

-- Add commission_fees column
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() 
    AND TABLE_NAME = 're_leases' 
    AND COLUMN_NAME = 'commission_fees');
SET @sql = IF(@col_exists = 0, 
    'ALTER TABLE `re_leases` ADD COLUMN `commission_fees` DECIMAL(12,2) DEFAULT 0.00 COMMENT ''Commission fees amount in AED'' AFTER `admin_fees`', 
    'SELECT "Column commission_fees already exists" AS message');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SELECT 'Commission fees column added successfully!' AS message;
