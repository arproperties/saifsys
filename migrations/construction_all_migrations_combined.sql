-- ============================================================================
-- CONSTRUCTION MODULE — ALL MIGRATIONS COMBINED (single file)
-- ============================================================================
-- Run this ONCE on the live server (e.g. copy-paste into phpMyAdmin SQL tab,
-- or: mysql -u USER -p DATABASE < construction_all_migrations_combined.sql).
-- If you re-run and get errors on "Duplicate column" or "Duplicate key", comment
-- out sections 2 (contractor bank fields) and 8 (subcontractors).
--
-- Prerequisites: companies, employees, user tables exist. For GL: re_chart_of_accounts,
-- re_journal_headers (Real Estate/accounting) should exist first.
--
-- Before running: In section 14 (Chart of Accounts), set @company_id to your
-- construction company ID, or skip that section and use Setup Accounts in the app.
-- ============================================================================

SET FOREIGN_KEY_CHECKS = 0;
SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";

-- ############################################################################
-- 1. construction_module_phase1.sql — Core tables
-- ############################################################################

-- Add 'construction' to companies.business_type
ALTER TABLE `companies`
MODIFY COLUMN `business_type` ENUM('cleaning', 'realestate', 'supermarket', 'restaurant', 'construction') NOT NULL;

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

-- ############################################################################
-- 2. construction_contractor_bank_fields.sql
-- ############################################################################

ALTER TABLE `co_contractors`
  ADD COLUMN `bank_name` VARCHAR(255) DEFAULT NULL AFTER `tax_number`,
  ADD COLUMN `account_number` VARCHAR(100) DEFAULT NULL AFTER `bank_name`,
  ADD COLUMN `iban` VARCHAR(50) DEFAULT NULL AFTER `account_number`,
  ADD COLUMN `swift_code` VARCHAR(20) DEFAULT NULL AFTER `iban`;

-- ############################################################################
-- 3. construction_phase2_project_phases.sql
-- ############################################################################

CREATE TABLE IF NOT EXISTS `co_project_phases` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `company_id` INT(11) NOT NULL,
  `project_id` INT(11) NOT NULL,
  `phase_name` VARCHAR(255) NOT NULL,
  `sequence` INT(11) NOT NULL DEFAULT 0,
  `planned_start_date` DATE DEFAULT NULL,
  `planned_end_date` DATE DEFAULT NULL,
  `actual_start_date` DATE DEFAULT NULL,
  `actual_end_date` DATE DEFAULT NULL,
  `percent_complete` DECIMAL(5,2) NOT NULL DEFAULT 0.00,
  `status` ENUM('not_started','in_progress','completed') NOT NULL DEFAULT 'not_started',
  `notes` TEXT DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_company` (`company_id`),
  KEY `idx_project` (`project_id`),
  KEY `idx_status` (`status`),
  FOREIGN KEY (`company_id`) REFERENCES `companies`(`id`) ON DELETE RESTRICT,
  FOREIGN KEY (`project_id`) REFERENCES `co_projects`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ############################################################################
-- 4. construction_phase2_variation_orders.sql
-- ############################################################################

CREATE TABLE IF NOT EXISTS `co_variation_orders` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `company_id` INT(11) NOT NULL,
  `project_id` INT(11) NOT NULL,
  `project_contractor_id` INT(11) DEFAULT NULL,
  `vo_number` VARCHAR(50) NOT NULL,
  `description` VARCHAR(500) NOT NULL,
  `amount` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  `status` ENUM('draft','approved','rejected') NOT NULL DEFAULT 'draft',
  `vo_date` DATE NOT NULL,
  `approved_date` DATE DEFAULT NULL,
  `notes` TEXT DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `created_by` INT(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_company_vo_number` (`company_id`, `vo_number`),
  KEY `idx_company` (`company_id`),
  KEY `idx_project` (`project_id`),
  KEY `idx_project_contractor` (`project_contractor_id`),
  KEY `idx_status` (`status`),
  FOREIGN KEY (`company_id`) REFERENCES `companies`(`id`) ON DELETE RESTRICT,
  FOREIGN KEY (`project_id`) REFERENCES `co_projects`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`project_contractor_id`) REFERENCES `co_project_contractors`(`id`) ON DELETE SET NULL,
  FOREIGN KEY (`created_by`) REFERENCES `user`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ############################################################################
-- 5. construction_phase2_documents.sql
-- ############################################################################

