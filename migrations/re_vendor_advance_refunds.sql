-- ============================================================================
-- Real Estate Vendor Advance Refunds (M7) — additive / idempotent
-- ----------------------------------------------------------------------------
-- Refund unused vendor advances to bank/cash: Dr Bank / Cr 1410.
-- Separate document from vendor payments and bills. re_* stack only.
-- ============================================================================

CREATE TABLE IF NOT EXISTS `re_vendor_advance_refunds` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `company_id` INT(11) NOT NULL,
    `vendor_id` INT(11) NOT NULL,
    `vendor_payment_id` BIGINT UNSIGNED NOT NULL,
    `refund_date` DATE NOT NULL,
    `amount` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    `bank_account_id` INT(11) DEFAULT NULL,
    `reference_number` VARCHAR(100) DEFAULT NULL,
    `notes` TEXT DEFAULT NULL,
    `journal_id` INT(11) DEFAULT NULL,
    `status` ENUM('posted','reversed') NOT NULL DEFAULT 'posted',
    `posted_by` INT(11) DEFAULT NULL,
    `posted_at` DATETIME DEFAULT NULL,
    `reversed_by` INT(11) DEFAULT NULL,
    `reversed_at` DATETIME DEFAULT NULL,
    `reversal_journal_id` INT(11) DEFAULT NULL,
    `created_by` INT(11) DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_re_adv_refund_payment` (`company_id`, `vendor_payment_id`, `status`),
    KEY `idx_re_adv_refund_vendor` (`company_id`, `vendor_id`, `status`),
    KEY `idx_re_adv_refund_journal` (`journal_id`),
    KEY `idx_re_adv_refund_status` (`company_id`, `status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Refunds of unused vendor advances to bank/cash (Dr Bank / Cr 1410).';
