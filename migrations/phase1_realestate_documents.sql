-- ============================================================================
-- PHASE 1: Real Estate Document Management System
-- ============================================================================
-- Creates tables for document storage, tracking, and compliance
-- ============================================================================

SET FOREIGN_KEY_CHECKS = 0;

-- ============================================================================
-- PART 1: REAL ESTATE DOCUMENTS TABLE
-- ============================================================================

CREATE TABLE IF NOT EXISTS `re_documents` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `company_id` INT(11) NOT NULL,
  `document_type` ENUM(
    'lease_agreement',
    'ejari',
    'tenant_id',
    'tenant_passport',
    'tenant_visa',
    'noc',
    'municipality_approval',
    'insurance',
    'building_permit',
    'utility_connection',
    'other'
  ) NOT NULL,
  `related_type` ENUM('lease', 'tenant', 'unit', 'building', 'maintenance') NOT NULL,
  `related_id` INT(11) NOT NULL COMMENT 'ID of related lease/tenant/unit/building/maintenance',
  `file_name` VARCHAR(255) NOT NULL COMMENT 'Original filename',
  `file_path` VARCHAR(500) NOT NULL COMMENT 'Relative path from project root',
  `file_size` INT(11) NOT NULL COMMENT 'File size in bytes',
  `mime_type` VARCHAR(100) DEFAULT NULL,
  `expires_at` DATE DEFAULT NULL COMMENT 'Document expiry date (for visas, insurance, etc.)',
  `is_expired` TINYINT(1) NOT NULL DEFAULT 0,
  `notes` TEXT DEFAULT NULL,
  `uploaded_by` INT(11) DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_company` (`company_id`),
  KEY `idx_type` (`document_type`),
  KEY `idx_related` (`related_type`, `related_id`),
  KEY `idx_expires` (`expires_at`, `is_expired`),
  KEY `idx_uploaded_by` (`uploaded_by`),
  FOREIGN KEY (`company_id`) REFERENCES `companies`(`id`) ON DELETE RESTRICT,
  FOREIGN KEY (`uploaded_by`) REFERENCES `user`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ============================================================================
-- PART 2: MOVE-IN/MOVE-OUT OPERATIONS TABLE
-- ============================================================================

CREATE TABLE IF NOT EXISTS `re_move_operations` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `company_id` INT(11) NOT NULL,
  `lease_id` INT(11) NOT NULL,
  `unit_id` INT(11) NOT NULL,
  `tenant_id` INT(11) NOT NULL,
  `operation_type` ENUM('move_in', 'move_out') NOT NULL,
  `operation_date` DATE NOT NULL,
  `status` ENUM('pending', 'in_progress', 'completed', 'cancelled') NOT NULL DEFAULT 'pending',
  
  -- Move-In specific fields
  `contract_verified` TINYINT(1) DEFAULT 0,
  `payment_confirmed` TINYINT(1) DEFAULT 0,
  `keys_handed_over` TINYINT(1) DEFAULT 0,
  `meter_reading_electricity` DECIMAL(10,2) DEFAULT NULL,
  `meter_reading_water` DECIMAL(10,2) DEFAULT NULL,
  `meter_reading_gas` DECIMAL(10,2) DEFAULT NULL,
  `inspection_completed` TINYINT(1) DEFAULT 0,
  `inspection_notes` TEXT DEFAULT NULL,
  
  -- Move-Out specific fields
  `notice_received_date` DATE DEFAULT NULL,
  `final_inspection_date` DATE DEFAULT NULL,
  `damage_assessment` TEXT DEFAULT NULL,
  `deposit_deduction_amount` DECIMAL(12,2) DEFAULT 0.00,
  `deposit_deduction_reason` TEXT DEFAULT NULL,
  `deposit_returned_amount` DECIMAL(12,2) DEFAULT 0.00,
  `final_meter_reading_electricity` DECIMAL(10,2) DEFAULT NULL,
  `final_meter_reading_water` DECIMAL(10,2) DEFAULT NULL,
  `final_meter_reading_gas` DECIMAL(10,2) DEFAULT NULL,
  
  `completed_by` INT(11) DEFAULT NULL COMMENT 'user_id',
  `completed_at` DATETIME DEFAULT NULL,
  `notes` TEXT DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `created_by` INT(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_company` (`company_id`),
  KEY `idx_lease` (`lease_id`),
  KEY `idx_unit` (`unit_id`),
  KEY `idx_tenant` (`tenant_id`),
  KEY `idx_type_status` (`operation_type`, `status`),
  KEY `idx_date` (`operation_date`),
  KEY `idx_created_by` (`created_by`),
  FOREIGN KEY (`company_id`) REFERENCES `companies`(`id`) ON DELETE RESTRICT,
  FOREIGN KEY (`lease_id`) REFERENCES `re_leases`(`id`) ON DELETE RESTRICT,
  FOREIGN KEY (`unit_id`) REFERENCES `re_units`(`id`) ON DELETE RESTRICT,
  FOREIGN KEY (`tenant_id`) REFERENCES `re_tenants`(`id`) ON DELETE RESTRICT,
  FOREIGN KEY (`created_by`) REFERENCES `user`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ============================================================================
-- PART 3: BILLING ITEMS TABLE (Service Charges, Parking, Penalties)
-- ============================================================================

