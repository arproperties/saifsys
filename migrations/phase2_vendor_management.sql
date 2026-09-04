-- Phase 2: Vendor Management System
-- Contractors and service agreements management

-- ============================================================================
-- 1. Vendors
-- ============================================================================
-- Vendor/contractor master data
CREATE TABLE IF NOT EXISTS `re_vendors` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `company_id` INT(11) NOT NULL,
  `vendor_name` VARCHAR(255) NOT NULL,
  `vendor_type` ENUM('contractor', 'supplier', 'service_provider', 'maintenance', 'cleaning', 'security', 'other') NOT NULL DEFAULT 'contractor',
  `contact_person` VARCHAR(255) DEFAULT NULL,
  `email` VARCHAR(255) DEFAULT NULL,
  `phone` VARCHAR(50) DEFAULT NULL,
  `mobile` VARCHAR(50) DEFAULT NULL,
  `address` TEXT DEFAULT NULL,
  `city` VARCHAR(100) DEFAULT NULL,
  `state` VARCHAR(100) DEFAULT NULL,
  `country` VARCHAR(100) DEFAULT NULL,
  `postal_code` VARCHAR(20) DEFAULT NULL,
  `tax_id` VARCHAR(100) DEFAULT NULL,
  `license_number` VARCHAR(100) DEFAULT NULL,
  `license_expiry` DATE DEFAULT NULL,
  `insurance_provider` VARCHAR(255) DEFAULT NULL,
  `insurance_policy_number` VARCHAR(100) DEFAULT NULL,
  `insurance_expiry` DATE DEFAULT NULL,
  `payment_terms` VARCHAR(100) DEFAULT NULL COMMENT 'e.g., Net 30, COD, etc.',
  `bank_name` VARCHAR(255) DEFAULT NULL,
  `bank_account_number` VARCHAR(100) DEFAULT NULL,
  `bank_iban` VARCHAR(100) DEFAULT NULL,
  `rating` DECIMAL(3,2) DEFAULT NULL COMMENT 'Average rating 0-5',
  `total_jobs` INT(11) NOT NULL DEFAULT 0,
  `total_spent` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  `status` ENUM('active', 'inactive', 'suspended', 'blacklisted') NOT NULL DEFAULT 'active',
  `notes` TEXT DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_company` (`company_id`),
  KEY `idx_vendor_type` (`vendor_type`),
  KEY `idx_status` (`status`),
  KEY `idx_rating` (`rating`),
  FOREIGN KEY (`company_id`) REFERENCES `companies`(`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ============================================================================
-- 2. Service Agreements
-- ============================================================================
-- Service agreements/contracts with vendors
CREATE TABLE IF NOT EXISTS `re_service_agreements` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `company_id` INT(11) NOT NULL,
  `vendor_id` INT(11) NOT NULL,
  `agreement_number` VARCHAR(100) NOT NULL,
  `agreement_name` VARCHAR(255) NOT NULL,
  `service_type` ENUM('maintenance', 'cleaning', 'security', 'landscaping', 'plumbing', 'electrical', 'hvac', 'pest_control', 'waste_management', 'other') NOT NULL,
  `start_date` DATE NOT NULL,
  `end_date` DATE DEFAULT NULL COMMENT 'NULL for ongoing agreements',
  `renewal_date` DATE DEFAULT NULL,
  `auto_renew` TINYINT(1) NOT NULL DEFAULT 0,
  `billing_frequency` ENUM('one_time', 'monthly', 'quarterly', 'semi_annual', 'annual') NOT NULL DEFAULT 'monthly',
  `contract_value` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  `currency` VARCHAR(3) DEFAULT 'AED',
  `payment_terms` VARCHAR(100) DEFAULT NULL,
  `scope_of_work` TEXT DEFAULT NULL,
  `terms_and_conditions` TEXT DEFAULT NULL,
  `sla_requirements` TEXT DEFAULT NULL COMMENT 'Service level agreement requirements',
  `penalty_clauses` TEXT DEFAULT NULL,
  `status` ENUM('draft', 'active', 'expired', 'terminated', 'renewed') NOT NULL DEFAULT 'draft',
  `signed_date` DATE DEFAULT NULL,
  `signed_by` VARCHAR(255) DEFAULT NULL,
  `document_path` VARCHAR(500) DEFAULT NULL COMMENT 'Path to signed agreement document',
  `notes` TEXT DEFAULT NULL,
  `created_by` INT(11) DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_company_agreement_number` (`company_id`, `agreement_number`),
  KEY `idx_company` (`company_id`),
  KEY `idx_vendor` (`vendor_id`),
  KEY `idx_service_type` (`service_type`),
  KEY `idx_status` (`status`),
  KEY `idx_start_date` (`start_date`),
  KEY `idx_end_date` (`end_date`),
  FOREIGN KEY (`company_id`) REFERENCES `companies`(`id`) ON DELETE RESTRICT,
  FOREIGN KEY (`vendor_id`) REFERENCES `re_vendors`(`id`) ON DELETE RESTRICT,
  FOREIGN KEY (`created_by`) REFERENCES `user`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ============================================================================
-- 3. Vendor Services
-- ============================================================================
-- Services provided by vendors (for agreements)
CREATE TABLE IF NOT EXISTS `re_vendor_services` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `company_id` INT(11) NOT NULL,
  `agreement_id` INT(11) NOT NULL,
  `service_name` VARCHAR(255) NOT NULL,
  `service_description` TEXT DEFAULT NULL,
  `unit_price` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `quantity` DECIMAL(10,2) DEFAULT 1.00,
  `unit` VARCHAR(50) DEFAULT NULL COMMENT 'e.g., hour, sqm, unit, etc.',
  `frequency` VARCHAR(100) DEFAULT NULL COMMENT 'e.g., daily, weekly, monthly',
  `total_amount` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  `notes` TEXT DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_company` (`company_id`),
  KEY `idx_agreement` (`agreement_id`),
  FOREIGN KEY (`company_id`) REFERENCES `companies`(`id`) ON DELETE RESTRICT,
  FOREIGN KEY (`agreement_id`) REFERENCES `re_service_agreements`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ============================================================================
