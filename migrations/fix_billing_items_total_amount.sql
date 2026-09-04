-- Fix missing total_amount column in re_billing_items table
-- Run this if you get "Unknown column 'total_amount' in 'field list'" error
-- This migration adds missing columns from the enhanced billing system

-- Check if total_amount column exists, if not add it
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

-- Check if other missing columns exist and add them
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

SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
                   WHERE TABLE_SCHEMA = DATABASE() 
                   AND TABLE_NAME = 're_billing_items' 
                   AND COLUMN_NAME = 'quantity');
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE `re_billing_items` ADD COLUMN `quantity` DECIMAL(10,2) DEFAULT 1.00 AFTER `amount`',
    'SELECT "Column quantity already exists" AS message');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
                   WHERE TABLE_SCHEMA = DATABASE() 
                   AND TABLE_NAME = 're_billing_items' 
                   AND COLUMN_NAME = 'item_name');
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE `re_billing_items` CHANGE COLUMN `description` `item_name` VARCHAR(255) NOT NULL',
    'SELECT "Column item_name already exists" AS message');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
                   WHERE TABLE_SCHEMA = DATABASE() 
                   AND TABLE_NAME = 're_billing_items' 
                   AND COLUMN_NAME = 'item_description');
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE `re_billing_items` ADD COLUMN `item_description` TEXT DEFAULT NULL AFTER `item_name`',
    'SELECT "Column item_description already exists" AS message');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

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

SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
                   WHERE TABLE_SCHEMA = DATABASE() 
                   AND TABLE_NAME = 're_billing_items' 
                   AND COLUMN_NAME = 'service_charge_id');
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE `re_billing_items` ADD COLUMN `service_charge_id` INT(11) DEFAULT NULL AFTER `due_date`',
    'SELECT "Column service_charge_id already exists" AS message');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
                   WHERE TABLE_SCHEMA = DATABASE() 
                   AND TABLE_NAME = 're_billing_items' 
                   AND COLUMN_NAME = 'penalty_rule_id');
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE `re_billing_items` ADD COLUMN `penalty_rule_id` INT(11) DEFAULT NULL AFTER `service_charge_id`',
    'SELECT "Column penalty_rule_id already exists" AS message');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
                   WHERE TABLE_SCHEMA = DATABASE() 
                   AND TABLE_NAME = 're_billing_items' 
                   AND COLUMN_NAME = 'installment_id');
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE `re_billing_items` ADD COLUMN `installment_id` INT(11) DEFAULT NULL COMMENT "If linked to rent installment" AFTER `penalty_rule_id`',
    'SELECT "Column installment_id already exists" AS message');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
                   WHERE TABLE_SCHEMA = DATABASE() 
                   AND TABLE_NAME = 're_billing_items' 
                   AND COLUMN_NAME = 'is_paid');
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE `re_billing_items` ADD COLUMN `is_paid` TINYINT(1) NOT NULL DEFAULT 0 AFTER `installment_id`',
    'SELECT "Column is_paid already exists" AS message');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
                   WHERE TABLE_SCHEMA = DATABASE() 
                   AND TABLE_NAME = 're_billing_items' 
                   AND COLUMN_NAME = 'paid_amount');
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE `re_billing_items` ADD COLUMN `paid_amount` DECIMAL(10,2) DEFAULT 0.00 AFTER `is_paid`',
    'SELECT "Column paid_amount already exists" AS message');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
                   WHERE TABLE_SCHEMA = DATABASE() 
                   AND TABLE_NAME = 're_billing_items' 
                   AND COLUMN_NAME = 'paid_date');
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE `re_billing_items` ADD COLUMN `paid_date` DATE DEFAULT NULL AFTER `paid_amount`',
    'SELECT "Column paid_date already exists" AS message');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Update existing records to calculate total_amount if it's 0
UPDATE re_billing_items 
SET total_amount = amount + COALESCE(tax_amount, 0) 
WHERE total_amount = 0 OR total_amount IS NULL;
