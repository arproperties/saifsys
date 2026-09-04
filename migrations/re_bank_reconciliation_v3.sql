-- Real Estate — bank reconciliation v3 (settings / automation)
-- Run after migrations/re_bank_reconciliation_v2.sql

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `re_bank_reco_settings` (
  `company_id` INT(11) NOT NULL,
  `auto_reconcile_rule_matches` TINYINT(1) NOT NULL DEFAULT 0,
  `updated_at` DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  `updated_by` INT(11) DEFAULT NULL,
  PRIMARY KEY (`company_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
