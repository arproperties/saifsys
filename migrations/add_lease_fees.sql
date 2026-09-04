-- ============================================================================
-- Add Lease Fees (Chiller, Ejari, Admin) and Add to First Installment Option
-- ============================================================================
-- This migration adds:
-- 1. chiller_fees column to re_leases
-- 2. ejari_fees column to re_leases
-- 3. admin_fees column to re_leases
-- 4. add_fees_to_first_installment column (checkbox option)
-- ============================================================================

-- Add chiller_fees column
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() 
    AND TABLE_NAME = 're_leases' 
    AND COLUMN_NAME = 'chiller_fees');
SET @sql = IF(@col_exists = 0, 
    'ALTER TABLE `re_leases` ADD COLUMN `chiller_fees` DECIMAL(12,2) DEFAULT 0.00 COMMENT ''Chiller fees amount in AED'' AFTER `security_deposit`', 
    'SELECT "Column chiller_fees already exists" AS message');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Add ejari_fees column
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() 
    AND TABLE_NAME = 're_leases' 
    AND COLUMN_NAME = 'ejari_fees');
SET @sql = IF(@col_exists = 0, 
    'ALTER TABLE `re_leases` ADD COLUMN `ejari_fees` DECIMAL(12,2) DEFAULT 0.00 COMMENT ''Ejari fees amount in AED'' AFTER `chiller_fees`', 
    'SELECT "Column ejari_fees already exists" AS message');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Add admin_fees column
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() 
    AND TABLE_NAME = 're_leases' 
    AND COLUMN_NAME = 'admin_fees');
SET @sql = IF(@col_exists = 0, 
    'ALTER TABLE `re_leases` ADD COLUMN `admin_fees` DECIMAL(12,2) DEFAULT 0.00 COMMENT ''Admin fees amount in AED'' AFTER `ejari_fees`', 
    'SELECT "Column admin_fees already exists" AS message');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Add add_fees_to_first_installment column (checkbox option)
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() 
    AND TABLE_NAME = 're_leases' 
    AND COLUMN_NAME = 'add_fees_to_first_installment');
SET @sql = IF(@col_exists = 0, 
    'ALTER TABLE `re_leases` ADD COLUMN `add_fees_to_first_installment` TINYINT(1) DEFAULT 1 COMMENT ''If 1, add all fees to 1st installment; if 0, create separate installment'' AFTER `admin_fees`', 
    'SELECT "Column add_fees_to_first_installment already exists" AS message');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SELECT 'Lease fees columns added successfully!' AS message;
