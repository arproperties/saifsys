-- Phase 1 MVP Enhancement: Collections & Alerts System
-- Automated overdue rent alerts, payment notifications, bounced cheque tracking

-- ============================================================================
-- 1. Collections Alerts Configuration
-- ============================================================================
CREATE TABLE IF NOT EXISTS `re_collections_alerts_config` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `company_id` INT(11) NOT NULL,
  `alert_type` ENUM('overdue_rent', 'payment_received', 'bounced_cheque', 'upcoming_due', 'invoice_overdue') NOT NULL,
  `is_enabled` TINYINT(1) NOT NULL DEFAULT 1,
  `days_before_alert` INT(11) DEFAULT 0 COMMENT 'For upcoming_due alerts',
  `days_overdue_threshold` INT(11) DEFAULT 0 COMMENT 'Days overdue before alert (0 = immediate)',
  `alert_frequency` ENUM('once', 'daily', 'weekly') NOT NULL DEFAULT 'daily',
  `recipient_emails` TEXT DEFAULT NULL COMMENT 'Comma-separated email addresses',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_company_alert_type` (`company_id`, `alert_type`),
  KEY `idx_company` (`company_id`),
  KEY `idx_enabled` (`is_enabled`),
  FOREIGN KEY (`company_id`) REFERENCES `companies`(`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ============================================================================
