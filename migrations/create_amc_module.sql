-- ============================================================================
-- ANNUAL MAINTENANCE CONTRACT (AMC) MODULE - Database Schema
-- ============================================================================
-- This migration creates all tables for the AMC module in Real Estate
-- Database: bestsys
-- Safe to run multiple times (uses IF NOT EXISTS)
-- ============================================================================

SET FOREIGN_KEY_CHECKS = 0;
SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";

-- ============================================================================
-- PART 1: AMC CATEGORIES TABLE
-- ============================================================================
-- Predefined categories for different types of AMCs

CREATE TABLE IF NOT EXISTS `re_amc_categories` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(100) NOT NULL COMMENT 'Fire Alarm, Elevator, Pool, etc.',
  `code` VARCHAR(50) NOT NULL COMMENT 'fire_alarm, elevator, pool, etc.',
  `description` TEXT DEFAULT NULL,
  `is_mandatory` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Required by DCD/Municipality',
  `regulatory_body` VARCHAR(100) DEFAULT NULL COMMENT 'DCD, Municipality, etc.',
  `default_visit_frequency` ENUM('daily', 'weekly', 'monthly', 'quarterly', 'bi_annual', 'annual') DEFAULT 'monthly',
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_amc_category_code` (`code`),
  KEY `idx_active` (`is_active`),
  KEY `idx_mandatory` (`is_mandatory`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Insert default AMC categories
INSERT IGNORE INTO `re_amc_categories` (`name`, `code`, `description`, `is_mandatory`, `regulatory_body`, `default_visit_frequency`) VALUES
('Fire Alarm System', 'fire_alarm', 'Fire alarm and detection system maintenance', 1, 'Dubai Civil Defense', 'monthly'),
('Fire Fighting System', 'fire_fighting', 'Fire fighting equipment and hydrants', 1, 'Dubai Civil Defense', 'monthly'),
('Elevator', 'elevator', 'Elevator maintenance and safety', 1, 'Dubai Municipality', 'monthly'),
('Swimming Pool', 'pool', 'Pool cleaning and chemical treatment', 1, 'Dubai Municipality', 'weekly'),
('Pest Control', 'pest_control', 'Regular pest control services', 1, 'Dubai Municipality', 'monthly'),
('Water Tank Cleaning', 'water_tank', 'Water tank cleaning and disinfection', 1, 'Dubai Municipality', 'quarterly'),
('Waste Collection', 'waste_collection', 'Garbage collection and disposal', 1, 'Dubai Municipality', 'daily'),
('DCD Compliance', 'dcd_compliance', 'General DCD compliance and inspections', 1, 'Dubai Civil Defense', 'quarterly'),
('AC Maintenance', 'ac_maintenance', 'HVAC system maintenance', 0, NULL, 'monthly'),
('Landscaping', 'landscaping', 'Garden and landscaping maintenance', 0, NULL, 'weekly'),
('Security System', 'security_system', 'CCTV and security equipment', 0, NULL, 'monthly'),
('Generator', 'generator', 'Backup generator maintenance', 0, NULL, 'monthly');

-- ============================================================================
-- PART 2: AMC CONTRACTS TABLE
-- ============================================================================
-- Main table for AMC contracts

CREATE TABLE IF NOT EXISTS `re_amc_contracts` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `company_id` INT(11) NOT NULL,
  `building_id` INT(11) NOT NULL,
  `category_id` INT(11) NOT NULL,
  `vendor_id` INT(11) NOT NULL,
  `contract_number` VARCHAR(100) NOT NULL,
  `contract_title` VARCHAR(255) DEFAULT NULL,
  `start_date` DATE NOT NULL,
  `end_date` DATE NOT NULL,
  `renewal_date` DATE DEFAULT NULL COMMENT 'Auto-renewal date if applicable',
  `contract_value` DECIMAL(15,2) NOT NULL DEFAULT 0.00 COMMENT 'Total contract value',
  `vat_percentage` DECIMAL(5,2) NOT NULL DEFAULT 5.00 COMMENT 'VAT percentage',
  `vat_amount` DECIMAL(15,2) NOT NULL DEFAULT 0.00 COMMENT 'Calculated VAT',
  `total_amount` DECIMAL(15,2) NOT NULL DEFAULT 0.00 COMMENT 'Contract value + VAT',
  `payment_terms` VARCHAR(100) DEFAULT NULL COMMENT 'e.g., Monthly, Quarterly, Annual',
  `payment_schedule` ENUM('monthly', 'quarterly', 'semi_annual', 'annual', 'one_time') DEFAULT 'annual',
  `visit_frequency` ENUM('daily', 'weekly', 'monthly', 'quarterly', 'bi_annual', 'annual') NOT NULL DEFAULT 'monthly',
  `sla_response_time` INT(11) DEFAULT NULL COMMENT 'SLA response time in hours',
  `sla_resolution_time` INT(11) DEFAULT NULL COMMENT 'SLA resolution time in hours',
  `status` ENUM('draft', 'active', 'expired', 'terminated', 'renewed', 'cancelled') NOT NULL DEFAULT 'draft',
  `auto_renew` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Auto-renew contract on expiry',
  `notes` TEXT DEFAULT NULL,
  `created_by` INT(11) DEFAULT NULL COMMENT 'User who created the contract',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_contract_number` (`contract_number`),
  KEY `idx_company` (`company_id`),
  KEY `idx_building` (`building_id`),
  KEY `idx_category` (`category_id`),
  KEY `idx_vendor` (`vendor_id`),
  KEY `idx_status` (`status`),
  KEY `idx_dates` (`start_date`, `end_date`),
  KEY `idx_expiry` (`end_date`),
  FOREIGN KEY (`company_id`) REFERENCES `companies`(`id`) ON DELETE RESTRICT,
  FOREIGN KEY (`building_id`) REFERENCES `re_buildings`(`id`) ON DELETE RESTRICT,
  FOREIGN KEY (`category_id`) REFERENCES `re_amc_categories`(`id`) ON DELETE RESTRICT,
  FOREIGN KEY (`vendor_id`) REFERENCES `re_vendors`(`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ============================================================================
-- PART 3: AMC VISITS TABLE
-- ============================================================================
-- Scheduled and completed inspection visits

CREATE TABLE IF NOT EXISTS `re_amc_visits` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `contract_id` INT(11) NOT NULL,
  `visit_number` INT(11) NOT NULL COMMENT 'Sequential visit number for this contract',
  `scheduled_date` DATE NOT NULL,
  `scheduled_time` TIME DEFAULT NULL,
  `actual_date` DATE DEFAULT NULL,
  `actual_time` TIME DEFAULT NULL,
  `visit_type` ENUM('scheduled', 'emergency', 'inspection', 'repair', 'compliance') NOT NULL DEFAULT 'scheduled',
  `status` ENUM('scheduled', 'in_progress', 'completed', 'cancelled', 'rescheduled') NOT NULL DEFAULT 'scheduled',
  `technician_name` VARCHAR(255) DEFAULT NULL,
  `technician_phone` VARCHAR(50) DEFAULT NULL,
  `work_performed` TEXT DEFAULT NULL,
  `issues_found` TEXT DEFAULT NULL,
  `parts_replaced` TEXT DEFAULT NULL,
  `next_visit_date` DATE DEFAULT NULL,
  `cost` DECIMAL(12,2) DEFAULT NULL COMMENT 'Cost for this visit if different from contract',
  `rating` INT(11) DEFAULT NULL COMMENT 'Service rating 1-5',
  `notes` TEXT DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_contract_visit` (`contract_id`, `visit_number`),
  KEY `idx_contract` (`contract_id`),
  KEY `idx_scheduled_date` (`scheduled_date`),
  KEY `idx_status` (`status`),
  KEY `idx_visit_type` (`visit_type`),
  FOREIGN KEY (`contract_id`) REFERENCES `re_amc_contracts`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ============================================================================
-- PART 4: AMC CERTIFICATES TABLE
-- ============================================================================
-- Compliance certificates and documents

CREATE TABLE IF NOT EXISTS `re_amc_certificates` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `contract_id` INT(11) NOT NULL,
  `certificate_type` ENUM('compliance', 'inspection', 'safety', 'renewal', 'other') NOT NULL DEFAULT 'compliance',
  `certificate_number` VARCHAR(100) DEFAULT NULL,
  `issued_by` VARCHAR(255) DEFAULT NULL COMMENT 'Issuing authority (DCD, Municipality, etc.)',
  `issue_date` DATE NOT NULL,
  `expiry_date` DATE NOT NULL,
  `file_name` VARCHAR(255) DEFAULT NULL,
  `file_path` VARCHAR(500) DEFAULT NULL,
  `file_size` INT(11) DEFAULT NULL,
  `status` ENUM('valid', 'expired', 'expiring_soon', 'renewed') NOT NULL DEFAULT 'valid',
  `notes` TEXT DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_contract` (`contract_id`),
  KEY `idx_expiry` (`expiry_date`),
  KEY `idx_status` (`status`),
  KEY `idx_certificate_number` (`certificate_number`),
  FOREIGN KEY (`contract_id`) REFERENCES `re_amc_contracts`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ============================================================================
-- PART 5: AMC ALERTS TABLE
-- ============================================================================
-- Expiry alerts and notifications

CREATE TABLE IF NOT EXISTS `re_amc_alerts` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `contract_id` INT(11) DEFAULT NULL,
  `certificate_id` INT(11) DEFAULT NULL,
  `alert_type` ENUM('contract_expiry', 'certificate_expiry', 'visit_due', 'renewal_due', 'payment_due') NOT NULL,
  `alert_date` DATE NOT NULL COMMENT 'Date when alert should trigger',
  `expiry_date` DATE NOT NULL COMMENT 'Actual expiry date',
  `days_until_expiry` INT(11) NOT NULL COMMENT 'Days remaining until expiry',
  `severity` ENUM('info', 'warning', 'critical') NOT NULL DEFAULT 'warning',
  `status` ENUM('pending', 'sent', 'acknowledged', 'resolved', 'dismissed') NOT NULL DEFAULT 'pending',
  `notification_sent` TINYINT(1) NOT NULL DEFAULT 0,
  `notification_sent_at` DATETIME DEFAULT NULL,
  `acknowledged_by` INT(11) DEFAULT NULL COMMENT 'User who acknowledged',
  `acknowledged_at` DATETIME DEFAULT NULL,
  `notes` TEXT DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_contract` (`contract_id`),
  KEY `idx_certificate` (`certificate_id`),
  KEY `idx_alert_type` (`alert_type`),
  KEY `idx_alert_date` (`alert_date`),
  KEY `idx_status` (`status`),
  KEY `idx_severity` (`severity`),
  FOREIGN KEY (`contract_id`) REFERENCES `re_amc_contracts`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`certificate_id`) REFERENCES `re_amc_certificates`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ============================================================================
