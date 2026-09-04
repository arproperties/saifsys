-- ============================================================================
-- Real Estate Historical Income Reclassification (additive / idempotent)
-- ----------------------------------------------------------------------------
-- Corrects misposted invoice income via separate adjustment journals:
--   Dr Wrong Income / Cr Correct Income
-- Original invoice journals, AR, VAT, receipts, allocations are never modified.
-- Apply only after explicit human approval (localhost first).
-- ============================================================================

CREATE TABLE IF NOT EXISTS `re_income_reclass_sessions` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `company_id` INT(11) NOT NULL,
    `session_number` VARCHAR(40) NOT NULL,
    `status` ENUM('draft','dry_run_passed','completed','partial','reversed') NOT NULL DEFAULT 'draft',
    `correction_date` DATE DEFAULT NULL COMMENT 'Open-period date used when original journal date is locked',
    `selected_count` INT NOT NULL DEFAULT 0,
    `repaired_count` INT NOT NULL DEFAULT 0,
    `failed_count` INT NOT NULL DEFAULT 0,
    `skipped_count` INT NOT NULL DEFAULT 0,
    `total_amount` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    `dry_run_json` LONGTEXT DEFAULT NULL,
    `recon_before_json` LONGTEXT DEFAULT NULL,
    `recon_after_json` LONGTEXT DEFAULT NULL,
    `repair_journal_id_min` INT(11) DEFAULT NULL,
    `repair_journal_id_max` INT(11) DEFAULT NULL,
    `notes` TEXT DEFAULT NULL,
    `created_by` INT(11) DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `executed_at` DATETIME DEFAULT NULL,
    `reversed_by` INT(11) DEFAULT NULL,
    `reversed_at` DATETIME DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_re_income_reclass_session_number` (`company_id`, `session_number`),
    KEY `idx_re_income_reclass_sess_company_status` (`company_id`, `status`),
    KEY `idx_re_income_reclass_sess_created` (`company_id`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Historical income reclass repair sessions (audit only; no invoice mutation).';

CREATE TABLE IF NOT EXISTS `re_income_reclass_lines` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `repair_session_id` BIGINT UNSIGNED NOT NULL,
    `company_id` INT(11) NOT NULL,
    `candidate_key` VARCHAR(120) NOT NULL COMMENT 'Permanent key: item:journal:wrong:correct',
    `active_fingerprint` VARCHAR(120) DEFAULT NULL COMMENT 'Set only while repair active; cleared after reverse posts',
    `invoice_id` INT(11) NOT NULL,
    `invoice_item_id` BIGINT UNSIGNED DEFAULT NULL,
    `original_journal_id` INT(11) NOT NULL,
    `obligation_id` BIGINT UNSIGNED DEFAULT NULL,
    `wrong_account_id` INT(11) NOT NULL,
    `correct_account_id` INT(11) NOT NULL,
    `amount` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    `repair_journal_id` INT(11) DEFAULT NULL,
    `reversal_journal_id` INT(11) DEFAULT NULL,
    `correction_date` DATE DEFAULT NULL,
    `original_journal_date` DATE DEFAULT NULL,
    `posted_account_code` VARCHAR(20) DEFAULT NULL,
    `expected_account_code` VARCHAR(20) DEFAULT NULL,
    `obligation_type` VARCHAR(40) DEFAULT NULL,
    `accounting_class` VARCHAR(40) DEFAULT NULL,
    `item_name` VARCHAR(255) DEFAULT NULL,
    `invoice_number` VARCHAR(50) DEFAULT NULL,
    `journal_number` VARCHAR(50) DEFAULT NULL,
    `status` ENUM('selected','skipped','failed','repaired','reversed') NOT NULL DEFAULT 'selected',
    `error_message` VARCHAR(500) DEFAULT NULL,
    `created_by` INT(11) DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `reversed_by` INT(11) DEFAULT NULL,
    `reversed_at` DATETIME DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_re_income_reclass_active_fp` (`company_id`, `active_fingerprint`),
    KEY `idx_re_income_reclass_line_session` (`repair_session_id`, `status`),
    KEY `idx_re_income_reclass_line_candidate` (`company_id`, `candidate_key`),
    KEY `idx_re_income_reclass_line_invoice` (`company_id`, `invoice_id`),
    KEY `idx_re_income_reclass_line_orig_jrn` (`company_id`, `original_journal_id`),
    KEY `idx_re_income_reclass_line_repair_jrn` (`repair_journal_id`),
    CONSTRAINT `fk_re_income_reclass_line_session`
        FOREIGN KEY (`repair_session_id`) REFERENCES `re_income_reclass_sessions` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Per-candidate income reclass audit lines; active_fingerprint prevents duplicate active repairs.';
