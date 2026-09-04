-- ============================================================================
-- Real Estate Accounting Transformation - Phase 8 Bank Reconciliation Upgrade
-- ----------------------------------------------------------------------------
-- Purpose:
--   Persist bank statement imports, statement lines, confirmed reconciliation
--   matches, audit trail, and bank-account period locks.
--
-- Safety:
--   - Additive only.
--   - No historical auto-match.
--   - No destructive changes.
-- ============================================================================

CREATE TABLE IF NOT EXISTS `re_bank_import_batches` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `company_id` INT(11) NOT NULL,
    `bank_account_id` INT(11) NOT NULL,
    `file_name` VARCHAR(255) NOT NULL DEFAULT '',
    `statement_start_date` DATE DEFAULT NULL,
    `statement_end_date` DATE DEFAULT NULL,
    `opening_balance` DECIMAL(18,2) DEFAULT NULL,
    `closing_balance` DECIMAL(18,2) DEFAULT NULL,
    `row_count` INT(11) NOT NULL DEFAULT 0,
    `imported_by` INT(11) DEFAULT NULL,
    `imported_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `status` ENUM('preview','imported','cancelled') NOT NULL DEFAULT 'imported',
    `notes` VARCHAR(255) DEFAULT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_re_bank_batch_company` (`company_id`),
    KEY `idx_re_bank_batch_bank` (`company_id`, `bank_account_id`, `imported_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Phase 8 persisted bank statement import batches.';

CREATE TABLE IF NOT EXISTS `re_bank_statement_lines` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `company_id` INT(11) NOT NULL,
    `bank_account_id` INT(11) NOT NULL,
    `import_batch_id` BIGINT UNSIGNED DEFAULT NULL,
    `statement_date` DATE NOT NULL,
    `value_date` DATE DEFAULT NULL,
    `description` VARCHAR(500) NOT NULL DEFAULT '',
    `reference` VARCHAR(150) DEFAULT NULL,
    `debit_amount` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
    `credit_amount` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
    `net_amount` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
    `running_balance` DECIMAL(18,2) DEFAULT NULL,
    `raw_payload_json` JSON DEFAULT NULL,
    `source_hash` CHAR(64) NOT NULL,
    `status` ENUM('unmatched','suggested','matched','partially_matched','ignored','investigating') NOT NULL DEFAULT 'unmatched',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_re_bank_stmt_hash` (`company_id`, `bank_account_id`, `source_hash`),
    KEY `idx_re_bank_stmt_bank_date` (`company_id`, `bank_account_id`, `statement_date`),
    KEY `idx_re_bank_stmt_batch` (`import_batch_id`),
    KEY `idx_re_bank_stmt_status` (`company_id`, `bank_account_id`, `status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Phase 8 persisted bank statement lines.';

CREATE TABLE IF NOT EXISTS `re_bank_reconciliation_matches` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `company_id` INT(11) NOT NULL,
    `bank_account_id` INT(11) NOT NULL,
    `statement_line_id` BIGINT UNSIGNED NOT NULL,
    `match_type` ENUM('receipt','payment','journal_line','adjustment','split','ignore') NOT NULL,
    `source_table` VARCHAR(80) DEFAULT NULL,
    `source_id` BIGINT UNSIGNED DEFAULT NULL,
    `gl_line_id` INT(11) DEFAULT NULL,
    `matched_amount` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
    `confidence_score` DECIMAL(6,2) DEFAULT NULL,
    `confidence_label` ENUM('High','Medium','Low') DEFAULT NULL,
    `matched_by` INT(11) DEFAULT NULL,
    `matched_at` DATETIME DEFAULT NULL,
    `status` ENUM('suggested','confirmed','void') NOT NULL DEFAULT 'suggested',
    `notes` VARCHAR(255) DEFAULT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_re_bank_match_line` (`company_id`, `statement_line_id`, `status`),
    KEY `idx_re_bank_match_source` (`company_id`, `source_table`, `source_id`),
    KEY `idx_re_bank_match_gl` (`gl_line_id`),
    KEY `idx_re_bank_match_bank` (`company_id`, `bank_account_id`, `status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Phase 8 suggested and confirmed bank reconciliation matches.';

CREATE TABLE IF NOT EXISTS `re_bank_reconciliation_audit` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `company_id` INT(11) NOT NULL,
    `bank_account_id` INT(11) NOT NULL,
    `statement_line_id` BIGINT UNSIGNED DEFAULT NULL,
    `match_id` BIGINT UNSIGNED DEFAULT NULL,
    `action` VARCHAR(80) NOT NULL,
    `old_value` TEXT DEFAULT NULL,
    `new_value` TEXT DEFAULT NULL,
    `changed_by` INT(11) DEFAULT NULL,
    `changed_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `reason` VARCHAR(255) DEFAULT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_re_bank_audit_bank` (`company_id`, `bank_account_id`, `changed_at`),
    KEY `idx_re_bank_audit_line` (`statement_line_id`),
    KEY `idx_re_bank_audit_match` (`match_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Phase 8 bank reconciliation audit trail.';

CREATE TABLE IF NOT EXISTS `re_bank_reconciliation_locks` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `company_id` INT(11) NOT NULL,
    `bank_account_id` INT(11) NOT NULL,
    `period_start` DATE NOT NULL,
    `period_end` DATE NOT NULL,
    `locked_by` INT(11) DEFAULT NULL,
    `locked_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `notes` VARCHAR(255) DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_re_bank_lock_period` (`company_id`, `bank_account_id`, `period_start`, `period_end`),
    KEY `idx_re_bank_lock_lookup` (`company_id`, `bank_account_id`, `period_start`, `period_end`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Phase 8 bank reconciliation period locks.';