-- 4. Vendor Performance
-- ============================================================================
-- Performance tracking and ratings for vendors
CREATE TABLE IF NOT EXISTS `re_vendor_performance` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `company_id` INT(11) NOT NULL,
  `vendor_id` INT(11) NOT NULL,
  `agreement_id` INT(11) DEFAULT NULL,
  `maintenance_request_id` INT(11) DEFAULT NULL,
  `task_id` INT(11) DEFAULT NULL,
  `performance_date` DATE NOT NULL,
  `rating` INT(11) NOT NULL COMMENT '1-5 rating',
  `quality_score` INT(11) DEFAULT NULL COMMENT '1-10 quality score',
  `timeliness_score` INT(11) DEFAULT NULL COMMENT '1-10 timeliness score',
  `communication_score` INT(11) DEFAULT NULL COMMENT '1-10 communication score',
  `cost_effectiveness_score` INT(11) DEFAULT NULL COMMENT '1-10 cost effectiveness score',
  `overall_score` DECIMAL(5,2) DEFAULT NULL COMMENT 'Calculated average score',
  `comments` TEXT DEFAULT NULL,
  `issues_encountered` TEXT DEFAULT NULL,
  `recommendations` TEXT DEFAULT NULL,
  `reviewed_by` INT(11) DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_company` (`company_id`),
  KEY `idx_vendor` (`vendor_id`),
  KEY `idx_agreement` (`agreement_id`),
  KEY `idx_maintenance_request` (`maintenance_request_id`),
  KEY `idx_task` (`task_id`),
  KEY `idx_performance_date` (`performance_date`),
  KEY `idx_rating` (`rating`),
  FOREIGN KEY (`company_id`) REFERENCES `companies`(`id`) ON DELETE RESTRICT,
  FOREIGN KEY (`vendor_id`) REFERENCES `re_vendors`(`id`) ON DELETE RESTRICT,
  FOREIGN KEY (`agreement_id`) REFERENCES `re_service_agreements`(`id`) ON DELETE SET NULL,
  FOREIGN KEY (`maintenance_request_id`) REFERENCES `re_maintenance_requests`(`id`) ON DELETE SET NULL,
  FOREIGN KEY (`task_id`) REFERENCES `re_tasks`(`id`) ON DELETE SET NULL,
  FOREIGN KEY (`reviewed_by`) REFERENCES `user`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ============================================================================
-- 5. Vendor Invoices
-- ============================================================================
-- Invoices from vendors
CREATE TABLE IF NOT EXISTS `re_vendor_invoices` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `company_id` INT(11) NOT NULL,
  `vendor_id` INT(11) NOT NULL,
  `agreement_id` INT(11) DEFAULT NULL,
  `invoice_number` VARCHAR(100) NOT NULL,
  `invoice_date` DATE NOT NULL,
  `due_date` DATE NOT NULL,
  `subtotal` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  `tax_amount` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  `discount_amount` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  `total_amount` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  `currency` VARCHAR(3) DEFAULT 'AED',
  `status` ENUM('pending', 'approved', 'paid', 'rejected', 'cancelled') NOT NULL DEFAULT 'pending',
  `payment_date` DATE DEFAULT NULL,
  `payment_method` VARCHAR(50) DEFAULT NULL,
  `payment_reference` VARCHAR(100) DEFAULT NULL,
  `notes` TEXT DEFAULT NULL,
  `document_path` VARCHAR(500) DEFAULT NULL,
  `created_by` INT(11) DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_company_invoice_number` (`company_id`, `invoice_number`),
  KEY `idx_company` (`company_id`),
  KEY `idx_vendor` (`vendor_id`),
  KEY `idx_agreement` (`agreement_id`),
  KEY `idx_status` (`status`),
  KEY `idx_invoice_date` (`invoice_date`),
  KEY `idx_due_date` (`due_date`),
  FOREIGN KEY (`company_id`) REFERENCES `companies`(`id`) ON DELETE RESTRICT,
  FOREIGN KEY (`vendor_id`) REFERENCES `re_vendors`(`id`) ON DELETE RESTRICT,
  FOREIGN KEY (`agreement_id`) REFERENCES `re_service_agreements`(`id`) ON DELETE SET NULL,
  FOREIGN KEY (`created_by`) REFERENCES `user`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ============================================================================
