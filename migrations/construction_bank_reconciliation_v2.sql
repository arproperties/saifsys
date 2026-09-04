-- Construction module — bank reconciliation v2 (additive, extends co_* tables)
-- Run after migrations/construction_bank_reconciliation.sql

SET NAMES utf8mb4;

ALTER TABLE `co_bank_import_batches`
  ADD COLUMN IF NOT EXISTS `statement_start_date` DATE DEFAULT NULL AFTER `file_name`,
  ADD COLUMN IF NOT EXISTS `statement_end_date` DATE DEFAULT NULL AFTER `statement_start_date`,
  ADD COLUMN IF NOT EXISTS `opening_balance` DECIMAL(18,2) DEFAULT NULL AFTER `statement_end_date`,
  ADD COLUMN IF NOT EXISTS `closing_balance` DECIMAL(18,2) DEFAULT NULL AFTER `opening_balance`,
  ADD COLUMN IF NOT EXISTS `total_debits` DECIMAL(18,2) NOT NULL DEFAULT 0.00 AFTER `closing_balance`,
  ADD COLUMN IF NOT EXISTS `total_credits` DECIMAL(18,2) NOT NULL DEFAULT 0.00 AFTER `total_debits`,
  ADD COLUMN IF NOT EXISTS `status` ENUM('preview','imported','cancelled') NOT NULL DEFAULT 'imported' AFTER `row_count`;

ALTER TABLE `co_bank_statement_lines`
  ADD COLUMN IF NOT EXISTS `value_date` DATE DEFAULT NULL AFTER `txn_date`,
  ADD COLUMN IF NOT EXISTS `cheque_no` VARCHAR(80) DEFAULT NULL AFTER `reference`,
  ADD COLUMN IF NOT EXISTS `debit_amount` DECIMAL(18,2) NOT NULL DEFAULT 0.00 AFTER `cheque_no`,
  ADD COLUMN IF NOT EXISTS `credit_amount` DECIMAL(18,2) NOT NULL DEFAULT 0.00 AFTER `debit_amount`,
  ADD COLUMN IF NOT EXISTS `currency` CHAR(3) NOT NULL DEFAULT 'AED' AFTER `credit_amount`,
  ADD COLUMN IF NOT EXISTS `status` ENUM('unreconciled','suggested','reconciled','discussed','ignored','duplicate') NOT NULL DEFAULT 'unreconciled' AFTER `source_hash`,
  ADD COLUMN IF NOT EXISTS `matched_confidence` DECIMAL(5,2) DEFAULT NULL AFTER `status`,
  ADD COLUMN IF NOT EXISTS `suggested_match_type` VARCHAR(40) DEFAULT NULL AFTER `matched_confidence`,
  ADD COLUMN IF NOT EXISTS `suggested_match_id` BIGINT UNSIGNED DEFAULT NULL AFTER `suggested_match_type`,
  ADD COLUMN IF NOT EXISTS `created_by` INT(11) DEFAULT NULL AFTER `suggested_match_id`,
  ADD COLUMN IF NOT EXISTS `updated_at` DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP AFTER `created_at`;

UPDATE `co_bank_statement_lines`
SET
  `debit_amount` = CASE WHEN `amount` < 0 THEN ABS(`amount`) ELSE 0 END,
  `credit_amount` = CASE WHEN `amount` > 0 THEN `amount` ELSE 0 END
WHERE (`debit_amount` = 0 AND `credit_amount` = 0 AND `amount` <> 0);

ALTER TABLE `co_reconciliation_matches`
  ADD COLUMN IF NOT EXISTS `company_id` INT(11) DEFAULT NULL AFTER `id`,
  ADD COLUMN IF NOT EXISTS `bank_account_id` INT(11) DEFAULT NULL AFTER `company_id`,
  ADD COLUMN IF NOT EXISTS `source_table` VARCHAR(80) DEFAULT NULL AFTER `system_id`,
  ADD COLUMN IF NOT EXISTS `source_id` BIGINT UNSIGNED DEFAULT NULL AFTER `source_table`,
  ADD COLUMN IF NOT EXISTS `match_method` ENUM('match','create','transfer','cash_coding','rule','adjustment') NOT NULL DEFAULT 'match' AFTER `source_id`,
  ADD COLUMN IF NOT EXISTS `confidence_score` DECIMAL(5,2) DEFAULT NULL AFTER `match_method`,
  ADD COLUMN IF NOT EXISTS `confidence_label` ENUM('High','Medium','Low') DEFAULT NULL AFTER `confidence_score`,
  ADD COLUMN IF NOT EXISTS `notes` VARCHAR(500) DEFAULT NULL AFTER `confidence_label`,
  ADD COLUMN IF NOT EXISTS `created_transaction_id` INT(11) DEFAULT NULL COMMENT 're_journal_headers.id when created from reco' AFTER `notes`;

