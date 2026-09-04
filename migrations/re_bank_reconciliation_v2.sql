-- Real Estate — bank reconciliation v2 (Xero-style workbench: notes, match metadata, permissions)
-- Run after migrations/re_accounting_phase8_bank_reconciliation.sql

SET NAMES utf8mb4;

ALTER TABLE `re_bank_reconciliation_matches`
  ADD COLUMN IF NOT EXISTS `match_method` ENUM('match','create','transfer','cash_coding','rule','adjustment') NOT NULL DEFAULT 'match' AFTER `notes`,
  ADD COLUMN IF NOT EXISTS `created_transaction_id` INT(11) DEFAULT NULL COMMENT 're_journal_headers.id when created from reco' AFTER `match_method`;

CREATE TABLE IF NOT EXISTS `re_bank_line_notes` (
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
  KEY `idx_re_bank_note_line` (`statement_line_id`),
  KEY `idx_re_bank_note_company` (`company_id`, `bank_account_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `re_bank_rules` (
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
  KEY `idx_re_bank_rules_company` (`company_id`, `is_active`, `priority`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

UPDATE role_modules rm
SET permissions = JSON_SET(
  COALESCE(NULLIF(rm.permissions, ''), '{}'),
  '$.realestate',
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
WHERE rm.module = 'realestate'
  AND (rm.permissions IS NULL OR rm.permissions = '' OR rm.permissions = '{}'
       OR JSON_EXTRACT(rm.permissions, '$.realestate') IS NULL);
