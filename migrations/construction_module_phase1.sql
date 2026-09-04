-- ============================================================================
-- CONSTRUCTION MODULE — Phase 1 (MVP)
-- For: Madar Alwadi Building Contracting
-- ============================================================================
-- Adds 'construction' business type and creates all co_* tables
-- Run: mysql -u root herosysgro < migrations/construction_module_phase1.sql
-- ============================================================================

SET FOREIGN_KEY_CHECKS = 0;
SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";

-- Add 'construction' to companies.business_type
ALTER TABLE `companies` 
MODIFY COLUMN `business_type` ENUM('cleaning', 'realestate', 'supermarket', 'restaurant', 'construction') NOT NULL;

-- ============================================================================
-- co_clients (for CLIENT projects)
-- ============================================================================
CREATE TABLE IF NOT EXISTS `co_clients` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `company_id` INT(11) NOT NULL,
  `client_name` VARCHAR(255) NOT NULL,
  `contact_person` VARCHAR(255) DEFAULT NULL,
  `email` VARCHAR(255) DEFAULT NULL,
  `phone` VARCHAR(100) DEFAULT NULL,
  `address` TEXT DEFAULT NULL,
  `tax_number` VARCHAR(100) DEFAULT NULL,
  `notes` TEXT DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_company` (`company_id`),
  FOREIGN KEY (`company_id`) REFERENCES `companies`(`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ============================================================================
