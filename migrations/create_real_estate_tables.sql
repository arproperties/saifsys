-- ============================================================================
-- MULTI-COMPANY ERP INTEGRATION - Real Estate Module Tables
-- ============================================================================
-- This migration creates all tables for the Real Estate module
-- Database: bestsys
-- Safe to run multiple times (uses IF NOT EXISTS)
-- ============================================================================

SET FOREIGN_KEY_CHECKS = 0;
SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";

-- ============================================================================
-- PART 1: BUILDINGS TABLE
-- ============================================================================

CREATE TABLE IF NOT EXISTS `re_buildings` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `company_id` INT(11) NOT NULL,
  `name` VARCHAR(200) NOT NULL,
  `address` TEXT DEFAULT NULL,
  `total_floors` INT(11) DEFAULT NULL,
  `total_units` INT(11) DEFAULT NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_company` (`company_id`),
  KEY `idx_active` (`is_active`),
  FOREIGN KEY (`company_id`) REFERENCES `companies`(`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ============================================================================
-- PART 2: FLOORS TABLE (optional)
-- ============================================================================

CREATE TABLE IF NOT EXISTS `re_floors` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `building_id` INT(11) NOT NULL,
  `floor_number` INT(11) NOT NULL,
  `name` VARCHAR(100) DEFAULT NULL,
  `total_units` INT(11) NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_building_floor` (`building_id`, `floor_number`),
  KEY `idx_building` (`building_id`),
  FOREIGN KEY (`building_id`) REFERENCES `re_buildings`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ============================================================================
-- PART 3: UNITS TABLE
-- ============================================================================

CREATE TABLE IF NOT EXISTS `re_units` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `company_id` INT(11) NOT NULL,
  `building_id` INT(11) NOT NULL,
  `floor_id` INT(11) DEFAULT NULL,
  `unit_number` VARCHAR(50) NOT NULL,
  `unit_type` ENUM('studio', '1br', '2br', '3br', '4br', 'penthouse', 'commercial') NOT NULL,
  `area_sqm` DECIMAL(10,2) DEFAULT NULL,
  `status` ENUM('vacant', 'occupied', 'maintenance', 'reserved') NOT NULL DEFAULT 'vacant',
  `monthly_rent` DECIMAL(12,2) DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_building_unit` (`building_id`, `unit_number`),
  KEY `idx_status` (`status`),
  KEY `idx_company` (`company_id`),
  KEY `idx_building` (`building_id`),
  KEY `idx_floor` (`floor_id`),
  FOREIGN KEY (`company_id`) REFERENCES `companies`(`id`) ON DELETE RESTRICT,
  FOREIGN KEY (`building_id`) REFERENCES `re_buildings`(`id`) ON DELETE RESTRICT,
  FOREIGN KEY (`floor_id`) REFERENCES `re_floors`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ============================================================================
-- PART 4: TENANTS TABLE
-- ============================================================================

CREATE TABLE IF NOT EXISTS `re_tenants` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `company_id` INT(11) NOT NULL,
  `first_name` VARCHAR(100) NOT NULL,
  `last_name` VARCHAR(100) NOT NULL,
  `email` VARCHAR(150) DEFAULT NULL,
  `phone` VARCHAR(50) DEFAULT NULL,
  `phone_alt` VARCHAR(50) DEFAULT NULL,
  `id_type` ENUM('emirates_id', 'passport', 'visa') NOT NULL,
  `id_number` VARCHAR(100) NOT NULL,
  `address` TEXT DEFAULT NULL,
  `emergency_contact_name` VARCHAR(200) DEFAULT NULL,
  `emergency_contact_phone` VARCHAR(50) DEFAULT NULL,
  `notes` TEXT DEFAULT NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_company` (`company_id`),
  KEY `idx_id_number` (`id_number`),
  KEY `idx_active` (`is_active`),
  FOREIGN KEY (`company_id`) REFERENCES `companies`(`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ============================================================================
