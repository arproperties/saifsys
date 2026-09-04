-- ============================================================================
-- REAL ESTATE ACCOUNTING SYSTEM - Database Schema
-- ============================================================================
-- This migration creates all tables for the Real Estate Accounting module
-- Database: herosysgro
-- Company Isolation: All tables use company_id for strict data segregation
-- Double-Entry: Full double-entry accounting system inspired by Tally (UAE)
-- ============================================================================

SET FOREIGN_KEY_CHECKS = 0;
SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";

-- ============================================================================
-- PART 1: CHART OF ACCOUNTS
-- ============================================================================

CREATE TABLE IF NOT EXISTS `re_chart_of_accounts` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `company_id` INT(11) NOT NULL,
  `account_code` VARCHAR(50) NOT NULL,
  `account_name` VARCHAR(255) NOT NULL,
  `account_type` ENUM('Asset', 'Liability', 'Equity', 'Income', 'Expense') NOT NULL,
  `parent_id` INT(11) DEFAULT NULL,
  `normal_balance` ENUM('debit', 'credit') NOT NULL,
  `is_header` TINYINT(1) DEFAULT 0 COMMENT '1 if this is a header account (parent only)',
  `is_active` TINYINT(1) DEFAULT 1,
  `description` TEXT DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_company_code` (`company_id`, `account_code`),
  KEY `idx_company` (`company_id`),
  KEY `idx_type` (`account_type`),
  KEY `idx_parent` (`parent_id`),
  KEY `idx_active` (`is_active`),
  FOREIGN KEY (`company_id`) REFERENCES `companies`(`id`) ON DELETE RESTRICT,
  FOREIGN KEY (`parent_id`) REFERENCES `re_chart_of_accounts`(`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ============================================================================
-- PART 2: JOURNAL HEADERS
-- ============================================================================