-- PART 6: AMC PAYMENTS TABLE
-- ============================================================================
-- Payment tracking (links to accounting module)

CREATE TABLE IF NOT EXISTS `re_amc_payments` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `contract_id` INT(11) NOT NULL,
  `company_id` INT(11) NOT NULL,
  `payment_number` VARCHAR(100) DEFAULT NULL COMMENT 'Invoice/Bill number',
  `payment_type` ENUM('advance', 'installment', 'final', 'penalty', 'refund') NOT NULL DEFAULT 'installment',
  `payment_date` DATE NOT NULL,
  `due_date` DATE DEFAULT NULL,
  `amount` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  `vat_amount` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  `total_amount` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  `payment_status` ENUM('pending', 'paid', 'partial', 'overdue', 'cancelled') NOT NULL DEFAULT 'pending',
  `payment_method` VARCHAR(50) DEFAULT NULL COMMENT 'Cash, Cheque, Bank Transfer, etc.',
  `reference_number` VARCHAR(100) DEFAULT NULL COMMENT 'Cheque number, transaction ID, etc.',
  `paid_date` DATE DEFAULT NULL,
  `paid_amount` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  `vendor_invoice_id` INT(11) DEFAULT NULL COMMENT 'Link to vendor invoice if exists',
  `accounting_entry_id` INT(11) DEFAULT NULL COMMENT 'Link to accounting GL entry',
  `notes` TEXT DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_contract` (`contract_id`),
  KEY `idx_company` (`company_id`),
  KEY `idx_payment_date` (`payment_date`),
  KEY `idx_status` (`payment_status`),
  KEY `idx_vendor_invoice` (`vendor_invoice_id`),
  FOREIGN KEY (`contract_id`) REFERENCES `re_amc_contracts`(`id`) ON DELETE RESTRICT,
  FOREIGN KEY (`company_id`) REFERENCES `companies`(`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ============================================================================
