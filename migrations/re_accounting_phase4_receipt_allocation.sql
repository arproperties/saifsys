-- ============================================================================
-- Real Estate Accounting Transformation - Phase 4 Receipt & Allocation Engine
-- ----------------------------------------------------------------------------
-- Purpose:
--   Add Invoice Mode receipt metadata and allocation tables. Receipts are created
--   only when funds clear, then allocated to issued invoices, obligations, or
--   tenant credit.
--
-- Safety:
--   - No historical receipt/payment conversion.
--   - No cheque behavior changes.
--   - No report changes.
--   - No journal/GL posting.
--   - Legacy lease rows remain compatible.
-- ============================================================================

ALTER TABLE `re_payments`
    MODIFY COLUMN `payment_method` ENUM('cash', 'bank_transfer', 'cheque', 'auto_debit', 'cash_deposit', 'card') NOT NULL DEFAULT 'bank_transfer';

ALTER TABLE `re_payments`
    ADD COLUMN IF NOT EXISTS `accounting_mode` ENUM('legacy','invoice') DEFAULT NULL
        COMMENT 'Invoice Mode receipt marker; legacy rows remain NULL/legacy' AFTER `company_id`,
    ADD COLUMN IF NOT EXISTS `receipt_source` ENUM('cleared_cheque','bank_transfer','cash','card') DEFAULT NULL
        COMMENT 'Phase 4 cleared money source' AFTER `payment_method`,
    ADD COLUMN IF NOT EXISTS `receipt_status` ENUM('cleared','cancelled') DEFAULT NULL
        COMMENT 'Phase 4 receipt status. Only cleared creates allocation capacity.' AFTER `receipt_source`,
    ADD COLUMN IF NOT EXISTS `cleared_date` DATE DEFAULT NULL
        COMMENT 'Date funds cleared and became receiptable' AFTER `receipt_status`,
    ADD COLUMN IF NOT EXISTS `tenant_id` INT(11) DEFAULT NULL
        COMMENT 'Denormalized tenant link for Invoice Mode receipts' AFTER `lease_id`,
    ADD COLUMN IF NOT EXISTS `unit_id` INT(11) DEFAULT NULL
        COMMENT 'Denormalized unit link for Invoice Mode receipts' AFTER `tenant_id`,
    ADD COLUMN IF NOT EXISTS `receipt_account_type` ENUM('bank','cash','card_clearing') DEFAULT NULL
        COMMENT 'Optional account type where cleared funds were received' AFTER `payment_method`,
    ADD COLUMN IF NOT EXISTS `receipt_account_id` INT(11) DEFAULT NULL
        COMMENT 'Optional bank/cash/card clearing account id for Phase 4 receipts' AFTER `receipt_account_type`,
    ADD COLUMN IF NOT EXISTS `allocation_status` ENUM('unallocated','partial','allocated','overpaid') DEFAULT NULL
        COMMENT 'Phase 4 receipt allocation state' AFTER `receipt_status`;

CREATE INDEX IF NOT EXISTS `idx_re_payments_invoice_mode_receipts`
    ON `re_payments` (`company_id`, `accounting_mode`, `receipt_status`, `cleared_date`);

CREATE INDEX IF NOT EXISTS `idx_re_payments_tenant_receipts`
    ON `re_payments` (`company_id`, `tenant_id`, `allocation_status`);

CREATE INDEX IF NOT EXISTS `idx_re_payments_receipt_account`
    ON `re_payments` (`company_id`, `receipt_account_type`, `receipt_account_id`);

CREATE TABLE IF NOT EXISTS `re_receipt_sequences` (
    `company_id` INT(11) NOT NULL,
    `year` INT(11) NOT NULL,
    `prefix` VARCHAR(20) NOT NULL DEFAULT 'REC',
    `sequence_number` INT(11) NOT NULL DEFAULT 0,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`company_id`, `year`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Phase 4 receipt number sequence. Used only when cleared money becomes a receipt.';

CREATE TABLE IF NOT EXISTS `re_receipt_allocations` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `company_id` INT(11) NOT NULL,
    `payment_id` INT(11) NOT NULL,
    `lease_id` INT(11) NOT NULL,
    `tenant_id` INT(11) DEFAULT NULL,
    `target_type` ENUM('invoice','obligation','tenant_credit') NOT NULL,
    `invoice_id` INT(11) DEFAULT NULL,
    `obligation_id` BIGINT UNSIGNED DEFAULT NULL,
    `amount_allocated` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    `notes` VARCHAR(255) DEFAULT NULL,
    `created_by` INT(11) DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_re_receipt_alloc_target` (`company_id`, `payment_id`, `target_type`, `invoice_id`, `obligation_id`),
    KEY `idx_re_receipt_alloc_payment` (`company_id`, `payment_id`),
    KEY `idx_re_receipt_alloc_lease` (`company_id`, `lease_id`),
    KEY `idx_re_receipt_alloc_invoice` (`invoice_id`),
    KEY `idx_re_receipt_alloc_obligation` (`obligation_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Phase 4 allocations from cleared Invoice Mode receipts to invoices, obligations, or tenant credit.';

