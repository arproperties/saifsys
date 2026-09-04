-- ============================================================================
-- Invoice Mode: multi-cheque link on one receipt
-- ----------------------------------------------------------------------------
-- Purpose:
--   Allow one cleared bank/cash/card receipt to explicitly settle multiple
--   operational schedule cheques (e.g. one transfer covering two PDCs).
--
-- Safety:
--   - Additive only (CREATE TABLE IF NOT EXISTS).
--   - Does not alter re_payments.cheque_id behaviour for single-cheque Allocate Payment.
--   - Single-cheque receipts continue to use re_payments.cheque_id only.
--   - Multi-cheque receipts leave cheque_id NULL and use this junction table.
--
-- Rollback intent:
--   DROP TABLE IF EXISTS re_receipt_cheque_links;
--   (Only after confirming no multi-cheque receipts remain in use.)
-- ============================================================================

CREATE TABLE IF NOT EXISTS `re_receipt_cheque_links` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `company_id` INT(11) NOT NULL,
    `payment_id` INT(11) NOT NULL COMMENT 'Invoice Mode cleared receipt (re_payments.id)',
    `cheque_id` INT(11) NOT NULL COMMENT 'Operational cheque / PDC (re_post_dated_cheques.id)',
    `amount_applied` DECIMAL(15,2) NOT NULL DEFAULT 0.00 COMMENT 'Face share of this receipt applied toward the cheque',
    `created_by` INT(11) DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_re_receipt_cheque_link` (`company_id`, `payment_id`, `cheque_id`),
    KEY `idx_re_receipt_cheque_payment` (`company_id`, `payment_id`),
    KEY `idx_re_receipt_cheque_cheque` (`company_id`, `cheque_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Multi-cheque links for one Invoice Mode receipt. Single-cheque Allocate Payment does not write here.';