-- co_projects
-- ============================================================================
CREATE TABLE IF NOT EXISTS `co_projects` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `company_id` INT(11) NOT NULL,
  `project_code` VARCHAR(50) NOT NULL,
  `project_name` VARCHAR(255) NOT NULL,
  `project_type` ENUM('OWNER','CLIENT') NOT NULL,
  `location` VARCHAR(255) DEFAULT NULL,
  `start_date` DATE DEFAULT NULL,
  `expected_completion_date` DATE DEFAULT NULL,
  `project_manager_id` INT(11) DEFAULT NULL,
  `status` ENUM('draft','active','on_hold','completed','cancelled') NOT NULL DEFAULT 'draft',
  `approved_budget` DECIMAL(15,2) DEFAULT NULL,
  `contractor_contract_value` DECIMAL(15,2) DEFAULT NULL,
  `client_id` INT(11) DEFAULT NULL,
  `contract_value` DECIMAL(15,2) DEFAULT NULL,
  `notes` TEXT DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `created_by` INT(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_company_project_code` (`company_id`, `project_code`),
  KEY `idx_company` (`company_id`),
  KEY `idx_status` (`status`),
  KEY `idx_project_type` (`project_type`),
  KEY `idx_project_manager` (`project_manager_id`),
  KEY `idx_client` (`client_id`),
  FOREIGN KEY (`company_id`) REFERENCES `companies`(`id`) ON DELETE RESTRICT,
  FOREIGN KEY (`project_manager_id`) REFERENCES `employees`(`id`) ON DELETE SET NULL,
  FOREIGN KEY (`client_id`) REFERENCES `co_clients`(`id`) ON DELETE SET NULL,
  FOREIGN KEY (`created_by`) REFERENCES `user`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ============================================================================
-- co_contractors
-- ============================================================================
CREATE TABLE IF NOT EXISTS `co_contractors` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `company_id` INT(11) NOT NULL,
  `contractor_name` VARCHAR(255) NOT NULL,
  `contact_person` VARCHAR(255) DEFAULT NULL,
  `email` VARCHAR(255) DEFAULT NULL,
  `phone` VARCHAR(100) DEFAULT NULL,
  `address` TEXT DEFAULT NULL,
  `tax_number` VARCHAR(100) DEFAULT NULL,
  `bank_details` TEXT DEFAULT NULL,
  `notes` TEXT DEFAULT NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_company` (`company_id`),
  KEY `idx_active` (`is_active`),
  FOREIGN KEY (`company_id`) REFERENCES `companies`(`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ============================================================================
-- co_project_contractors (link contractor to project)
-- ============================================================================
CREATE TABLE IF NOT EXISTS `co_project_contractors` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `company_id` INT(11) NOT NULL,
  `project_id` INT(11) NOT NULL,
  `contractor_id` INT(11) NOT NULL,
  `contract_value` DECIMAL(15,2) NOT NULL,
  `retention_pct` DECIMAL(5,2) NOT NULL DEFAULT 0.00,
  `start_date` DATE DEFAULT NULL,
  `end_date` DATE DEFAULT NULL,
  `status` VARCHAR(50) DEFAULT 'active',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_company` (`company_id`),
  KEY `idx_project` (`project_id`),
  KEY `idx_contractor` (`contractor_id`),
  FOREIGN KEY (`company_id`) REFERENCES `companies`(`id`) ON DELETE RESTRICT,
  FOREIGN KEY (`project_id`) REFERENCES `co_projects`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`contractor_id`) REFERENCES `co_contractors`(`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ============================================================================
-- co_contractor_payments (progress payments)
-- ============================================================================
CREATE TABLE IF NOT EXISTS `co_contractor_payments` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `company_id` INT(11) NOT NULL,
  `project_contractor_id` INT(11) NOT NULL,
  `payment_date` DATE NOT NULL,
  `amount` DECIMAL(15,2) NOT NULL,
  `retention_held` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  `net_paid` DECIMAL(15,2) NOT NULL,
  `journal_id` INT(11) DEFAULT NULL,
  `reference` VARCHAR(255) DEFAULT NULL,
  `notes` TEXT DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `created_by` INT(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_company` (`company_id`),
  KEY `idx_project_contractor` (`project_contractor_id`),
  KEY `idx_journal` (`journal_id`),
  FOREIGN KEY (`company_id`) REFERENCES `companies`(`id`) ON DELETE RESTRICT,
  FOREIGN KEY (`project_contractor_id`) REFERENCES `co_project_contractors`(`id`) ON DELETE RESTRICT,
  FOREIGN KEY (`journal_id`) REFERENCES `re_journal_headers`(`id`) ON DELETE SET NULL,
  FOREIGN KEY (`created_by`) REFERENCES `user`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ============================================================================
-- co_project_cost_categories
-- ============================================================================
CREATE TABLE IF NOT EXISTS `co_project_cost_categories` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `company_id` INT(11) NOT NULL,
  `code` VARCHAR(50) NOT NULL,
  `name` VARCHAR(255) NOT NULL,
  `category_type` ENUM('materials','labor','subcontractor','equipment','miscellaneous') NOT NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_company_code` (`company_id`, `code`),
  KEY `idx_company` (`company_id`),
  FOREIGN KEY (`company_id`) REFERENCES `companies`(`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ============================================================================
-- co_project_costs
-- ============================================================================
CREATE TABLE IF NOT EXISTS `co_project_costs` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `company_id` INT(11) NOT NULL,
  `project_id` INT(11) NOT NULL,
  `cost_date` DATE NOT NULL,
  `category_id` INT(11) DEFAULT NULL,
  `amount` DECIMAL(15,2) NOT NULL,
  `cost_type` ENUM('materials','labor','subcontractor','equipment','miscellaneous') NOT NULL,
  `vendor_id` INT(11) DEFAULT NULL,
  `contractor_id` INT(11) DEFAULT NULL,
  `employee_id` INT(11) DEFAULT NULL,
  `description` VARCHAR(500) DEFAULT NULL,
  `reference` VARCHAR(255) DEFAULT NULL,
  `journal_id` INT(11) DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `created_by` INT(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_company` (`company_id`),
  KEY `idx_project` (`project_id`),
  KEY `idx_category` (`category_id`),
  KEY `idx_journal` (`journal_id`),
  FOREIGN KEY (`company_id`) REFERENCES `companies`(`id`) ON DELETE RESTRICT,
  FOREIGN KEY (`project_id`) REFERENCES `co_projects`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`category_id`) REFERENCES `co_project_cost_categories`(`id`) ON DELETE SET NULL,
  FOREIGN KEY (`contractor_id`) REFERENCES `co_contractors`(`id`) ON DELETE SET NULL,
  FOREIGN KEY (`employee_id`) REFERENCES `employees`(`id`) ON DELETE SET NULL,
  FOREIGN KEY (`journal_id`) REFERENCES `re_journal_headers`(`id`) ON DELETE SET NULL,
  FOREIGN KEY (`created_by`) REFERENCES `user`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ============================================================================
-- co_material_issues
-- ============================================================================
CREATE TABLE IF NOT EXISTS `co_material_issues` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `company_id` INT(11) NOT NULL,
  `project_id` INT(11) NOT NULL,
  `issue_date` DATE NOT NULL,
  `item_code` VARCHAR(100) DEFAULT NULL,
  `item_name` VARCHAR(255) NOT NULL,
  `quantity` DECIMAL(15,4) NOT NULL DEFAULT 1.0000,
  `unit_cost` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  `total_cost` DECIMAL(15,2) NOT NULL,
  `cost_posted` TINYINT(1) NOT NULL DEFAULT 0,
  `journal_id` INT(11) DEFAULT NULL,
  `notes` TEXT DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `created_by` INT(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_company` (`company_id`),
  KEY `idx_project` (`project_id`),
  KEY `idx_journal` (`journal_id`),
  FOREIGN KEY (`company_id`) REFERENCES `companies`(`id`) ON DELETE RESTRICT,
  FOREIGN KEY (`project_id`) REFERENCES `co_projects`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`journal_id`) REFERENCES `re_journal_headers`(`id`) ON DELETE SET NULL,
  FOREIGN KEY (`created_by`) REFERENCES `user`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ============================================================================
-- co_project_labor
-- ============================================================================
CREATE TABLE IF NOT EXISTS `co_project_labor` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `company_id` INT(11) NOT NULL,
  `project_id` INT(11) NOT NULL,
  `employee_id` INT(11) NOT NULL,
  `role` ENUM('labor','engineer','supervisor') NOT NULL DEFAULT 'labor',
  `from_date` DATE NOT NULL,
  `to_date` DATE DEFAULT NULL,
  `daily_rate` DECIMAL(15,2) DEFAULT NULL,
  `hours_worked` DECIMAL(8,2) DEFAULT NULL,
  `cost_amount` DECIMAL(15,2) DEFAULT NULL,
  `posted_to_cost` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `created_by` INT(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_company` (`company_id`),
  KEY `idx_project` (`project_id`),
  KEY `idx_employee` (`employee_id`),
  FOREIGN KEY (`company_id`) REFERENCES `companies`(`id`) ON DELETE RESTRICT,
  FOREIGN KEY (`project_id`) REFERENCES `co_projects`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`employee_id`) REFERENCES `employees`(`id`) ON DELETE RESTRICT,
  FOREIGN KEY (`created_by`) REFERENCES `user`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ============================================================================
-- co_client_invoices (CLIENT projects only)
-- ============================================================================
CREATE TABLE IF NOT EXISTS `co_client_invoices` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `company_id` INT(11) NOT NULL,
  `project_id` INT(11) NOT NULL,
  `client_id` INT(11) NOT NULL,
  `invoice_number` VARCHAR(100) NOT NULL,
  `invoice_date` DATE NOT NULL,
  `total_amount` DECIMAL(15,2) NOT NULL,
  `vat_amount` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  `status` ENUM('draft','sent','paid','partial','cancelled') NOT NULL DEFAULT 'draft',
  `journal_id` INT(11) DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `created_by` INT(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_company_invoice_number` (`company_id`, `invoice_number`),
  KEY `idx_company` (`company_id`),
  KEY `idx_project` (`project_id`),
  KEY `idx_client` (`client_id`),
  KEY `idx_journal` (`journal_id`),
  FOREIGN KEY (`company_id`) REFERENCES `companies`(`id`) ON DELETE RESTRICT,
  FOREIGN KEY (`project_id`) REFERENCES `co_projects`(`id`) ON DELETE RESTRICT,
  FOREIGN KEY (`client_id`) REFERENCES `co_clients`(`id`) ON DELETE RESTRICT,
  FOREIGN KEY (`journal_id`) REFERENCES `re_journal_headers`(`id`) ON DELETE SET NULL,
  FOREIGN KEY (`created_by`) REFERENCES `user`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ============================================================================
-- co_client_invoice_lines
-- ============================================================================
CREATE TABLE IF NOT EXISTS `co_client_invoice_lines` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `company_id` INT(11) NOT NULL,
  `invoice_id` INT(11) NOT NULL,
  `line_number` INT(11) NOT NULL,
  `description` VARCHAR(500) NOT NULL,
  `amount` DECIMAL(15,2) NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_company` (`company_id`),
  KEY `idx_invoice` (`invoice_id`),
  FOREIGN KEY (`company_id`) REFERENCES `companies`(`id`) ON DELETE RESTRICT,
  FOREIGN KEY (`invoice_id`) REFERENCES `co_client_invoices`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ============================================================================
-- co_client_payments
-- ============================================================================
CREATE TABLE IF NOT EXISTS `co_client_payments` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `company_id` INT(11) NOT NULL,
  `invoice_id` INT(11) NOT NULL,
  `payment_date` DATE NOT NULL,
  `amount` DECIMAL(15,2) NOT NULL,
  `journal_id` INT(11) DEFAULT NULL,
  `reference` VARCHAR(255) DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `created_by` INT(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_company` (`company_id`),
  KEY `idx_invoice` (`invoice_id`),
  KEY `idx_journal` (`journal_id`),
  FOREIGN KEY (`company_id`) REFERENCES `companies`(`id`) ON DELETE RESTRICT,
  FOREIGN KEY (`invoice_id`) REFERENCES `co_client_invoices`(`id`) ON DELETE RESTRICT,
  FOREIGN KEY (`journal_id`) REFERENCES `re_journal_headers`(`id`) ON DELETE SET NULL,
  FOREIGN KEY (`created_by`) REFERENCES `user`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

SET FOREIGN_KEY_CHECKS = 1;