CREATE TABLE IF NOT EXISTS `re_billing_items` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `company_id` INT(11) NOT NULL,
  `lease_id` INT(11) NOT NULL,
  `item_type` ENUM('service_charge', 'parking_fee', 'penalty', 'other') NOT NULL,
  `description` VARCHAR(255) NOT NULL,
  `amount` DECIMAL(12,2) NOT NULL,
  `billing_date` DATE NOT NULL COMMENT 'Date this charge applies to',
  `due_date` DATE NOT NULL,
  `status` ENUM('pending', 'paid', 'overdue', 'waived') NOT NULL DEFAULT 'pending',
  `payment_id` INT(11) DEFAULT NULL COMMENT 'Link to re_payments if paid',
  `notes` TEXT DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `created_by` INT(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_company` (`company_id`),
  KEY `idx_lease` (`lease_id`),
  KEY `idx_type` (`item_type`),
  KEY `idx_status` (`status`),
  KEY `idx_due_date` (`due_date`),
  KEY `idx_payment` (`payment_id`),
  KEY `idx_created_by` (`created_by`),
  FOREIGN KEY (`company_id`) REFERENCES `companies`(`id`) ON DELETE RESTRICT,
  FOREIGN KEY (`lease_id`) REFERENCES `re_leases`(`id`) ON DELETE RESTRICT,
  FOREIGN KEY (`payment_id`) REFERENCES `re_payments`(`id`) ON DELETE SET NULL,
  FOREIGN KEY (`created_by`) REFERENCES `user`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ============================================================================
-- PART 4: COMPLIANCE TRACKING TABLE
-- ============================================================================

CREATE TABLE IF NOT EXISTS `re_compliance_status` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `company_id` INT(11) NOT NULL,
  `unit_id` INT(11) NOT NULL,
  `lease_id` INT(11) DEFAULT NULL,
  `compliance_type` ENUM(
    'ejari_registered',
    'municipality_approved',
    'insurance_valid',
    'tenant_id_valid',
    'tenant_visa_valid',
    'noc_obtained',
    'utility_connected'
  ) NOT NULL,
  `is_compliant` TINYINT(1) NOT NULL DEFAULT 0,
  `document_id` INT(11) DEFAULT NULL COMMENT 'Link to re_documents',
  `expires_at` DATE DEFAULT NULL,
  `last_verified_at` DATETIME DEFAULT NULL,
  `verified_by` INT(11) DEFAULT NULL,
  `notes` TEXT DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_unit_compliance` (`unit_id`, `compliance_type`),
  KEY `idx_company` (`company_id`),
  KEY `idx_lease` (`lease_id`),
  KEY `idx_compliant` (`is_compliant`),
  KEY `idx_expires` (`expires_at`),
  KEY `idx_document` (`document_id`),
  KEY `idx_verified_by` (`verified_by`),
  FOREIGN KEY (`company_id`) REFERENCES `companies`(`id`) ON DELETE RESTRICT,
  FOREIGN KEY (`unit_id`) REFERENCES `re_units`(`id`) ON DELETE RESTRICT,
  FOREIGN KEY (`lease_id`) REFERENCES `re_leases`(`id`) ON DELETE SET NULL,
  FOREIGN KEY (`document_id`) REFERENCES `re_documents`(`id`) ON DELETE SET NULL,
  FOREIGN KEY (`verified_by`) REFERENCES `user`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ============================================================================
-- PART 5: ADD MISSING FIELDS TO EXISTING TABLES
-- ============================================================================

-- Add furniture status to units
ALTER TABLE `re_units` 
ADD COLUMN IF NOT EXISTS `furniture_status` ENUM('furnished', 'unfurnished', 'semi_furnished') DEFAULT NULL AFTER `status`;

-- Add blocked status (already in enum, but add notes field)
ALTER TABLE `re_units`
ADD COLUMN IF NOT EXISTS `blocked_reason` TEXT DEFAULT NULL AFTER `furniture_status`;

-- Add service charge to leases
ALTER TABLE `re_leases`
ADD COLUMN IF NOT EXISTS `monthly_service_charge` DECIMAL(12,2) DEFAULT 0.00 AFTER `monthly_rent`;

-- Add parking fee to leases
ALTER TABLE `re_leases`
ADD COLUMN IF NOT EXISTS `monthly_parking_fee` DECIMAL(12,2) DEFAULT 0.00 AFTER `monthly_service_charge`;

-- Add grace period to leases
ALTER TABLE `re_leases`
ADD COLUMN IF NOT EXISTS `grace_period_days` INT(3) DEFAULT 0 COMMENT 'Days after due date before penalty applies' AFTER `payment_day`;

-- Add penalty rate to leases
ALTER TABLE `re_leases`
ADD COLUMN IF NOT EXISTS `penalty_rate_percent` DECIMAL(5,2) DEFAULT 0.00 COMMENT 'Percentage penalty per month on overdue amount' AFTER `grace_period_days`;

-- Add renewal terms to leases
ALTER TABLE `re_leases`
ADD COLUMN IF NOT EXISTS `renewal_terms` TEXT DEFAULT NULL COMMENT 'Renewal conditions and terms' AFTER `notes`;

-- Add company/individual flag to tenants
ALTER TABLE `re_tenants`
ADD COLUMN IF NOT EXISTS `tenant_type` ENUM('individual', 'company') NOT NULL DEFAULT 'individual' AFTER `company_id`;

-- Add company name for company tenants
ALTER TABLE `re_tenants`
ADD COLUMN IF NOT EXISTS `company_name` VARCHAR(200) DEFAULT NULL AFTER `tenant_type`;

-- Add visa expiry to tenants
ALTER TABLE `re_tenants`
ADD COLUMN IF NOT EXISTS `visa_expiry_date` DATE DEFAULT NULL AFTER `id_number`;

SET FOREIGN_KEY_CHECKS = 1;

-- ============================================================================
-- PHASE 1 MIGRATION COMPLETE
-- ============================================================================

