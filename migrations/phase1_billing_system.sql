-- Phase 1 MVP Enhancement: Billing System
-- Comprehensive billing system with service charges, penalties, invoices, and cheque tracking

-- ============================================================================
-- 1. Service Charge Types (Configuration)
-- ============================================================================
CREATE TABLE IF NOT EXISTS `re_service_charge_types` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `company_id` INT(11) NOT NULL,
  `charge_name` VARCHAR(255) NOT NULL,
  `charge_description` TEXT DEFAULT NULL,
  `charge_type` ENUM('fixed', 'per_unit', 'percentage', 'per_sqm') NOT NULL DEFAULT 'fixed',
  `default_amount` DECIMAL(10,2) DEFAULT 0.00,
  `is_recurring` TINYINT(1) NOT NULL DEFAULT 1,
  `recurrence_type` ENUM('monthly', 'quarterly', 'annually', 'one_time') NOT NULL DEFAULT 'monthly',
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_company` (`company_id`),
  KEY `idx_active` (`is_active`),
  FOREIGN KEY (`company_id`) REFERENCES `companies`(`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ============================================================================
-- 2. Service Charges (Applied to Leases/Units)
-- ============================================================================
CREATE TABLE IF NOT EXISTS `re_service_charges` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `company_id` INT(11) NOT NULL,
  `lease_id` INT(11) NOT NULL,
  `charge_type_id` INT(11) DEFAULT NULL,
  `charge_name` VARCHAR(255) NOT NULL,
  `charge_description` TEXT DEFAULT NULL,
  `amount` DECIMAL(10,2) NOT NULL,
  `charge_type` ENUM('fixed', 'per_unit', 'percentage', 'per_sqm') NOT NULL DEFAULT 'fixed',
  `is_recurring` TINYINT(1) NOT NULL DEFAULT 1,
  `recurrence_type` ENUM('monthly', 'quarterly', 'annually', 'one_time') NOT NULL DEFAULT 'monthly',
  `start_date` DATE NOT NULL,
  `end_date` DATE DEFAULT NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_company` (`company_id`),
  KEY `idx_lease` (`lease_id`),
  KEY `idx_charge_type` (`charge_type_id`),
  KEY `idx_active` (`is_active`),
  KEY `idx_dates` (`start_date`, `end_date`),
  FOREIGN KEY (`company_id`) REFERENCES `companies`(`id`) ON DELETE RESTRICT,
  FOREIGN KEY (`lease_id`) REFERENCES `re_leases`(`id`) ON DELETE RESTRICT,
  FOREIGN KEY (`charge_type_id`) REFERENCES `re_service_charge_types`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ============================================================================
-- 3. Penalty Rules (Configuration)
-- ============================================================================
CREATE TABLE IF NOT EXISTS `re_penalty_rules` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `company_id` INT(11) NOT NULL,
  `rule_name` VARCHAR(255) NOT NULL,
  `rule_description` TEXT DEFAULT NULL,
  `penalty_type` ENUM('late_payment', 'bounced_cheque', 'violation', 'other') NOT NULL DEFAULT 'late_payment',
  `calculation_method` ENUM('fixed', 'percentage', 'per_day') NOT NULL DEFAULT 'fixed',
  `amount` DECIMAL(10,2) DEFAULT 0.00,
  `percentage` DECIMAL(5,2) DEFAULT 0.00 COMMENT 'Percentage of amount',
  `per_day_amount` DECIMAL(10,2) DEFAULT 0.00 COMMENT 'Amount per day for late payment',
  `grace_period_days` INT(11) DEFAULT 0 COMMENT 'Days before penalty applies',
  `max_penalty_amount` DECIMAL(10,2) DEFAULT NULL COMMENT 'Maximum penalty cap',
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_company` (`company_id`),
  KEY `idx_penalty_type` (`penalty_type`),
  KEY `idx_active` (`is_active`),
  FOREIGN KEY (`company_id`) REFERENCES `companies`(`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ============================================================================
-- 4. Billing Items (Service Charges + Penalties + Other Charges)
-- ============================================================================
CREATE TABLE IF NOT EXISTS `re_billing_items` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `company_id` INT(11) NOT NULL,
  `lease_id` INT(11) NOT NULL,
  `item_type` ENUM('rent', 'service_charge', 'penalty', 'other') NOT NULL DEFAULT 'service_charge',
  `item_name` VARCHAR(255) NOT NULL,
  `item_description` TEXT DEFAULT NULL,
  `amount` DECIMAL(10,2) NOT NULL,
  `quantity` DECIMAL(10,2) DEFAULT 1.00,
  `unit_price` DECIMAL(10,2) DEFAULT NULL,
  `tax_rate` DECIMAL(5,2) DEFAULT 0.00,
  `tax_amount` DECIMAL(10,2) DEFAULT 0.00,
  `total_amount` DECIMAL(10,2) NOT NULL,
  `billing_period_start` DATE DEFAULT NULL,
  `billing_period_end` DATE DEFAULT NULL,
  `due_date` DATE NOT NULL,
  `service_charge_id` INT(11) DEFAULT NULL,
  `penalty_rule_id` INT(11) DEFAULT NULL,
  `installment_id` INT(11) DEFAULT NULL COMMENT 'If linked to rent installment',
  `is_paid` TINYINT(1) NOT NULL DEFAULT 0,
  `paid_amount` DECIMAL(10,2) DEFAULT 0.00,
  `paid_date` DATE DEFAULT NULL,
  `payment_id` INT(11) DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_company` (`company_id`),
  KEY `idx_lease` (`lease_id`),
  KEY `idx_item_type` (`item_type`),
  KEY `idx_due_date` (`due_date`),
  KEY `idx_is_paid` (`is_paid`),
  KEY `idx_service_charge` (`service_charge_id`),
  KEY `idx_penalty_rule` (`penalty_rule_id`),
  KEY `idx_installment` (`installment_id`),
  FOREIGN KEY (`company_id`) REFERENCES `companies`(`id`) ON DELETE RESTRICT,
  FOREIGN KEY (`lease_id`) REFERENCES `re_leases`(`id`) ON DELETE RESTRICT,
  FOREIGN KEY (`service_charge_id`) REFERENCES `re_service_charges`(`id`) ON DELETE SET NULL,
  FOREIGN KEY (`penalty_rule_id`) REFERENCES `re_penalty_rules`(`id`) ON DELETE SET NULL,
  FOREIGN KEY (`installment_id`) REFERENCES `re_lease_installments`(`id`) ON DELETE SET NULL,
  FOREIGN KEY (`payment_id`) REFERENCES `re_payments`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ============================================================================
-- 5. Invoices
-- ============================================================================
CREATE TABLE IF NOT EXISTS `re_invoices` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `company_id` INT(11) NOT NULL,
  `lease_id` INT(11) NOT NULL,
  `invoice_number` VARCHAR(100) NOT NULL,
  `invoice_date` DATE NOT NULL,
  `due_date` DATE NOT NULL,
  `subtotal` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `tax_rate` DECIMAL(5,2) DEFAULT 0.00,
  `tax_amount` DECIMAL(10,2) DEFAULT 0.00,
  `discount_amount` DECIMAL(10,2) DEFAULT 0.00,
  `total_amount` DECIMAL(10,2) NOT NULL,
  `paid_amount` DECIMAL(10,2) DEFAULT 0.00,
  `outstanding_amount` DECIMAL(10,2) NOT NULL,
  `status` ENUM('draft', 'sent', 'paid', 'partial', 'overdue', 'cancelled') NOT NULL DEFAULT 'draft',
  `notes` TEXT DEFAULT NULL,
  `created_by` INT(11) DEFAULT NULL COMMENT 'user_id',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_invoice_number` (`company_id`, `invoice_number`),
  KEY `idx_company` (`company_id`),
  KEY `idx_lease` (`lease_id`),
  KEY `idx_invoice_date` (`invoice_date`),
  KEY `idx_due_date` (`due_date`),
  KEY `idx_status` (`status`),
  FOREIGN KEY (`company_id`) REFERENCES `companies`(`id`) ON DELETE RESTRICT,
  FOREIGN KEY (`lease_id`) REFERENCES `re_leases`(`id`) ON DELETE RESTRICT,
  FOREIGN KEY (`created_by`) REFERENCES `user`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ============================================================================
-- 6. Invoice Items
-- ============================================================================
CREATE TABLE IF NOT EXISTS `re_invoice_items` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `company_id` INT(11) NOT NULL,
  `invoice_id` INT(11) NOT NULL,
  `billing_item_id` INT(11) DEFAULT NULL,
  `item_name` VARCHAR(255) NOT NULL,
  `item_description` TEXT DEFAULT NULL,
  `quantity` DECIMAL(10,2) DEFAULT 1.00,
  `unit_price` DECIMAL(10,2) NOT NULL,
  `tax_rate` DECIMAL(5,2) DEFAULT 0.00,
  `tax_amount` DECIMAL(10,2) DEFAULT 0.00,
  `line_total` DECIMAL(10,2) NOT NULL,
  `display_order` INT(11) NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_company` (`company_id`),
  KEY `idx_invoice` (`invoice_id`),
  KEY `idx_billing_item` (`billing_item_id`),
  FOREIGN KEY (`company_id`) REFERENCES `companies`(`id`) ON DELETE RESTRICT,
  FOREIGN KEY (`invoice_id`) REFERENCES `re_invoices`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`billing_item_id`) REFERENCES `re_billing_items`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ============================================================================