UPDATE `co_reconciliation_matches` m
JOIN `co_bank_statement_lines` l ON l.id = m.bank_statement_line_id
SET m.company_id = l.company_id, m.bank_account_id = l.bank_account_id
WHERE m.company_id IS NULL;

CREATE TABLE IF NOT EXISTS `co_bank_line_notes` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `company_id` INT(11) NOT NULL,
  `bank_account_id` INT(11) NOT NULL,
  `statement_line_id` BIGINT UNSIGNED NOT NULL,
  `note` TEXT NOT NULL,
  `assignee_user_id` INT(11) DEFAULT NULL,
  `need_invoice` TINYINT(1) NOT NULL DEFAULT 0,
  `need_approval` TINYINT(1) NOT NULL DEFAULT 0,
  `need_vendor_confirmation` TINYINT(1) NOT NULL DEFAULT 0,
  `follow_up_date` DATE DEFAULT NULL,
  `attachment_path` VARCHAR(500) DEFAULT NULL,
  `created_by` INT(11) DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_co_bank_note_line` (`statement_line_id`),
  KEY `idx_co_bank_note_company` (`company_id`, `bank_account_id`),
  CONSTRAINT `fk_co_bank_note_line` FOREIGN KEY (`statement_line_id`) REFERENCES `co_bank_statement_lines` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `co_bank_reconciliation_audit` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `company_id` INT(11) NOT NULL,
  `bank_account_id` INT(11) DEFAULT NULL,
  `statement_line_id` BIGINT UNSIGNED DEFAULT NULL,
  `match_id` BIGINT UNSIGNED DEFAULT NULL,
  `action` VARCHAR(80) NOT NULL,
  `related_transaction_id` BIGINT UNSIGNED DEFAULT NULL,
  `old_value` JSON DEFAULT NULL,
  `new_value` JSON DEFAULT NULL,
  `user_id` INT(11) DEFAULT NULL,
  `ip_address` VARCHAR(45) DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_co_bank_audit_company` (`company_id`, `created_at`),
  KEY `idx_co_bank_audit_line` (`statement_line_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `co_bank_reco_settings` (
  `company_id` INT(11) NOT NULL,
  `auto_reconcile_rule_matches` TINYINT(1) NOT NULL DEFAULT 0,
  `updated_at` DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`company_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `co_bank_rules` (
  `id` INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `company_id` INT(11) NOT NULL,
  `rule_name` VARCHAR(120) NOT NULL,
  `direction` ENUM('spent','received','both','transfer') NOT NULL DEFAULT 'spent',
  `bank_account_id` INT(11) DEFAULT NULL,
  `conditions_json` JSON NOT NULL,
  `action_json` JSON NOT NULL,
  `priority` INT(11) NOT NULL DEFAULT 100,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `auto_suggest` TINYINT(1) NOT NULL DEFAULT 1,
  `auto_create_draft` TINYINT(1) NOT NULL DEFAULT 0,
  `created_by` INT(11) DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_co_bank_rules_company` (`company_id`, `is_active`, `priority`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

UPDATE role_modules rm
SET permissions = JSON_SET(
  COALESCE(NULLIF(rm.permissions, ''), '{}'),
  '$.construction',
  JSON_ARRAY(
    'bank_reconciliation.view',
    'bank_reconciliation.import',
    'bank_reconciliation.match',
    'bank_reconciliation.create_transaction',
    'bank_reconciliation.transfer',
    'bank_reconciliation.cash_coding',
    'bank_reconciliation.rules',
    'bank_reconciliation.undo',
    'bank_reconciliation.report',
    'bank_reconciliation.admin_override'
  )
)
WHERE rm.module = 'construction'
  AND (rm.permissions IS NULL OR rm.permissions = '' OR rm.permissions = '{}'
       OR JSON_EXTRACT(rm.permissions, '$.construction') IS NULL);
