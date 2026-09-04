-- ============================================================================
-- Add installment_id to re_invoices table
-- ============================================================================
-- This migration adds installment_id column to link invoices to lease installments
-- Safe to run multiple times (checks if column exists before adding)
-- ============================================================================

-- Add installment_id column
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() 
    AND TABLE_NAME = 're_invoices' 
    AND COLUMN_NAME = 'installment_id');
SET @sql = IF(@col_exists = 0, 
    'ALTER TABLE `re_invoices` ADD COLUMN `installment_id` INT(11) DEFAULT NULL COMMENT ''Link to re_lease_installments if applicable'' AFTER `lease_id`', 
    'SELECT "Column installment_id already exists" AS message');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Add index for installment_id
SET @idx_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS 
    WHERE TABLE_SCHEMA = DATABASE() 
    AND TABLE_NAME = 're_invoices' 
    AND INDEX_NAME = 'idx_installment');
SET @sql = IF(@idx_exists = 0, 
    'ALTER TABLE `re_invoices` ADD KEY `idx_installment` (`installment_id`)', 
    'SELECT "Index idx_installment already exists" AS message');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Add foreign key constraint
SET @fk_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE 
    WHERE TABLE_SCHEMA = DATABASE() 
    AND TABLE_NAME = 're_invoices' 
    AND COLUMN_NAME = 'installment_id'
    AND REFERENCED_TABLE_NAME = 're_lease_installments');
SET @sql = IF(@fk_exists = 0, 
    'ALTER TABLE `re_invoices` ADD CONSTRAINT `fk_invoice_installment` FOREIGN KEY (`installment_id`) REFERENCES `re_lease_installments`(`id`) ON DELETE SET NULL', 
    'SELECT "FK fk_invoice_installment already exists" AS message');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SELECT 'installment_id column added to re_invoices successfully!' AS message;