-- 6. Vendor Invoice Items
-- ============================================================================
-- Line items for vendor invoices
CREATE TABLE IF NOT EXISTS `re_vendor_invoice_items` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `company_id` INT(11) NOT NULL,
  `invoice_id` INT(11) NOT NULL,
  `service_name` VARCHAR(255) NOT NULL,
  `description` TEXT DEFAULT NULL,
  `quantity` DECIMAL(10,2) NOT NULL DEFAULT 1.00,
  `unit_price` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `total_price` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_company` (`company_id`),
  KEY `idx_invoice` (`invoice_id`),
  FOREIGN KEY (`company_id`) REFERENCES `companies`(`id`) ON DELETE RESTRICT,
  FOREIGN KEY (`invoice_id`) REFERENCES `re_vendor_invoices`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ============================================================================
-- 7. Vendor Documents
-- ============================================================================
-- Documents related to vendors (licenses, insurance, contracts, etc.)
CREATE TABLE IF NOT EXISTS `re_vendor_documents` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `company_id` INT(11) NOT NULL,
  `vendor_id` INT(11) DEFAULT NULL,
  `agreement_id` INT(11) DEFAULT NULL,
  `document_type` ENUM('license', 'insurance', 'contract', 'invoice', 'certificate', 'other') NOT NULL,
  `document_name` VARCHAR(255) NOT NULL,
  `file_name` VARCHAR(255) NOT NULL,
  `file_path` VARCHAR(500) NOT NULL,
  `file_size` INT(11) NOT NULL,
  `mime_type` VARCHAR(100) DEFAULT NULL,
  `expiry_date` DATE DEFAULT NULL,
  `notes` TEXT DEFAULT NULL,
  `uploaded_by` INT(11) DEFAULT NULL,
  `uploaded_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_company` (`company_id`),
  KEY `idx_vendor` (`vendor_id`),
  KEY `idx_agreement` (`agreement_id`),
  KEY `idx_document_type` (`document_type`),
  KEY `idx_expiry_date` (`expiry_date`),
  FOREIGN KEY (`company_id`) REFERENCES `companies`(`id`) ON DELETE RESTRICT,
  FOREIGN KEY (`vendor_id`) REFERENCES `re_vendors`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`agreement_id`) REFERENCES `re_service_agreements`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`uploaded_by`) REFERENCES `user`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Add vendor_id to maintenance_requests for tracking
ALTER TABLE `re_maintenance_requests`
ADD COLUMN `vendor_id` INT(11) DEFAULT NULL AFTER `assigned_to`,
ADD KEY `idx_vendor` (`vendor_id`),
ADD FOREIGN KEY (`vendor_id`) REFERENCES `re_vendors`(`id`) ON DELETE SET NULL;