-- 2. Overdue Rent Alerts Log
-- ============================================================================
CREATE TABLE IF NOT EXISTS `re_overdue_rent_alerts` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `company_id` INT(11) NOT NULL,
  `lease_id` INT(11) NOT NULL,
  `installment_id` INT(11) DEFAULT NULL,
  `billing_item_id` INT(11) DEFAULT NULL,
  `invoice_id` INT(11) DEFAULT NULL,
  `due_date` DATE NOT NULL,
  `amount` DECIMAL(10,2) NOT NULL,
  `days_overdue` INT(11) NOT NULL,
  `alert_sent_date` DATE NOT NULL,
  `alert_sent_to` TEXT DEFAULT NULL COMMENT 'Comma-separated emails',
  `status` ENUM('pending', 'sent', 'resolved', 'cancelled') NOT NULL DEFAULT 'pending',
  `resolved_date` DATE DEFAULT NULL,
  `resolved_by` INT(11) DEFAULT NULL COMMENT 'user_id',
  `notes` TEXT DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_company` (`company_id`),
  KEY `idx_lease` (`lease_id`),
  KEY `idx_status` (`status`),
  KEY `idx_due_date` (`due_date`),
  KEY `idx_installment` (`installment_id`),
  KEY `idx_billing_item` (`billing_item_id`),
  KEY `idx_invoice` (`invoice_id`),
  FOREIGN KEY (`company_id`) REFERENCES `companies`(`id`) ON DELETE RESTRICT,
  FOREIGN KEY (`lease_id`) REFERENCES `re_leases`(`id`) ON DELETE RESTRICT,
  FOREIGN KEY (`installment_id`) REFERENCES `re_lease_installments`(`id`) ON DELETE SET NULL,
  FOREIGN KEY (`billing_item_id`) REFERENCES `re_billing_items`(`id`) ON DELETE SET NULL,
  FOREIGN KEY (`invoice_id`) REFERENCES `re_invoices`(`id`) ON DELETE SET NULL,
  FOREIGN KEY (`resolved_by`) REFERENCES `user`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ============================================================================
-- 3. Payment Received Notifications Log
-- ============================================================================
CREATE TABLE IF NOT EXISTS `re_payment_notifications` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `company_id` INT(11) NOT NULL,
  `payment_id` INT(11) NOT NULL,
  `lease_id` INT(11) NOT NULL,
  `notification_sent_date` DATETIME NOT NULL,
  `notification_sent_to` TEXT DEFAULT NULL COMMENT 'Comma-separated emails',
  `notification_type` ENUM('payment_received', 'partial_payment', 'full_payment') NOT NULL DEFAULT 'payment_received',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_company` (`company_id`),
  KEY `idx_payment` (`payment_id`),
  KEY `idx_lease` (`lease_id`),
  KEY `idx_sent_date` (`notification_sent_date`),
  FOREIGN KEY (`company_id`) REFERENCES `companies`(`id`) ON DELETE RESTRICT,
  FOREIGN KEY (`payment_id`) REFERENCES `re_payments`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`lease_id`) REFERENCES `re_leases`(`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ============================================================================
-- 4. Bounced Cheque Alerts Log
-- ============================================================================
CREATE TABLE IF NOT EXISTS `re_bounced_cheque_alerts` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `company_id` INT(11) NOT NULL,
  `cheque_id` INT(11) NOT NULL,
  `lease_id` INT(11) NOT NULL,
  `cheque_number` VARCHAR(100) NOT NULL,
  `cheque_amount` DECIMAL(10,2) NOT NULL,
  `bounced_date` DATE NOT NULL,
  `bounced_reason` TEXT DEFAULT NULL,
  `alert_sent_date` DATETIME NOT NULL,
  `alert_sent_to` TEXT DEFAULT NULL COMMENT 'Comma-separated emails',
  `status` ENUM('pending', 'sent', 'resolved', 'cancelled') NOT NULL DEFAULT 'pending',
  `resolved_date` DATE DEFAULT NULL,
  `resolved_by` INT(11) DEFAULT NULL COMMENT 'user_id',
  `resolution_notes` TEXT DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_company` (`company_id`),
  KEY `idx_cheque` (`cheque_id`),
  KEY `idx_lease` (`lease_id`),
  KEY `idx_status` (`status`),
  KEY `idx_bounced_date` (`bounced_date`),
  FOREIGN KEY (`company_id`) REFERENCES `companies`(`id`) ON DELETE RESTRICT,
  FOREIGN KEY (`cheque_id`) REFERENCES `re_post_dated_cheques`(`id`) ON DELETE RESTRICT,
  FOREIGN KEY (`lease_id`) REFERENCES `re_leases`(`id`) ON DELETE RESTRICT,
  FOREIGN KEY (`resolved_by`) REFERENCES `user`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ============================================================================
-- 5. Upcoming Due Date Alerts
-- ============================================================================
CREATE TABLE IF NOT EXISTS `re_upcoming_due_alerts` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `company_id` INT(11) NOT NULL,
  `lease_id` INT(11) NOT NULL,
  `installment_id` INT(11) DEFAULT NULL,
  `billing_item_id` INT(11) DEFAULT NULL,
  `invoice_id` INT(11) DEFAULT NULL,
  `due_date` DATE NOT NULL,
  `amount` DECIMAL(10,2) NOT NULL,
  `days_before_due` INT(11) NOT NULL COMMENT 'Days before due date when alert was sent',
  `alert_sent_date` DATE NOT NULL,
  `alert_sent_to` TEXT DEFAULT NULL COMMENT 'Comma-separated emails',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_company` (`company_id`),
  KEY `idx_lease` (`lease_id`),
  KEY `idx_due_date` (`due_date`),
  KEY `idx_installment` (`installment_id`),
  KEY `idx_billing_item` (`billing_item_id`),
  KEY `idx_invoice` (`invoice_id`),
  FOREIGN KEY (`company_id`) REFERENCES `companies`(`id`) ON DELETE RESTRICT,
  FOREIGN KEY (`lease_id`) REFERENCES `re_leases`(`id`) ON DELETE RESTRICT,
  FOREIGN KEY (`installment_id`) REFERENCES `re_lease_installments`(`id`) ON DELETE SET NULL,
  FOREIGN KEY (`billing_item_id`) REFERENCES `re_billing_items`(`id`) ON DELETE SET NULL,
  FOREIGN KEY (`invoice_id`) REFERENCES `re_invoices`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ============================================================================
-- 6. Collections Summary View (Virtual/Computed)
-- ============================================================================
-- This will be computed in PHP, but we can add indexes to help performance

-- Add indexes to existing tables for collections queries
SET @idx_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS 
                   WHERE TABLE_SCHEMA = DATABASE() 
                   AND TABLE_NAME = 're_lease_installments' 
                   AND INDEX_NAME = 'idx_status_date');
SET @sql = IF(@idx_exists = 0,
    'ALTER TABLE `re_lease_installments` ADD INDEX `idx_status_date` (`status`, `installment_date`)',
    'SELECT "Index idx_status_date already exists" AS message');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @idx_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS 
                   WHERE TABLE_SCHEMA = DATABASE() 
                   AND TABLE_NAME = 're_billing_items' 
                   AND INDEX_NAME = 'idx_due_paid');
SET @sql = IF(@idx_exists = 0,
    'ALTER TABLE `re_billing_items` ADD INDEX `idx_due_paid` (`due_date`, `is_paid`)',
    'SELECT "Index idx_due_paid already exists" AS message');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ============================================================================
-- 7. Default Alert Configuration (Insert per company via application)
-- ============================================================================
-- Examples will be inserted via the UI:
-- - Overdue Rent Alert: Enabled, 0 days threshold, daily frequency
-- - Payment Received: Enabled, immediate notification
-- - Bounced Cheque: Enabled, immediate notification
-- - Upcoming Due: Enabled, 3 days before, once per item