CREATE TABLE IF NOT EXISTS `co_documents` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `company_id` INT(11) NOT NULL,
  `project_id` INT(11) DEFAULT NULL,
  `contractor_id` INT(11) DEFAULT NULL,
  `doc_type` ENUM('contract','drawing','permit','invoice','other') NOT NULL DEFAULT 'other',
  `title` VARCHAR(255) NOT NULL,
  `file_path` VARCHAR(500) NOT NULL,
  `notes` TEXT DEFAULT NULL,
  `uploaded_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `uploaded_by` INT(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_company` (`company_id`),
  KEY `idx_project` (`project_id`),
  KEY `idx_contractor` (`contractor_id`),
  FOREIGN KEY (`company_id`) REFERENCES `companies`(`id`) ON DELETE RESTRICT,
  FOREIGN KEY (`project_id`) REFERENCES `co_projects`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`contractor_id`) REFERENCES `co_contractors`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`uploaded_by`) REFERENCES `user`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ############################################################################
-- 6. construction_phase2_work_orders.sql
-- ############################################################################

CREATE TABLE IF NOT EXISTS `co_work_orders` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `company_id` INT(11) NOT NULL,
  `project_id` INT(11) NOT NULL,
  `project_contractor_id` INT(11) NOT NULL,
  `work_order_number` VARCHAR(50) NOT NULL,
  `description` VARCHAR(500) NOT NULL,
  `amount` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  `status` ENUM('draft','issued','in_progress','completed','cancelled') NOT NULL DEFAULT 'draft',
  `start_date` DATE DEFAULT NULL,
  `end_date` DATE DEFAULT NULL,
  `notes` TEXT DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `created_by` INT(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_company_wo_number` (`company_id`, `work_order_number`),
  KEY `idx_company` (`company_id`),
  KEY `idx_project` (`project_id`),
  KEY `idx_project_contractor` (`project_contractor_id`),
  FOREIGN KEY (`company_id`) REFERENCES `companies`(`id`) ON DELETE RESTRICT,
  FOREIGN KEY (`project_id`) REFERENCES `co_projects`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`project_contractor_id`) REFERENCES `co_project_contractors`(`id`) ON DELETE RESTRICT,
  FOREIGN KEY (`created_by`) REFERENCES `user`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ############################################################################
-- 7. construction_phase2_retention_releases.sql
-- ############################################################################

CREATE TABLE IF NOT EXISTS `co_retention_releases` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `company_id` INT(11) NOT NULL,
  `project_contractor_id` INT(11) NOT NULL,
  `release_date` DATE NOT NULL,
  `amount` DECIMAL(15,2) NOT NULL,
  `journal_id` INT(11) DEFAULT NULL,
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

-- ############################################################################
-- 8. construction_subcontractors.sql
-- ############################################################################

ALTER TABLE `co_project_contractors`
  ADD COLUMN `parent_project_contractor_id` INT(11) DEFAULT NULL AFTER `contractor_id`,
  ADD COLUMN `coordination_fee` DECIMAL(15,2) DEFAULT NULL COMMENT 'Amount owner pays to main contractor (AED) for his coordination/mobilization of this subcontractor' AFTER `contract_value`;

ALTER TABLE `co_project_contractors`
  ADD KEY `idx_parent` (`parent_project_contractor_id`);

ALTER TABLE `co_project_contractors`
  ADD CONSTRAINT `fk_pc_parent` FOREIGN KEY (`parent_project_contractor_id`) REFERENCES `co_project_contractors` (`id`) ON DELETE SET NULL ON UPDATE CASCADE;

-- ############################################################################
-- 9. construction_phase3_submittals_rfis.sql
-- ############################################################################

CREATE TABLE IF NOT EXISTS `co_submittals` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `company_id` INT(11) NOT NULL,
  `project_id` INT(11) NOT NULL,
  `contractor_id` INT(11) DEFAULT NULL,
  `submittal_number` VARCHAR(50) NOT NULL,
  `title` VARCHAR(255) NOT NULL,
  `description` TEXT DEFAULT NULL,
  `submittal_type` ENUM('drawing','specification','sample','other') NOT NULL DEFAULT 'other',
  `status` ENUM('draft','submitted','under_review','approved','rejected','revised') NOT NULL DEFAULT 'draft',
  `due_date` DATE DEFAULT NULL,
  `submitted_date` DATE DEFAULT NULL,
  `response_date` DATE DEFAULT NULL,
  `response_notes` TEXT DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `created_by` INT(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_company_submittal_number` (`company_id`, `submittal_number`),
  KEY `idx_company` (`company_id`),
  KEY `idx_project` (`project_id`),
  KEY `idx_contractor` (`contractor_id`),
  KEY `idx_status` (`status`),
  FOREIGN KEY (`company_id`) REFERENCES `companies`(`id`) ON DELETE RESTRICT,
  FOREIGN KEY (`project_id`) REFERENCES `co_projects`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`contractor_id`) REFERENCES `co_contractors`(`id`) ON DELETE SET NULL,
  FOREIGN KEY (`created_by`) REFERENCES `user`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `co_rfis` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `company_id` INT(11) NOT NULL,
  `project_id` INT(11) NOT NULL,
  `project_contractor_id` INT(11) DEFAULT NULL,
  `rfi_number` VARCHAR(50) NOT NULL,
  `subject` VARCHAR(255) NOT NULL,
  `description` TEXT DEFAULT NULL,
  `status` ENUM('open','answered','closed') NOT NULL DEFAULT 'open',
  `issued_date` DATE NOT NULL,
  `due_date` DATE DEFAULT NULL,
  `answered_date` DATE DEFAULT NULL,
  `response` TEXT DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `created_by` INT(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_company_rfi_number` (`company_id`, `rfi_number`),
  KEY `idx_company` (`company_id`),
  KEY `idx_project` (`project_id`),
  KEY `idx_project_contractor` (`project_contractor_id`),
  KEY `idx_status` (`status`),
  FOREIGN KEY (`company_id`) REFERENCES `companies`(`id`) ON DELETE RESTRICT,
  FOREIGN KEY (`project_id`) REFERENCES `co_projects`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`project_contractor_id`) REFERENCES `co_project_contractors`(`id`) ON DELETE SET NULL,
  FOREIGN KEY (`created_by`) REFERENCES `user`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ############################################################################
-- 10. construction_suppliers_phase1.sql
-- ############################################################################

CREATE TABLE IF NOT EXISTS `co_suppliers` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `company_id` INT(11) NOT NULL,
  `supplier_name` VARCHAR(255) NOT NULL,
  `contact_person` VARCHAR(255) DEFAULT NULL,
  `email` VARCHAR(255) DEFAULT NULL,
  `phone` VARCHAR(100) DEFAULT NULL,
  `address` TEXT DEFAULT NULL,
  `tax_number` VARCHAR(100) DEFAULT NULL,
  `vat_number` VARCHAR(100) DEFAULT NULL COMMENT 'VAT registration number',
  `bank_name` VARCHAR(255) DEFAULT NULL,
  `account_number` VARCHAR(100) DEFAULT NULL,
  `iban` VARCHAR(100) DEFAULT NULL,
  `swift_code` VARCHAR(50) DEFAULT NULL,
  `bank_details` TEXT DEFAULT NULL,
  `notes` TEXT DEFAULT NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_company` (`company_id`),
  KEY `idx_active` (`is_active`),
  FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ############################################################################
-- 11. construction_supplier_invoices_phase2.sql
-- ############################################################################

CREATE TABLE IF NOT EXISTS `co_supplier_invoices` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `company_id` INT(11) NOT NULL,
  `supplier_id` INT(11) NOT NULL,
  `project_id` INT(11) DEFAULT NULL,
  `invoice_number` VARCHAR(100) NOT NULL,
  `invoice_date` DATE NOT NULL,
  `due_date` DATE DEFAULT NULL,
  `subtotal` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  `vat_pct` DECIMAL(5,2) NOT NULL DEFAULT 0.00,
  `vat_amount` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  `total` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  `description` VARCHAR(500) DEFAULT NULL,
  `reference` VARCHAR(100) DEFAULT NULL,
  `journal_id` INT(11) DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `created_by` INT(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_company` (`company_id`),
  KEY `idx_supplier` (`supplier_id`),
  KEY `idx_project` (`project_id`),
  KEY `idx_invoice_date` (`invoice_date`),
  KEY `idx_journal` (`journal_id`),
  UNIQUE KEY `uq_company_supplier_inv` (`company_id`, `supplier_id`, `invoice_number`),
  FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE RESTRICT,
  FOREIGN KEY (`supplier_id`) REFERENCES `co_suppliers` (`id`) ON DELETE RESTRICT,
  FOREIGN KEY (`project_id`) REFERENCES `co_projects` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ############################################################################
-- 12. construction_supplier_payments_phase3.sql
-- ############################################################################

CREATE TABLE IF NOT EXISTS `co_supplier_payments` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `company_id` INT(11) NOT NULL,
  `supplier_id` INT(11) NOT NULL,
  `payment_date` DATE NOT NULL,
  `amount` DECIMAL(15,2) NOT NULL,
  `reference` VARCHAR(100) DEFAULT NULL,
  `journal_id` INT(11) DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `created_by` INT(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_company` (`company_id`),
  KEY `idx_supplier` (`supplier_id`),
  KEY `idx_payment_date` (`payment_date`),
  KEY `idx_journal` (`journal_id`),
  FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE RESTRICT,
  FOREIGN KEY (`supplier_id`) REFERENCES `co_suppliers` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ############################################################################
-- 13. construction_supplier_documents.sql
-- ############################################################################

CREATE TABLE IF NOT EXISTS `co_supplier_documents` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `company_id` INT(11) NOT NULL,
  `supplier_id` INT(11) NOT NULL,
  `doc_type` VARCHAR(50) NOT NULL DEFAULT 'other',
  `title` VARCHAR(255) NOT NULL,
  `file_path` VARCHAR(500) NOT NULL,
  `notes` TEXT DEFAULT NULL,
  `uploaded_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `uploaded_by` INT(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_company` (`company_id`),
  KEY `idx_supplier` (`supplier_id`),
  FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE RESTRICT,
  FOREIGN KEY (`supplier_id`) REFERENCES `co_suppliers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `co_supplier_invoice_documents` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `company_id` INT(11) NOT NULL,
  `supplier_invoice_id` INT(11) NOT NULL,
  `title` VARCHAR(255) NOT NULL DEFAULT 'Invoice PDF',
  `file_path` VARCHAR(500) NOT NULL,
  `uploaded_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `uploaded_by` INT(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_company` (`company_id`),
  KEY `idx_invoice` (`supplier_invoice_id`),
  FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE RESTRICT,
  FOREIGN KEY (`supplier_invoice_id`) REFERENCES `co_supplier_invoices` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ############################################################################
-- 14. construction_chart_of_accounts.sql (optional — set @company_id below)
-- ############################################################################
-- Requires: re_chart_of_accounts table and base COA (e.g. 1000, 2100/2000, 5000).
-- Set @company_id to your construction company ID, or skip this section and use
-- Construction → Setup Accounts in the app to add 2110, 2130, 2310 as well.

SET @company_id = 2;

SET @parent_asset = (SELECT id FROM re_chart_of_accounts WHERE company_id = @company_id AND account_code = '1000' LIMIT 1);
SET @parent_liability = (SELECT id FROM re_chart_of_accounts WHERE company_id = @company_id AND account_code = '2100' LIMIT 1);
SET @parent_expense = (SELECT id FROM re_chart_of_accounts WHERE company_id = @company_id AND account_code = '5000' LIMIT 1);
SET @parent_liability = IFNULL(@parent_liability, (SELECT id FROM re_chart_of_accounts WHERE company_id = @company_id AND account_code = '2000' LIMIT 1));

INSERT IGNORE INTO re_chart_of_accounts (company_id, account_code, account_name, account_type, parent_id, normal_balance, is_header, is_active, description)
SELECT @company_id, '1515', 'Construction in Progress', 'Asset', @parent_asset, 'debit', 0, 1, 'Construction work in progress (OWNER projects)'
WHERE @parent_asset IS NOT NULL AND NOT EXISTS (SELECT 1 FROM re_chart_of_accounts WHERE company_id = @company_id AND account_code = '1515');

INSERT IGNORE INTO re_chart_of_accounts (company_id, account_code, account_name, account_type, parent_id, normal_balance, is_header, is_active, description)
SELECT @company_id, '5125', 'Project Cost / Construction COGS', 'Expense', @parent_expense, 'debit', 0, 1, 'Project costs (CLIENT projects)'
WHERE @parent_expense IS NOT NULL AND NOT EXISTS (SELECT 1 FROM re_chart_of_accounts WHERE company_id = @company_id AND account_code = '5125');

INSERT IGNORE INTO re_chart_of_accounts (company_id, account_code, account_name, account_type, parent_id, normal_balance, is_header, is_active, description)
SELECT @company_id, '2145', 'Contractor Payable', 'Liability', @parent_liability, 'credit', 0, 1, 'Amounts owed to contractors'
WHERE @parent_liability IS NOT NULL AND NOT EXISTS (SELECT 1 FROM re_chart_of_accounts WHERE company_id = @company_id AND account_code = '2145');

INSERT IGNORE INTO re_chart_of_accounts (company_id, account_code, account_name, account_type, parent_id, normal_balance, is_header, is_active, description)
SELECT @company_id, '2125', 'Retention Payable', 'Liability', @parent_liability, 'credit', 0, 1, 'Contractor retention held'
WHERE @parent_liability IS NOT NULL AND NOT EXISTS (SELECT 1 FROM re_chart_of_accounts WHERE company_id = @company_id AND account_code = '2125');

SET FOREIGN_KEY_CHECKS = 1;

-- ============================================================================
-- END — Construction module migrations complete.
-- ============================================================================
