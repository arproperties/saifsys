-- ============================================================================
-- Real Estate Accounting Transformation - Phase 6 Cheque Engine Refactor
-- ----------------------------------------------------------------------------
-- Purpose:
--   Cheques become operational payment instruments. Cleared funds for Invoice
--   Mode leases must flow through receipts and allocations.
--
-- Safety:
--   - Additive where possible.
--   - No historical conversion.
--   - No invoice, receipt, journal, or report changes in migration.
-- ============================================================================

INSERT INTO `settings` (`key`, `value`)
VALUES
    ('re_invoice_mode_cheque_clear_requires_receipt', '1'),
    ('re_default_bounced_cheque_fee', '500')
ON DUPLICATE KEY UPDATE `value` = `value`;

ALTER TABLE `re_post_dated_cheques`
    MODIFY COLUMN `status` ENUM(
        'draft',
        'pending',
        'collected',
        'hold',
        'held_by_finance',
        'deposited',
        'cleared',
        'bounced',
        'replaced',
        'cancelled',
        'returned',
        'legal_escalated'
    ) NOT NULL DEFAULT 'pending';

ALTER TABLE `re_lease_cheques`
    MODIFY COLUMN `status` ENUM(
        'draft',
        'pending',
        'collected',
        'hold',
        'held_by_finance',
        'deposited',
        'cleared',
        'bounced',
        'replaced',
        'cancelled',
        'returned',
        'legal_escalated'
    ) NOT NULL DEFAULT 'pending';

ALTER TABLE `re_post_dated_cheques`
    ADD COLUMN IF NOT EXISTS `replacement_for_cheque_id` INT(11) DEFAULT NULL AFTER `payment_id`,
    ADD COLUMN IF NOT EXISTS `replaced_by_cheque_id` INT(11) DEFAULT NULL AFTER `replacement_for_cheque_id`,
    ADD COLUMN IF NOT EXISTS `settlement_method` VARCHAR(30) DEFAULT NULL AFTER `replaced_by_cheque_id`,
    ADD COLUMN IF NOT EXISTS `settlement_reference` VARCHAR(100) DEFAULT NULL AFTER `settlement_method`,
    ADD COLUMN IF NOT EXISTS `settlement_payment_id` INT(11) DEFAULT NULL AFTER `settlement_reference`;

ALTER TABLE `re_lease_cheques`
    ADD COLUMN IF NOT EXISTS `replacement_for_cheque_id` INT(11) DEFAULT NULL AFTER `status`,
    ADD COLUMN IF NOT EXISTS `replaced_by_cheque_id` INT(11) DEFAULT NULL AFTER `replacement_for_cheque_id`,
    ADD COLUMN IF NOT EXISTS `settlement_method` VARCHAR(30) DEFAULT NULL AFTER `replaced_by_cheque_id`,
    ADD COLUMN IF NOT EXISTS `settlement_reference` VARCHAR(100) DEFAULT NULL AFTER `settlement_method`,
    ADD COLUMN IF NOT EXISTS `settlement_payment_id` INT(11) DEFAULT NULL AFTER `settlement_reference`;

CREATE TABLE IF NOT EXISTS `re_cheque_lifecycle_audit` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `company_id` INT(11) NOT NULL,
    `cheque_id` INT(11) NOT NULL,
    `lease_id` INT(11) NOT NULL,
    `old_status` VARCHAR(30) DEFAULT NULL,
    `new_status` VARCHAR(30) NOT NULL,
    `changed_by` INT(11) DEFAULT NULL,
    `changed_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `reason` VARCHAR(255) DEFAULT NULL,
    `source_page` VARCHAR(80) NOT NULL DEFAULT 'system',
    `related_receipt_id` INT(11) DEFAULT NULL,
    `replacement_cheque_id` INT(11) DEFAULT NULL,
    `accounting_mode` ENUM('legacy','invoice') NOT NULL DEFAULT 'legacy',
    PRIMARY KEY (`id`),
    KEY `idx_re_cheque_lifecycle_cheque` (`company_id`, `cheque_id`, `changed_at`),
    KEY `idx_re_cheque_lifecycle_lease` (`company_id`, `lease_id`, `changed_at`),
    KEY `idx_re_cheque_lifecycle_receipt` (`related_receipt_id`),
    KEY `idx_re_cheque_lifecycle_replacement` (`replacement_cheque_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Audit trail for cheque lifecycle status changes and replacement links.';

