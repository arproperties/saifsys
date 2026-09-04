-- ============================================================================
-- Real Estate Accounting Transformation - Phase 7 Security Deposit Controls
-- ----------------------------------------------------------------------------
-- Purpose:
--   Add controlled security deposit audit, settlement, diagnostics, and account
--   settings. Security deposits are refundable liabilities, not revenue.
--
-- Safety:
--   - Additive only.
--   - No historical reposting.
--   - No mass conversion or correction.
-- ============================================================================

INSERT INTO `settings` (`key`, `value`)
VALUES
    ('re_security_deposit_liability_account_code', '2200'),
    ('re_deposit_damage_recovery_account_code', '4300'),
    ('re_deposit_forfeiture_income_account_code', '4400'),
    ('re_deposit_refund_requires_approval', '1'),
    ('re_deposit_deduction_requires_approval', '1')
ON DUPLICATE KEY UPDATE `value` = `value`;

CREATE TABLE IF NOT EXISTS `re_security_deposit_audit` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `company_id` INT(11) NOT NULL,
    `lease_id` INT(11) NOT NULL,
    `tenant_id` INT(11) DEFAULT NULL,
    `action_type` VARCHAR(80) NOT NULL,
    `old_value` TEXT DEFAULT NULL,
    `new_value` TEXT DEFAULT NULL,
    `amount` DECIMAL(15,2) DEFAULT NULL,
    `reason` VARCHAR(255) DEFAULT NULL,
    `changed_by` INT(11) DEFAULT NULL,
    `changed_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `source` VARCHAR(80) NOT NULL DEFAULT 'system',
    `related_receipt_id` INT(11) DEFAULT NULL,
    `related_journal_id` INT(11) DEFAULT NULL,
    `related_move_out_id` INT(11) DEFAULT NULL,
    `related_obligation_id` BIGINT UNSIGNED DEFAULT NULL,
    `related_refund_id` INT(11) DEFAULT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_re_sd_audit_lease` (`company_id`, `lease_id`, `changed_at`),
    KEY `idx_re_sd_audit_action` (`company_id`, `action_type`),
    KEY `idx_re_sd_audit_receipt` (`related_receipt_id`),
    KEY `idx_re_sd_audit_journal` (`related_journal_id`),
    KEY `idx_re_sd_audit_move_out` (`related_move_out_id`),
    KEY `idx_re_sd_audit_obligation` (`related_obligation_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Audit trail for security deposit lifecycle actions.';

CREATE TABLE IF NOT EXISTS `re_security_deposit_deductions` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `company_id` INT(11) NOT NULL,
    `lease_id` INT(11) NOT NULL,
    `tenant_id` INT(11) DEFAULT NULL,
    `move_out_id` INT(11) DEFAULT NULL,
    `deduction_type` ENUM('damage','cleaning','repair','unpaid_invoice_offset','admin_fee','forfeiture','other') NOT NULL,
    `amount` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    `reason` VARCHAR(255) NOT NULL,
    `supporting_document_path` VARCHAR(500) DEFAULT NULL,
    `status` ENUM('draft','approved','rejected','applied','cancelled') NOT NULL DEFAULT 'draft',
    `approved_by` INT(11) DEFAULT NULL,
    `approved_at` DATETIME DEFAULT NULL,
    `tenant_notified` TINYINT(1) NOT NULL DEFAULT 0,
    `related_invoice_id` INT(11) DEFAULT NULL,
    `journal_id` INT(11) DEFAULT NULL,
    `created_by` INT(11) DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_re_sd_deduct_lease` (`company_id`, `lease_id`),
    KEY `idx_re_sd_deduct_move_out` (`move_out_id`),
    KEY `idx_re_sd_deduct_status` (`company_id`, `status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Controlled security deposit deductions for move-out settlement.';

CREATE TABLE IF NOT EXISTS `re_security_deposit_settlements` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `company_id` INT(11) NOT NULL,
    `lease_id` INT(11) NOT NULL,
    `tenant_id` INT(11) DEFAULT NULL,
    `move_out_id` INT(11) DEFAULT NULL,
    `expected_amount` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    `received_amount` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    `deduction_amount` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    `refund_amount` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    `retained_amount` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    `status` ENUM('draft','approved','refunded','partially_refunded','deducted','forfeited','cancelled') NOT NULL DEFAULT 'draft',
    `approved_by` INT(11) DEFAULT NULL,
    `approved_at` DATETIME DEFAULT NULL,
    `refund_id` INT(11) DEFAULT NULL,
    `journal_id` INT(11) DEFAULT NULL,
    `notes` TEXT DEFAULT NULL,
    `created_by` INT(11) DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_re_sd_settle_lease` (`company_id`, `lease_id`),
    KEY `idx_re_sd_settle_move_out` (`move_out_id`),
    KEY `idx_re_sd_settle_status` (`company_id`, `status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Security deposit move-out settlement summary and approval record.';