CREATE TABLE IF NOT EXISTS `re_journal_headers` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `company_id` INT(11) NOT NULL,
  `journal_number` VARCHAR(50) NOT NULL,
  `journal_date` DATE NOT NULL,
  `journal_type` ENUM('manual', 'invoice', 'payment', 'deposit', 'refund', 'adjustment', 'recurring', 'reversal') NOT NULL,
  `reference_type` VARCHAR(50) DEFAULT NULL COMMENT 'Source document type: invoice, payment, lease, etc.',
  `reference_id` INT(11) DEFAULT NULL COMMENT 'ID of source document',
  `description` TEXT DEFAULT NULL,
  `total_debit` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  `total_credit` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  `is_posted` TINYINT(1) DEFAULT 0,
  `posted_at` DATETIME DEFAULT NULL,
  `posted_by` INT(11) DEFAULT NULL,
  `is_reversed` TINYINT(1) DEFAULT 0,
  `reversal_journal_id` INT(11) DEFAULT NULL COMMENT 'ID of reversal journal if this is a reversal',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `created_by` INT(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_journal_number` (`company_id`, `journal_number`),
  KEY `idx_company` (`company_id`),
  KEY `idx_date` (`journal_date`),
  KEY `idx_type` (`journal_type`),
  KEY `idx_reference` (`reference_type`, `reference_id`),
  KEY `idx_posted` (`is_posted`),
  KEY `idx_created_by` (`created_by`),
  KEY `idx_posted_by` (`posted_by`),
  FOREIGN KEY (`company_id`) REFERENCES `companies`(`id`) ON DELETE RESTRICT,
  FOREIGN KEY (`created_by`) REFERENCES `user`(`id`) ON DELETE SET NULL,
  FOREIGN KEY (`posted_by`) REFERENCES `user`(`id`) ON DELETE SET NULL,
  FOREIGN KEY (`reversal_journal_id`) REFERENCES `re_journal_headers`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ============================================================================
-- PART 3: JOURNAL LINES
-- ============================================================================

CREATE TABLE IF NOT EXISTS `re_journal_lines` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `company_id` INT(11) NOT NULL,
  `journal_id` INT(11) NOT NULL,
  `account_id` INT(11) NOT NULL,
  `line_number` INT(11) NOT NULL,
  `debit_amount` DECIMAL(15,2) DEFAULT 0.00,
  `credit_amount` DECIMAL(15,2) DEFAULT 0.00,
  `description` VARCHAR(500) DEFAULT NULL,
  `reference` VARCHAR(100) DEFAULT NULL COMMENT 'Additional reference (invoice #, payment #, etc.)',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_company` (`company_id`),
  KEY `idx_journal` (`journal_id`),
  KEY `idx_account` (`account_id`),
  KEY `idx_line` (`journal_id`, `line_number`),
  FOREIGN KEY (`company_id`) REFERENCES `companies`(`id`) ON DELETE RESTRICT,
  FOREIGN KEY (`journal_id`) REFERENCES `re_journal_headers`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`account_id`) REFERENCES `re_chart_of_accounts`(`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ============================================================================
-- PART 4: GENERAL LEDGER
-- ============================================================================

CREATE TABLE IF NOT EXISTS `re_general_ledger` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `company_id` INT(11) NOT NULL,
  `account_id` INT(11) NOT NULL,
  `journal_id` INT(11) NOT NULL,
  `journal_line_id` INT(11) NOT NULL,
  `entry_date` DATE NOT NULL,
  `debit_amount` DECIMAL(15,2) DEFAULT 0.00,
  `credit_amount` DECIMAL(15,2) DEFAULT 0.00,
  `balance` DECIMAL(15,2) NOT NULL COMMENT 'Running balance for this account',
  `description` VARCHAR(500) DEFAULT NULL,
  `reference` VARCHAR(100) DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_company` (`company_id`),
  KEY `idx_account_date` (`account_id`, `entry_date`),
  KEY `idx_account` (`account_id`),
  KEY `idx_journal` (`journal_id`),
  KEY `idx_date` (`entry_date`),
  FOREIGN KEY (`company_id`) REFERENCES `companies`(`id`) ON DELETE RESTRICT,
  FOREIGN KEY (`account_id`) REFERENCES `re_chart_of_accounts`(`id`) ON DELETE RESTRICT,
  FOREIGN KEY (`journal_id`) REFERENCES `re_journal_headers`(`id`) ON DELETE RESTRICT,
  FOREIGN KEY (`journal_line_id`) REFERENCES `re_journal_lines`(`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ============================================================================
-- PART 5: ACCOUNT LEDGERS (SUB-LEDGERS)
-- ============================================================================

CREATE TABLE IF NOT EXISTS `re_account_ledgers` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `company_id` INT(11) NOT NULL,
  `account_id` INT(11) NOT NULL COMMENT 'Parent GL account (e.g., Accounts Receivable)',
  `sub_account_type` ENUM('tenant', 'vendor', 'bank', 'cash', 'other') NOT NULL,
  `sub_account_id` INT(11) NOT NULL COMMENT 'tenant_id, vendor_id, bank_account_id, etc.',
  `sub_account_name` VARCHAR(255) NOT NULL,
  `opening_balance` DECIMAL(15,2) DEFAULT 0.00,
  `current_balance` DECIMAL(15,2) DEFAULT 0.00,
  `is_active` TINYINT(1) DEFAULT 1,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_account_sub` (`company_id`, `account_id`, `sub_account_type`, `sub_account_id`),
  KEY `idx_company` (`company_id`),
  KEY `idx_account` (`account_id`),
  KEY `idx_sub` (`sub_account_type`, `sub_account_id`),
  FOREIGN KEY (`company_id`) REFERENCES `companies`(`id`) ON DELETE RESTRICT,
  FOREIGN KEY (`account_id`) REFERENCES `re_chart_of_accounts`(`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ============================================================================
-- PART 6: ACCOUNT LEDGER ENTRIES
-- ============================================================================

CREATE TABLE IF NOT EXISTS `re_account_ledger_entries` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `company_id` INT(11) NOT NULL,
  `ledger_id` INT(11) NOT NULL,
  `journal_id` INT(11) NOT NULL,
  `journal_line_id` INT(11) NOT NULL,
  `entry_date` DATE NOT NULL,
  `debit_amount` DECIMAL(15,2) DEFAULT 0.00,
  `credit_amount` DECIMAL(15,2) DEFAULT 0.00,
  `balance` DECIMAL(15,2) NOT NULL COMMENT 'Running balance for this sub-ledger',
  `description` VARCHAR(500) DEFAULT NULL,
  `reference` VARCHAR(100) DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_company` (`company_id`),
  KEY `idx_ledger_date` (`ledger_id`, `entry_date`),
  KEY `idx_ledger` (`ledger_id`),
  KEY `idx_journal` (`journal_id`),
  KEY `idx_date` (`entry_date`),
  FOREIGN KEY (`company_id`) REFERENCES `companies`(`id`) ON DELETE RESTRICT,
  FOREIGN KEY (`ledger_id`) REFERENCES `re_account_ledgers`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`journal_id`) REFERENCES `re_journal_headers`(`id`) ON DELETE RESTRICT,
  FOREIGN KEY (`journal_line_id`) REFERENCES `re_journal_lines`(`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ============================================================================
-- PART 7: BANK ACCOUNTS
-- ============================================================================

CREATE TABLE IF NOT EXISTS `re_bank_accounts` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `company_id` INT(11) NOT NULL,
  `account_name` VARCHAR(255) NOT NULL,
  `bank_name` VARCHAR(255) DEFAULT NULL,
  `account_number` VARCHAR(100) DEFAULT NULL,
  `iban` VARCHAR(50) DEFAULT NULL,
  `swift_code` VARCHAR(20) DEFAULT NULL,
  `currency` VARCHAR(3) DEFAULT 'AED',
  `gl_account_id` INT(11) NOT NULL COMMENT 'Links to Chart of Accounts',
  `opening_balance` DECIMAL(15,2) DEFAULT 0.00,
  `current_balance` DECIMAL(15,2) DEFAULT 0.00,
  `is_active` TINYINT(1) DEFAULT 1,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_company` (`company_id`),
  KEY `idx_gl_account` (`gl_account_id`),
  KEY `idx_active` (`is_active`),
  FOREIGN KEY (`company_id`) REFERENCES `companies`(`id`) ON DELETE RESTRICT,
  FOREIGN KEY (`gl_account_id`) REFERENCES `re_chart_of_accounts`(`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ============================================================================
-- PART 8: VAT CONFIGURATION
-- ============================================================================

CREATE TABLE IF NOT EXISTS `re_vat_config` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `company_id` INT(11) NOT NULL,
  `vat_rate` DECIMAL(5,2) DEFAULT 5.00 COMMENT 'UAE standard VAT rate',
  `vat_registration_number` VARCHAR(100) DEFAULT NULL,
  `input_vat_account_id` INT(11) NOT NULL COMMENT 'Input VAT account',
  `output_vat_account_id` INT(11) NOT NULL COMMENT 'Output VAT account',
  `is_active` TINYINT(1) DEFAULT 1,
  `effective_from` DATE DEFAULT NULL,
  `effective_to` DATE DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_company_active` (`company_id`, `is_active`),
  KEY `idx_company` (`company_id`),
  KEY `idx_input_vat` (`input_vat_account_id`),
  KEY `idx_output_vat` (`output_vat_account_id`),
  FOREIGN KEY (`company_id`) REFERENCES `companies`(`id`) ON DELETE RESTRICT,
  FOREIGN KEY (`input_vat_account_id`) REFERENCES `re_chart_of_accounts`(`id`) ON DELETE RESTRICT,
  FOREIGN KEY (`output_vat_account_id`) REFERENCES `re_chart_of_accounts`(`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ============================================================================
-- PART 9: FINANCIAL PERIODS
-- ============================================================================

CREATE TABLE IF NOT EXISTS `re_financial_periods` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `company_id` INT(11) NOT NULL,
  `period_name` VARCHAR(100) NOT NULL,
  `start_date` DATE NOT NULL,
  `end_date` DATE NOT NULL,
  `is_closed` TINYINT(1) DEFAULT 0,
  `closed_at` DATETIME DEFAULT NULL,
  `closed_by` INT(11) DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_company` (`company_id`),
  KEY `idx_dates` (`start_date`, `end_date`),
  KEY `idx_closed` (`is_closed`),
  KEY `idx_closed_by` (`closed_by`),
  FOREIGN KEY (`company_id`) REFERENCES `companies`(`id`) ON DELETE RESTRICT,
  FOREIGN KEY (`closed_by`) REFERENCES `user`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ============================================================================
-- PART 10: JOURNAL SEQUENCES (for auto-numbering)
-- ============================================================================

CREATE TABLE IF NOT EXISTS `re_journal_sequences` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `company_id` INT(11) NOT NULL,
  `year` INT(11) NOT NULL,
  `sequence_number` INT(11) NOT NULL DEFAULT 0,
  `prefix` VARCHAR(50) DEFAULT 'JRN',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_company_year` (`company_id`, `year`),
  KEY `idx_company` (`company_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ============================================================================
-- PART 11: ACCOUNTING POSTING LOG (Audit Trail)
-- ============================================================================

CREATE TABLE IF NOT EXISTS `re_accounting_postings` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `company_id` INT(11) NOT NULL,
  `journal_id` INT(11) NOT NULL,
  `source_type` VARCHAR(50) NOT NULL COMMENT 'invoice, payment, deposit, etc.',
  `source_id` INT(11) NOT NULL,
  `posting_status` ENUM('pending', 'posted', 'failed', 'reversed') DEFAULT 'pending',
  `error_message` TEXT DEFAULT NULL,
  `posted_at` DATETIME DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_company` (`company_id`),
  KEY `idx_journal` (`journal_id`),
  KEY `idx_source` (`source_type`, `source_id`),
  KEY `idx_status` (`posting_status`),
  FOREIGN KEY (`company_id`) REFERENCES `companies`(`id`) ON DELETE RESTRICT,
  FOREIGN KEY (`journal_id`) REFERENCES `re_journal_headers`(`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

SET FOREIGN_KEY_CHECKS = 1;

-- ============================================================================
-- END OF MIGRATION
-- ============================================================================
