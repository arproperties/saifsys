-- Phase 3 — Cleaning module bank reconciliation (not real-estate accounting)
-- Run after chart_of_accounts exists.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `cleaning_bank_accounts` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `chart_account_id` int(10) UNSIGNED NOT NULL,
  `name` varchar(120) NOT NULL,
  `currency` char(3) NOT NULL DEFAULT 'AED',
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `company_id` int(11) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_cleaning_bank_coa` (`chart_account_id`),
  KEY `idx_cleaning_bank_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `cleaning_bank_import_batches` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `bank_account_id` int(10) UNSIGNED NOT NULL,
  `file_name` varchar(255) NOT NULL DEFAULT '',
  `imported_by` int(11) DEFAULT NULL,
  `imported_at` datetime NOT NULL DEFAULT current_timestamp(),
  `row_count` int(11) NOT NULL DEFAULT 0,
  `notes` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_cleaning_batch_bank` (`bank_account_id`),
  CONSTRAINT `fk_cleaning_batch_bank` FOREIGN KEY (`bank_account_id`) REFERENCES `cleaning_bank_accounts` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `cleaning_bank_statement_lines` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `bank_account_id` int(10) UNSIGNED NOT NULL,
  `txn_date` date NOT NULL,
  `amount` decimal(18,2) NOT NULL COMMENT 'Positive = money in, negative = money out',
  `description` varchar(500) NOT NULL DEFAULT '',
  `reference` varchar(120) DEFAULT NULL,
  `balance_after` decimal(18,2) DEFAULT NULL,
  `import_batch_id` int(10) UNSIGNED DEFAULT NULL,
  `source_hash` char(64) DEFAULT NULL COMMENT 'Dedupe key',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_cleaning_stmt_bank_date` (`bank_account_id`, `txn_date`),
  KEY `idx_cleaning_stmt_batch` (`import_batch_id`),
  KEY `idx_cleaning_stmt_hash` (`bank_account_id`, `source_hash`),
  CONSTRAINT `fk_cleaning_stmt_bank` FOREIGN KEY (`bank_account_id`) REFERENCES `cleaning_bank_accounts` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_cleaning_stmt_batch` FOREIGN KEY (`import_batch_id`) REFERENCES `cleaning_bank_import_batches` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `cleaning_reconciliation_matches` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `bank_statement_line_id` bigint(20) UNSIGNED NOT NULL,
  `system_type` enum('receipt','expense') NOT NULL,
  `system_id` int(11) NOT NULL,
  `amount_matched` decimal(18,2) NOT NULL,
  `status` enum('proposed','confirmed','void') NOT NULL DEFAULT 'proposed',
  `period_lock_key` char(7) DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `confirmed_by` int(11) DEFAULT NULL,
  `confirmed_at` datetime DEFAULT NULL,
  `voided_by` int(11) DEFAULT NULL,
  `voided_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_cleaning_match_bankline` (`bank_statement_line_id`),
  KEY `idx_cleaning_match_system` (`system_type`, `system_id`),
  KEY `idx_cleaning_match_status` (`status`),
  CONSTRAINT `fk_cleaning_match_line` FOREIGN KEY (`bank_statement_line_id`) REFERENCES `cleaning_bank_statement_lines` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `cleaning_reconciliation_period_locks` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `bank_account_id` int(10) UNSIGNED NOT NULL,
  `period` char(7) NOT NULL COMMENT 'YYYY-MM',
  `locked` tinyint(1) NOT NULL DEFAULT 1,
  `locked_by` int(11) DEFAULT NULL,
  `locked_at` datetime DEFAULT NULL,
  `notes` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_cleaning_lock_bank_period` (`bank_account_id`, `period`),
  CONSTRAINT `fk_cleaning_lock_bank` FOREIGN KEY (`bank_account_id`) REFERENCES `cleaning_bank_accounts` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

ALTER TABLE `cleaning_bank_accounts`
  ADD CONSTRAINT `fk_cleaning_bank_coa` FOREIGN KEY (`chart_account_id`) REFERENCES `chart_of_accounts` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE;
