-- ============================================================================
-- Real Estate Accounting Transformation - Phase 9.5 Vendor AP Cleanup
-- ----------------------------------------------------------------------------
-- Purpose:
--   Upgrade Real Estate vendor bills/AP workflow with professional bill status,
--   line-level accounts/VAT, vendor payments, allocations, attachments, and audit.
--
-- Safety:
--   - Additive only.
--   - No old ERP expense deletion or migration.
--   - No historical reposting.
-- ============================================================================

ALTER TABLE `re_vendor_invoices`
    ADD COLUMN IF NOT EXISTS `order_number` VARCHAR(100) DEFAULT NULL AFTER `invoice_number`,
    ADD COLUMN IF NOT EXISTS `permit_number` VARCHAR(100) DEFAULT NULL AFTER `order_number`,
    ADD COLUMN IF NOT EXISTS `place_of_supply` VARCHAR(100) DEFAULT 'Dubai' AFTER `permit_number`,
    ADD COLUMN IF NOT EXISTS `vat_treatment` ENUM('vat_registered','non_vat','exempt','out_of_scope') NOT NULL DEFAULT 'vat_registered' AFTER `place_of_supply`,
    ADD COLUMN IF NOT EXISTS `payment_terms` VARCHAR(100) DEFAULT NULL AFTER `due_date`,
    ADD COLUMN IF NOT EXISTS `posting_status` ENUM('not_posted','posted','reversed') NOT NULL DEFAULT 'not_posted' AFTER `status`,
    ADD COLUMN IF NOT EXISTS `journal_id` INT(11) DEFAULT NULL AFTER `posting_status`,
    ADD COLUMN IF NOT EXISTS `paid_amount` DECIMAL(15,2) NOT NULL DEFAULT 0.00 AFTER `total_amount`,
    ADD COLUMN IF NOT EXISTS `balance_due` DECIMAL(15,2) NOT NULL DEFAULT 0.00 AFTER `paid_amount`;

ALTER TABLE `re_vendor_invoice_items`
    ADD COLUMN IF NOT EXISTS `expense_account_id` INT(11) DEFAULT NULL AFTER `invoice_id`,
    ADD COLUMN IF NOT EXISTS `building_id` INT(11) DEFAULT NULL AFTER `expense_account_id`,
    ADD COLUMN IF NOT EXISTS `unit_id` INT(11) DEFAULT NULL AFTER `building_id`,
    ADD COLUMN IF NOT EXISTS `lease_id` INT(11) DEFAULT NULL AFTER `unit_id`,
    ADD COLUMN IF NOT EXISTS `line_description` VARCHAR(500) DEFAULT NULL AFTER `description`,
    ADD COLUMN IF NOT EXISTS `subtotal` DECIMAL(15,2) NOT NULL DEFAULT 0.00 AFTER `unit_price`,
    ADD COLUMN IF NOT EXISTS `vat_treatment` ENUM('standard','exempt','zero_rated','out_of_scope') NOT NULL DEFAULT 'standard' AFTER `subtotal`,
    ADD COLUMN IF NOT EXISTS `vat_rate` DECIMAL(8,4) NOT NULL DEFAULT 0.0000 AFTER `vat_treatment`,
    ADD COLUMN IF NOT EXISTS `vat_amount` DECIMAL(15,2) NOT NULL DEFAULT 0.00 AFTER `vat_rate`,
    ADD COLUMN IF NOT EXISTS `line_total` DECIMAL(15,2) NOT NULL DEFAULT 0.00 AFTER `vat_amount`;


ALTER TABLE `re_vendor_invoices`
    MODIFY COLUMN `status` ENUM('draft','open','pending','approved','partially_paid','paid','rejected','cancelled','void') NOT NULL DEFAULT 'draft';

CREATE TABLE IF NOT EXISTS `re_vendor_payments` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `company_id` INT(11) NOT NULL,
    `vendor_id` INT(11) NOT NULL,
    `payment_date` DATE NOT NULL,
    `amount` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    `payment_method` ENUM('cash','bank_transfer','cheque','card','other') NOT NULL DEFAULT 'bank_transfer',
    `bank_account_id` INT(11) DEFAULT NULL,
    `reference_number` VARCHAR(100) DEFAULT NULL,
    `notes` TEXT DEFAULT NULL,
    `journal_id` INT(11) DEFAULT NULL,
    `status` ENUM('draft','posted','void') NOT NULL DEFAULT 'posted',
    `created_by` INT(11) DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_re_vendor_pay_company` (`company_id`, `payment_date`),
    KEY `idx_re_vendor_pay_vendor` (`company_id`, `vendor_id`),
    KEY `idx_re_vendor_pay_bank` (`bank_account_id`),
    KEY `idx_re_vendor_pay_journal` (`journal_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Vendor payments made against Real Estate AP bills.';

CREATE TABLE IF NOT EXISTS `re_vendor_payment_allocations` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `company_id` INT(11) NOT NULL,
    `vendor_payment_id` BIGINT UNSIGNED NOT NULL,
    `vendor_invoice_id` INT(11) NOT NULL,
    `amount_allocated` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_re_vendor_payment_bill` (`company_id`, `vendor_payment_id`, `vendor_invoice_id`),
    KEY `idx_re_vendor_alloc_bill` (`company_id`, `vendor_invoice_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Allocations from vendor payments to vendor bills.';

CREATE TABLE IF NOT EXISTS `re_vendor_bill_attachments` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `company_id` INT(11) NOT NULL,
    `vendor_invoice_id` INT(11) NOT NULL,
    `file_name` VARCHAR(255) NOT NULL,
    `file_path` VARCHAR(500) NOT NULL,
    `mime_type` VARCHAR(100) DEFAULT NULL,
    `file_size` INT(11) DEFAULT NULL,
    `uploaded_by` INT(11) DEFAULT NULL,
    `uploaded_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_re_vendor_bill_attach` (`company_id`, `vendor_invoice_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Attachments for Real Estate vendor bills.';

CREATE TABLE IF NOT EXISTS `re_vendor_ap_audit` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `company_id` INT(11) NOT NULL,
    `vendor_id` INT(11) DEFAULT NULL,
    `vendor_invoice_id` INT(11) DEFAULT NULL,
    `vendor_payment_id` BIGINT UNSIGNED DEFAULT NULL,
    `action_type` VARCHAR(80) NOT NULL,
    `old_value` TEXT DEFAULT NULL,
    `new_value` TEXT DEFAULT NULL,
    `amount` DECIMAL(15,2) DEFAULT NULL,
    `reason` VARCHAR(255) DEFAULT NULL,
    `changed_by` INT(11) DEFAULT NULL,
    `changed_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `source` VARCHAR(80) NOT NULL DEFAULT 'system',
    `related_journal_id` INT(11) DEFAULT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_re_vendor_ap_audit_bill` (`company_id`, `vendor_invoice_id`, `changed_at`),
    KEY `idx_re_vendor_ap_audit_pay` (`company_id`, `vendor_payment_id`, `changed_at`),
    KEY `idx_re_vendor_ap_audit_action` (`company_id`, `action_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Audit trail for Real Estate vendor/AP actions.';

INSERT INTO `settings` (`key`, `value`)
VALUES
    ('re_vendor_ap_account_code', '2130'),
    ('re_vendor_input_vat_account_code', '2320'),
    ('re_vendor_default_expense_account_code', '5100')
ON DUPLICATE KEY UPDATE `value` = `value`;