-- 7. Post-Dated Cheques
-- ============================================================================
CREATE TABLE IF NOT EXISTS `re_post_dated_cheques` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `company_id` INT(11) NOT NULL,
  `lease_id` INT(11) NOT NULL,
  `cheque_number` VARCHAR(100) NOT NULL,
  `bank_name` VARCHAR(255) DEFAULT NULL,
  `account_holder_name` VARCHAR(255) DEFAULT NULL,
  `cheque_amount` DECIMAL(10,2) NOT NULL,
  `cheque_date` DATE NOT NULL COMMENT 'Date on cheque',
  `received_date` DATE DEFAULT NULL COMMENT 'Date cheque was received',
  `status` ENUM('pending', 'deposited', 'cleared', 'bounced', 'cancelled') NOT NULL DEFAULT 'pending',
  `deposited_date` DATE DEFAULT NULL,
  `cleared_date` DATE DEFAULT NULL,
  `bounced_date` DATE DEFAULT NULL,
  `bounced_reason` TEXT DEFAULT NULL,
  `billing_item_id` INT(11) DEFAULT NULL COMMENT 'Linked billing item',
  `installment_id` INT(11) DEFAULT NULL COMMENT 'Linked installment',
  `payment_id` INT(11) DEFAULT NULL COMMENT 'Payment record if cleared',
  `notes` TEXT DEFAULT NULL,
  `created_by` INT(11) DEFAULT NULL COMMENT 'user_id',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_company` (`company_id`),
  KEY `idx_lease` (`lease_id`),
  KEY `idx_cheque_date` (`cheque_date`),
  KEY `idx_status` (`status`),
  KEY `idx_billing_item` (`billing_item_id`),
  KEY `idx_installment` (`installment_id`),
  FOREIGN KEY (`company_id`) REFERENCES `companies`(`id`) ON DELETE RESTRICT,
  FOREIGN KEY (`lease_id`) REFERENCES `re_leases`(`id`) ON DELETE RESTRICT,
  FOREIGN KEY (`billing_item_id`) REFERENCES `re_billing_items`(`id`) ON DELETE SET NULL,
  FOREIGN KEY (`installment_id`) REFERENCES `re_lease_installments`(`id`) ON DELETE SET NULL,
  FOREIGN KEY (`payment_id`) REFERENCES `re_payments`(`id`) ON DELETE SET NULL,
  FOREIGN KEY (`created_by`) REFERENCES `user`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ============================================================================
-- 8. Invoice Number Sequence (for auto-generation)
-- ============================================================================
CREATE TABLE IF NOT EXISTS `re_invoice_sequences` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `company_id` INT(11) NOT NULL,
  `year` INT(11) NOT NULL,
  `sequence_number` INT(11) NOT NULL DEFAULT 0,
  `prefix` VARCHAR(50) DEFAULT 'INV',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_company_year` (`company_id`, `year`),
  FOREIGN KEY (`company_id`) REFERENCES `companies`(`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ============================================================================
-- 9. Add billing-related columns to existing tables
-- ============================================================================
-- Add tax/VAT support to payments (safe to re-run)
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
                   WHERE TABLE_SCHEMA = DATABASE() 
                   AND TABLE_NAME = 're_payments' 
                   AND COLUMN_NAME = 'tax_amount');
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE `re_payments` ADD COLUMN `tax_amount` DECIMAL(10,2) DEFAULT 0.00',
    'SELECT "Column tax_amount already exists" AS message');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
                   WHERE TABLE_SCHEMA = DATABASE() 
                   AND TABLE_NAME = 're_payments' 
                   AND COLUMN_NAME = 'invoice_id');
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE `re_payments` ADD COLUMN `invoice_id` INT(11) DEFAULT NULL',
    'SELECT "Column invoice_id already exists" AS message');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
                   WHERE TABLE_SCHEMA = DATABASE() 
                   AND TABLE_NAME = 're_payments' 
                   AND COLUMN_NAME = 'cheque_id');
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE `re_payments` ADD COLUMN `cheque_id` INT(11) DEFAULT NULL COMMENT "Link to post-dated cheque if payment from cheque"',
    'SELECT "Column cheque_id already exists" AS message');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add indexes for payments
SET @idx_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS 
                   WHERE TABLE_SCHEMA = DATABASE() 
                   AND TABLE_NAME = 're_payments' 
                   AND INDEX_NAME = 'idx_invoice');
SET @sql = IF(@idx_exists = 0,
    'ALTER TABLE `re_payments` ADD KEY `idx_invoice` (`invoice_id`)',
    'SELECT "Index idx_invoice already exists" AS message');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @idx_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS 
                   WHERE TABLE_SCHEMA = DATABASE() 
                   AND TABLE_NAME = 're_payments' 
                   AND INDEX_NAME = 'idx_cheque');
SET @sql = IF(@idx_exists = 0,
    'ALTER TABLE `re_payments` ADD KEY `idx_cheque` (`cheque_id`)',
    'SELECT "Index idx_cheque already exists" AS message');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add foreign keys for payments
SET @fk_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE 
                  WHERE TABLE_SCHEMA = DATABASE() 
                  AND TABLE_NAME = 're_payments' 
                  AND CONSTRAINT_NAME = 'fk_payment_invoice');
SET @sql = IF(@fk_exists = 0,
    'ALTER TABLE `re_payments` ADD CONSTRAINT `fk_payment_invoice` FOREIGN KEY (`invoice_id`) REFERENCES `re_invoices`(`id`) ON DELETE SET NULL',
    'SELECT "Foreign key fk_payment_invoice already exists" AS message');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @fk_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE 
                  WHERE TABLE_SCHEMA = DATABASE() 
                  AND TABLE_NAME = 're_payments' 
                  AND CONSTRAINT_NAME = 'fk_payment_cheque');
SET @sql = IF(@fk_exists = 0,
    'ALTER TABLE `re_payments` ADD CONSTRAINT `fk_payment_cheque` FOREIGN KEY (`cheque_id`) REFERENCES `re_post_dated_cheques`(`id`) ON DELETE SET NULL',
    'SELECT "Foreign key fk_payment_cheque already exists" AS message');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add invoice reference to lease installments
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
                   WHERE TABLE_SCHEMA = DATABASE() 
                   AND TABLE_NAME = 're_lease_installments' 
                   AND COLUMN_NAME = 'invoice_id');
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE `re_lease_installments` ADD COLUMN `invoice_id` INT(11) DEFAULT NULL',
    'SELECT "Column invoice_id already exists" AS message');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @idx_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS 
                   WHERE TABLE_SCHEMA = DATABASE() 
                   AND TABLE_NAME = 're_lease_installments' 
                   AND INDEX_NAME = 'idx_invoice');
SET @sql = IF(@idx_exists = 0,
    'ALTER TABLE `re_lease_installments` ADD KEY `idx_invoice` (`invoice_id`)',
    'SELECT "Index idx_invoice already exists" AS message');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @fk_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE 
                  WHERE TABLE_SCHEMA = DATABASE() 
                  AND TABLE_NAME = 're_lease_installments' 
                  AND CONSTRAINT_NAME = 'fk_installment_invoice');
SET @sql = IF(@fk_exists = 0,
    'ALTER TABLE `re_lease_installments` ADD CONSTRAINT `fk_installment_invoice` FOREIGN KEY (`invoice_id`) REFERENCES `re_invoices`(`id`) ON DELETE SET NULL',
    'SELECT "Foreign key fk_installment_invoice already exists" AS message');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ============================================================================
-- 10. Default Service Charge Types (Insert per company via application)
-- ============================================================================
-- Examples:
-- - Parking Fee (monthly, fixed)
-- - Maintenance Fee (monthly, fixed)
-- - Utilities (monthly, variable)
-- - Amenities Fee (monthly, fixed)
-- - Security Fee (monthly, fixed)

-- ============================================================================
-- 11. Default Penalty Rules (Insert per company via application)
-- ============================================================================
-- Examples:
-- - Late Payment Penalty (per day, percentage, or fixed)
-- - Bounced Cheque Penalty (fixed amount)
-- - Violation Penalty (fixed amount)

-- ============================================================================
-- 12. Indexes for Performance
-- ============================================================================
-- Ensure is_paid column exists in re_billing_items (in case table was created in partial run)
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
                   WHERE TABLE_SCHEMA = DATABASE() 
                   AND TABLE_NAME = 're_billing_items' 
                   AND COLUMN_NAME = 'is_paid');
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE `re_billing_items` ADD COLUMN `is_paid` TINYINT(1) NOT NULL DEFAULT 0',
    'SELECT "Column is_paid already exists" AS message');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Now create the index (column should exist now)
SET @idx_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS 
                   WHERE TABLE_SCHEMA = DATABASE() 
                   AND TABLE_NAME = 're_billing_items' 
                   AND INDEX_NAME = 'idx_lease_due_date');
SET @sql = IF(@idx_exists = 0,
    'ALTER TABLE `re_billing_items` ADD INDEX `idx_lease_due_date` (`lease_id`, `due_date`, `is_paid`)',
    'SELECT "Index idx_lease_due_date already exists" AS message');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @idx_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS 
                   WHERE TABLE_SCHEMA = DATABASE() 
                   AND TABLE_NAME = 're_invoices' 
                   AND INDEX_NAME = 'idx_lease_status');
SET @sql = IF(@idx_exists = 0,
    'ALTER TABLE `re_invoices` ADD INDEX `idx_lease_status` (`lease_id`, `status`)',
    'SELECT "Index idx_lease_status already exists" AS message');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @idx_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS 
                   WHERE TABLE_SCHEMA = DATABASE() 
                   AND TABLE_NAME = 're_post_dated_cheques' 
                   AND INDEX_NAME = 'idx_cheque_status_date');
SET @sql = IF(@idx_exists = 0,
    'ALTER TABLE `re_post_dated_cheques` ADD INDEX `idx_cheque_status_date` (`status`, `cheque_date`)',
    'SELECT "Index idx_cheque_status_date already exists" AS message');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

