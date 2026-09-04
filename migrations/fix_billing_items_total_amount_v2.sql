-- Fix missing total_amount column in re_billing_items table (Version 2)
-- This adds missing columns needed by the billing system

-- Add unit_price column (if not exists)
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
                   WHERE TABLE_SCHEMA = DATABASE() 
                   AND TABLE_NAME = 're_billing_items' 
                   AND COLUMN_NAME = 'unit_price');
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE `re_billing_items` ADD COLUMN `unit_price` DECIMAL(10,2) DEFAULT NULL AFTER `quantity`',
    'SELECT "Column unit_price already exists" AS message');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add tax_rate column (if not exists)
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
                   WHERE TABLE_SCHEMA = DATABASE() 
                   AND TABLE_NAME = 're_billing_items' 
                   AND COLUMN_NAME = 'tax_rate');
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE `re_billing_items` ADD COLUMN `tax_rate` DECIMAL(5,2) DEFAULT 0.00 AFTER `unit_price`',
    'SELECT "Column tax_rate already exists" AS message');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add tax_amount column (if not exists)
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
                   WHERE TABLE_SCHEMA = DATABASE() 
                   AND TABLE_NAME = 're_billing_items' 
                   AND COLUMN_NAME = 'tax_amount');
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE `re_billing_items` ADD COLUMN `tax_amount` DECIMAL(10,2) DEFAULT 0.00 AFTER `tax_rate`',
    'SELECT "Column tax_amount already exists" AS message');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add total_amount column (if not exists)
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
                   WHERE TABLE_SCHEMA = DATABASE() 
                   AND TABLE_NAME = 're_billing_items' 
                   AND COLUMN_NAME = 'total_amount');
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE `re_billing_items` ADD COLUMN `total_amount` DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER `tax_amount`',
    'SELECT "Column total_amount already exists" AS message');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add billing_period_start column (if not exists)
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
                   WHERE TABLE_SCHEMA = DATABASE() 
                   AND TABLE_NAME = 're_billing_items' 
                   AND COLUMN_NAME = 'billing_period_start');
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE `re_billing_items` ADD COLUMN `billing_period_start` DATE DEFAULT NULL AFTER `total_amount`',
    'SELECT "Column billing_period_start already exists" AS message');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add billing_period_end column (if not exists)
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
                   WHERE TABLE_SCHEMA = DATABASE() 
                   AND TABLE_NAME = 're_billing_items' 
                   AND COLUMN_NAME = 'billing_period_end');
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE `re_billing_items` ADD COLUMN `billing_period_end` DATE DEFAULT NULL AFTER `billing_period_start`',
    'SELECT "Column billing_period_end already exists" AS message');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Update existing records to calculate total_amount if it's 0 or NULL
UPDATE re_billing_items 
SET total_amount = amount + COALESCE(tax_amount, 0) 
WHERE total_amount = 0 OR total_amount IS NULL;

