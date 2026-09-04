-- ============================================================================
-- Real Estate Accounting Transformation - Phase 3.5 Lease Operations
-- ----------------------------------------------------------------------------
-- Purpose:
--   Operational payment/cheque schedule safeguards and audit trail.
--
-- Safety:
--   - Additive only.
--   - No historical conversion.
--   - No invoice, receipt, journal, or recognition changes.
-- ============================================================================

INSERT INTO `settings` (`key`, `value`)
VALUES ('re_payment_schedule_validation_mode', 'authorized_override')
ON DUPLICATE KEY UPDATE `value` = `value`;

CREATE TABLE IF NOT EXISTS `re_lease_payment_schedule_audit` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `company_id` INT(11) NOT NULL,
    `lease_id` INT(11) NOT NULL,
    `schedule_row_id` INT(11) DEFAULT NULL,
    `cheque_id` INT(11) DEFAULT NULL,
    `field_name` VARCHAR(100) NOT NULL,
    `old_value` TEXT DEFAULT NULL,
    `new_value` TEXT DEFAULT NULL,
    `changed_by` INT(11) DEFAULT NULL,
    `changed_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `reason` VARCHAR(255) DEFAULT NULL,
    `source` VARCHAR(80) NOT NULL DEFAULT 'lease_add',
    `accounting_mode` ENUM('legacy','invoice') NOT NULL DEFAULT 'legacy',
    PRIMARY KEY (`id`),
    KEY `idx_re_schedule_audit_lease` (`company_id`, `lease_id`, `changed_at`),
    KEY `idx_re_schedule_audit_row` (`schedule_row_id`),
    KEY `idx_re_schedule_audit_cheque` (`cheque_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Audit trail for operational lease payment schedule row changes.';

CREATE TABLE IF NOT EXISTS `re_lease_payment_schedule_mismatch_approvals` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `company_id` INT(11) NOT NULL,
    `lease_id` INT(11) NOT NULL,
    `expected_total` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    `scheduled_total` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    `difference_amount` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    `approved_by` INT(11) DEFAULT NULL,
    `approved_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `reason` VARCHAR(255) NOT NULL,
    `source_page` VARCHAR(80) NOT NULL DEFAULT 'lease_add',
    `accounting_mode` ENUM('legacy','invoice') NOT NULL DEFAULT 'legacy',
    PRIMARY KEY (`id`),
    KEY `idx_re_schedule_mismatch_lease` (`company_id`, `lease_id`, `approved_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Authorised approvals for operational schedule total mismatches.';

ALTER TABLE `re_post_dated_cheques`
    ADD COLUMN IF NOT EXISTS `payment_method` VARCHAR(30) NOT NULL DEFAULT 'cheque' AFTER `account_holder_name`,
    ADD COLUMN IF NOT EXISTS `reference_number` VARCHAR(100) DEFAULT NULL AFTER `cheque_number`;

ALTER TABLE `re_lease_cheques`
    ADD COLUMN IF NOT EXISTS `company_id` INT(11) DEFAULT NULL AFTER `id`,
    ADD COLUMN IF NOT EXISTS `bank_name` VARCHAR(255) DEFAULT NULL AFTER `cheque_holder_name`,
    ADD COLUMN IF NOT EXISTS `reference_number` VARCHAR(100) DEFAULT NULL AFTER `cheque_number`;

ALTER TABLE `re_lease_cheques`
    MODIFY COLUMN `payment_method` ENUM('cheque','cash','bank_transfer','card','other') NOT NULL DEFAULT 'cheque';