-- PART 7: AMC VISIT PHOTOS TABLE
-- ============================================================================
-- Photos attached to visits

CREATE TABLE IF NOT EXISTS `re_amc_visit_photos` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `visit_id` INT(11) NOT NULL,
  `photo_type` ENUM('before', 'during', 'after', 'issue', 'certificate') NOT NULL DEFAULT 'during',
  `file_name` VARCHAR(255) NOT NULL,
  `file_path` VARCHAR(500) NOT NULL,
  `file_size` INT(11) NOT NULL,
  `caption` VARCHAR(255) DEFAULT NULL,
  `uploaded_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_visit` (`visit_id`),
  FOREIGN KEY (`visit_id`) REFERENCES `re_amc_visits`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ============================================================================
-- PART 8: AMC ALERT CONFIGURATION TABLE
-- ============================================================================
-- Configuration for alert thresholds

CREATE TABLE IF NOT EXISTS `re_amc_alert_config` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `company_id` INT(11) NOT NULL,
  `alert_type` ENUM('contract_expiry', 'certificate_expiry', 'visit_due') NOT NULL,
  `days_before_expiry` INT(11) NOT NULL COMMENT 'Alert X days before expiry',
  `is_enabled` TINYINT(1) NOT NULL DEFAULT 1,
  `notification_methods` JSON DEFAULT NULL COMMENT 'email, sms, dashboard',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_company_alert_type` (`company_id`, `alert_type`),
  KEY `idx_company` (`company_id`),
  FOREIGN KEY (`company_id`) REFERENCES `companies`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Insert default alert configurations
INSERT IGNORE INTO `re_amc_alert_config` (`company_id`, `alert_type`, `days_before_expiry`, `is_enabled`) 
SELECT 1, 'contract_expiry', 30, 1 UNION ALL
SELECT 1, 'contract_expiry', 7, 1 UNION ALL
SELECT 1, 'certificate_expiry', 30, 1 UNION ALL
SELECT 1, 'certificate_expiry', 7, 1 UNION ALL
SELECT 1, 'visit_due', 3, 1;

SET FOREIGN_KEY_CHECKS = 1;

-- ============================================================================
-- END OF MIGRATION
-- ============================================================================
