-- Real Estate — bank reconciliation v4 (Phase 4: bank feed connections stub)
-- Run after migrations/re_bank_reconciliation_v3.sql

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `re_bank_feed_connections` (
  `id` INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `company_id` INT(11) NOT NULL,
  `bank_account_id` INT(11) DEFAULT NULL COMMENT 're_bank_accounts.id when linked',
  `provider` VARCHAR(80) NOT NULL DEFAULT 'manual' COMMENT 'manual, open_banking, csv_feed, etc.',
  `connection_name` VARCHAR(120) NOT NULL,
  `status` ENUM('inactive','pending','active','error') NOT NULL DEFAULT 'inactive',
  `config_json` JSON DEFAULT NULL,
  `last_sync_at` DATETIME DEFAULT NULL,
  `last_error` VARCHAR(500) DEFAULT NULL,
  `created_by` INT(11) DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_re_bank_feed_company` (`company_id`, `status`),
  KEY `idx_re_bank_feed_bank` (`bank_account_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
