-- Phase 3 & 5: Credit note journal type, Audit log
-- Run after accounting_critical_controls.sql

-- Add credit_note to journal_type ENUM
ALTER TABLE `re_journal_headers`
  MODIFY COLUMN `journal_type` ENUM(
    'manual', 'invoice', 'payment', 'deposit', 'refund', 'adjustment',
    'recurring', 'reversal', 'opening_balance', 'closing', 'credit_note'
  ) NOT NULL;

-- Audit log for accounting actions (optional; skip if table exists)
CREATE TABLE IF NOT EXISTS `re_accounting_audit_log` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `company_id` INT(11) NOT NULL,
  `action` VARCHAR(50) NOT NULL COMMENT 'create_journal, post_journal, reverse_journal, close_period',
  `entity_type` VARCHAR(50) DEFAULT NULL COMMENT 'journal_header, fiscal_year',
  `entity_id` INT(11) DEFAULT NULL,
  `user_id` INT(11) DEFAULT NULL,
  `ip_address` VARCHAR(45) DEFAULT NULL,
  `details` TEXT DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_company` (`company_id`),
  KEY `idx_entity` (`entity_type`, `entity_id`),
  KEY `idx_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