-- PART 5: LEASES TABLE
-- ============================================================================

CREATE TABLE IF NOT EXISTS `re_leases` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `company_id` INT(11) NOT NULL,
  `unit_id` INT(11) NOT NULL,
  `tenant_id` INT(11) NOT NULL,
  `lease_number` VARCHAR(50) DEFAULT NULL,
  `start_date` DATE NOT NULL,
  `end_date` DATE NOT NULL,
  `monthly_rent` DECIMAL(12,2) NOT NULL,
  `security_deposit` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `payment_day` INT(2) NOT NULL DEFAULT 1 COMMENT 'Day of month rent is due',
  `payment_method` ENUM('cash', 'bank_transfer', 'cheque', 'auto_debit') NOT NULL DEFAULT 'bank_transfer',
  `status` ENUM('draft', 'active', 'expired', 'terminated', 'renewed') NOT NULL DEFAULT 'draft',
  `move_in_date` DATE DEFAULT NULL,
  `move_out_date` DATE DEFAULT NULL,
  `notes` TEXT DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `created_by` INT(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_lease_number` (`lease_number`),
  KEY `idx_company` (`company_id`),
  KEY `idx_unit` (`unit_id`),
  KEY `idx_tenant` (`tenant_id`),
  KEY `idx_status` (`status`),
  KEY `idx_active_lease` (`unit_id`, `status`),
  KEY `idx_created_by` (`created_by`),
  FOREIGN KEY (`company_id`) REFERENCES `companies`(`id`) ON DELETE RESTRICT,
  FOREIGN KEY (`unit_id`) REFERENCES `re_units`(`id`) ON DELETE RESTRICT,
  FOREIGN KEY (`tenant_id`) REFERENCES `re_tenants`(`id`) ON DELETE RESTRICT,
  FOREIGN KEY (`created_by`) REFERENCES `user`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ============================================================================
-- PART 6: PAYMENTS TABLE (created before installments, FK added later)
-- ============================================================================

CREATE TABLE IF NOT EXISTS `re_payments` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `company_id` INT(11) NOT NULL,
  `lease_id` INT(11) NOT NULL,
  `installment_id` INT(11) DEFAULT NULL,
  `payment_date` DATE NOT NULL,
  `amount` DECIMAL(12,2) NOT NULL,
  `payment_method` ENUM('cash', 'bank_transfer', 'cheque', 'auto_debit') NOT NULL,
  `reference_number` VARCHAR(100) DEFAULT NULL,
  `receipt_number` VARCHAR(50) DEFAULT NULL,
  `notes` TEXT DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `created_by` INT(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_company` (`company_id`),
  KEY `idx_lease` (`lease_id`),
  KEY `idx_installment` (`installment_id`),
  KEY `idx_date` (`payment_date`),
  KEY `idx_created_by` (`created_by`),
  FOREIGN KEY (`company_id`) REFERENCES `companies`(`id`) ON DELETE RESTRICT,
  FOREIGN KEY (`lease_id`) REFERENCES `re_leases`(`id`) ON DELETE RESTRICT,
  FOREIGN KEY (`created_by`) REFERENCES `user`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ============================================================================
-- PART 7: LEASE INSTALLMENTS TABLE (rent schedule)
-- ============================================================================

CREATE TABLE IF NOT EXISTS `re_lease_installments` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `lease_id` INT(11) NOT NULL,
  `installment_date` DATE NOT NULL,
  `amount` DECIMAL(12,2) NOT NULL,
  `status` ENUM('pending', 'paid', 'overdue', 'waived') NOT NULL DEFAULT 'pending',
  `paid_at` DATETIME DEFAULT NULL,
  `payment_id` INT(11) DEFAULT NULL,
  `notes` VARCHAR(500) DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_lease` (`lease_id`),
  KEY `idx_status` (`status`),
  KEY `idx_date` (`installment_date`),
  KEY `idx_payment` (`payment_id`),
  FOREIGN KEY (`lease_id`) REFERENCES `re_leases`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`payment_id`) REFERENCES `re_payments`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ============================================================================
-- PART 8: MAINTENANCE REQUESTS TABLE
-- ============================================================================

CREATE TABLE IF NOT EXISTS `re_maintenance_requests` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `company_id` INT(11) NOT NULL,
  `unit_id` INT(11) NOT NULL,
  `tenant_id` INT(11) DEFAULT NULL,
  `lease_id` INT(11) DEFAULT NULL,
  `request_date` DATE NOT NULL,
  `priority` ENUM('low', 'medium', 'high', 'urgent') NOT NULL DEFAULT 'medium',
  `category` VARCHAR(100) DEFAULT NULL,
  `description` TEXT NOT NULL,
  `status` ENUM('pending', 'in_progress', 'completed', 'cancelled') NOT NULL DEFAULT 'pending',
  `assigned_to` INT(11) DEFAULT NULL COMMENT 'employee_id',
  `completed_at` DATETIME DEFAULT NULL,
  `cost` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `notes` TEXT DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `created_by` INT(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_company` (`company_id`),
  KEY `idx_unit` (`unit_id`),
  KEY `idx_tenant` (`tenant_id`),
  KEY `idx_lease` (`lease_id`),
  KEY `idx_status` (`status`),
  KEY `idx_assigned_to` (`assigned_to`),
  KEY `idx_created_by` (`created_by`),
  FOREIGN KEY (`company_id`) REFERENCES `companies`(`id`) ON DELETE RESTRICT,
  FOREIGN KEY (`unit_id`) REFERENCES `re_units`(`id`) ON DELETE RESTRICT,
  FOREIGN KEY (`tenant_id`) REFERENCES `re_tenants`(`id`) ON DELETE SET NULL,
  FOREIGN KEY (`lease_id`) REFERENCES `re_leases`(`id`) ON DELETE SET NULL,
  FOREIGN KEY (`assigned_to`) REFERENCES `employees`(`id`) ON DELETE SET NULL,
  FOREIGN KEY (`created_by`) REFERENCES `user`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ============================================================================
-- PART 9: UNIT STATUS HISTORY TABLE (audit trail)
-- ============================================================================

CREATE TABLE IF NOT EXISTS `re_unit_status_history` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `unit_id` INT(11) NOT NULL,
  `old_status` VARCHAR(50) DEFAULT NULL,
  `new_status` VARCHAR(50) DEFAULT NULL,
  `changed_by` INT(11) DEFAULT NULL,
  `changed_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `reason` TEXT DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_unit` (`unit_id`),
  KEY `idx_date` (`changed_at`),
  KEY `idx_changed_by` (`changed_by`),
  FOREIGN KEY (`unit_id`) REFERENCES `re_units`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`changed_by`) REFERENCES `user`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ============================================================================
-- PART 10: ADD CIRCULAR FOREIGN KEY (after both tables exist)
-- ============================================================================
-- Note: re_payments.installment_id references re_lease_installments.id
-- and re_lease_installments.payment_id references re_payments.id
-- We add the FK from payments to installments here (installments FK already added above)

-- Add FK from payments to installments
SET @fk_exists = (
    SELECT COUNT(*) 
    FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE 
    WHERE TABLE_SCHEMA = DATABASE() 
    AND TABLE_NAME = 're_payments' 
    AND COLUMN_NAME = 'installment_id'
    AND REFERENCED_TABLE_NAME = 're_lease_installments'
);

SET @sql = IF(@fk_exists = 0,
    'ALTER TABLE `re_payments` 
     ADD CONSTRAINT `fk_re_payments_installment` 
     FOREIGN KEY (`installment_id`) REFERENCES `re_lease_installments`(`id`) ON DELETE SET NULL',
    'SELECT "FK already exists" AS message'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET FOREIGN_KEY_CHECKS = 1;

-- ============================================================================
-- MIGRATION COMPLETE - Real Estate Tables
-- ============================================================================

