-- ============================================================================
-- Enhance Lease System
-- ============================================================================
-- This migration adds:
-- 1. annual_rent column to re_leases (instead of calculating from monthly_rent)
-- 2. number_of_installments column
-- 3. Additional parking and store fields
-- 4. Post-dated cheques table for lease payments
-- ============================================================================

-- Add annual_rent column (keep monthly_rent for backward compatibility, but calculate from annual)
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() 
    AND TABLE_NAME = 're_leases' 
    AND COLUMN_NAME = 'annual_rent');
SET @sql = IF(@col_exists = 0, 
    'ALTER TABLE `re_leases` ADD COLUMN `annual_rent` DECIMAL(12,2) DEFAULT NULL COMMENT ''Annual rent amount in AED'' AFTER `monthly_rent`', 
    'SELECT "Column annual_rent already exists" AS message');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Add number_of_installments column
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() 
    AND TABLE_NAME = 're_leases' 
    AND COLUMN_NAME = 'number_of_installments');
SET @sql = IF(@col_exists = 0, 
    'ALTER TABLE `re_leases` ADD COLUMN `number_of_installments` INT(3) DEFAULT 12 COMMENT ''Number of payment installments per year'' AFTER `payment_day`', 
    'SELECT "Column number_of_installments already exists" AS message');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Add additional parking fields
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() 
    AND TABLE_NAME = 're_leases' 
    AND COLUMN_NAME = 'has_additional_parking');
SET @sql = IF(@col_exists = 0, 
    'ALTER TABLE `re_leases` ADD COLUMN `has_additional_parking` TINYINT(1) DEFAULT 0 COMMENT ''Tenant has additional parking'' AFTER `monthly_parking_fee`', 
    'SELECT "Column has_additional_parking already exists" AS message');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() 
    AND TABLE_NAME = 're_leases' 
    AND COLUMN_NAME = 'additional_parking_fee');
SET @sql = IF(@col_exists = 0, 
    'ALTER TABLE `re_leases` ADD COLUMN `additional_parking_fee` DECIMAL(12,2) DEFAULT 0.00 COMMENT ''Monthly fee for additional parking'' AFTER `has_additional_parking`', 
    'SELECT "Column additional_parking_fee already exists" AS message');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() 
    AND TABLE_NAME = 're_leases' 
    AND COLUMN_NAME = 'additional_parking_start_date');
SET @sql = IF(@col_exists = 0, 
    'ALTER TABLE `re_leases` ADD COLUMN `additional_parking_start_date` DATE DEFAULT NULL COMMENT ''Start date for additional parking'' AFTER `additional_parking_fee`', 
    'SELECT "Column additional_parking_start_date already exists" AS message');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() 
    AND TABLE_NAME = 're_leases' 
    AND COLUMN_NAME = 'additional_parking_end_date');
SET @sql = IF(@col_exists = 0, 
    'ALTER TABLE `re_leases` ADD COLUMN `additional_parking_end_date` DATE DEFAULT NULL COMMENT ''End date for additional parking'' AFTER `additional_parking_start_date`', 
    'SELECT "Column additional_parking_end_date already exists" AS message');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Add additional store fields
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() 
    AND TABLE_NAME = 're_leases' 
    AND COLUMN_NAME = 'has_additional_store');
SET @sql = IF(@col_exists = 0, 
    'ALTER TABLE `re_leases` ADD COLUMN `has_additional_store` TINYINT(1) DEFAULT 0 COMMENT ''Tenant has additional store'' AFTER `additional_parking_end_date`', 
    'SELECT "Column has_additional_store already exists" AS message');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() 
    AND TABLE_NAME = 're_leases' 
    AND COLUMN_NAME = 'additional_store_fee');
SET @sql = IF(@col_exists = 0, 
    'ALTER TABLE `re_leases` ADD COLUMN `additional_store_fee` DECIMAL(12,2) DEFAULT 0.00 COMMENT ''Monthly fee for additional store'' AFTER `has_additional_store`', 
    'SELECT "Column additional_store_fee already exists" AS message');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() 
    AND TABLE_NAME = 're_leases' 
    AND COLUMN_NAME = 'additional_store_start_date');
SET @sql = IF(@col_exists = 0, 
    'ALTER TABLE `re_leases` ADD COLUMN `additional_store_start_date` DATE DEFAULT NULL COMMENT ''Start date for additional store'' AFTER `additional_store_fee`', 
    'SELECT "Column additional_store_start_date already exists" AS message');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() 
    AND TABLE_NAME = 're_leases' 
    AND COLUMN_NAME = 'additional_store_end_date');
SET @sql = IF(@col_exists = 0, 
    'ALTER TABLE `re_leases` ADD COLUMN `additional_store_end_date` DATE DEFAULT NULL COMMENT ''End date for additional store'' AFTER `additional_store_start_date`', 
    'SELECT "Column additional_store_end_date already exists" AS message');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Create post-dated cheques table for leases
CREATE TABLE IF NOT EXISTS `re_lease_cheques` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `lease_id` INT(11) NOT NULL,
  `installment_id` INT(11) DEFAULT NULL COMMENT 'Link to re_lease_installments if applicable',
  `cheque_number` VARCHAR(100) DEFAULT NULL,
  `cheque_date` DATE DEFAULT NULL,
  `cheque_amount` DECIMAL(12,2) NOT NULL,
  `cheque_holder_name` VARCHAR(200) DEFAULT NULL,
  `payment_method` ENUM('cheque', 'cash', 'bank_transfer', 'card') NOT NULL DEFAULT 'cheque',
  `cheque_photo_path` VARCHAR(500) DEFAULT NULL COMMENT 'Path to uploaded cheque photo',
  `status` ENUM('pending', 'deposited', 'cleared', 'bounced', 'cancelled') NOT NULL DEFAULT 'pending',
  `deposited_date` DATE DEFAULT NULL,
  `cleared_date` DATE DEFAULT NULL,
  `bounced_date` DATE DEFAULT NULL,
  `bounced_reason` TEXT DEFAULT NULL,
  `notes` TEXT DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_lease` (`lease_id`),
  KEY `idx_installment` (`installment_id`),
  KEY `idx_cheque_date` (`cheque_date`),
  KEY `idx_status` (`status`),
  FOREIGN KEY (`lease_id`) REFERENCES `re_leases`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`installment_id`) REFERENCES `re_lease_installments`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

SELECT 'Lease system enhancement completed successfully!' AS message;

