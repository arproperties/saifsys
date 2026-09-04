-- Construction Suppliers / AP — Phase 1 document lifecycle
-- Strict immutability: Draft → Posted → Partially Paid → Paid → Voided
-- Does NOT modify RE vendor tables or accounting_engine.
-- Human-applied. Run after construction_supplier_ap_phase0.sql.

ALTER TABLE `co_supplier_invoices`
  ADD COLUMN IF NOT EXISTS `status` VARCHAR(20) NOT NULL DEFAULT 'draft'
    COMMENT 'draft|posted|partially_paid|paid|voided'
    AFTER `journal_id`,
  ADD COLUMN IF NOT EXISTS `amended_from_invoice_id` INT(11) DEFAULT NULL
    COMMENT 'Source invoice when created via Void+Amend'
    AFTER `status`,
  ADD COLUMN IF NOT EXISTS `void_reason` VARCHAR(255) DEFAULT NULL
    AFTER `amended_from_invoice_id`,
  ADD COLUMN IF NOT EXISTS `voided_at` DATETIME DEFAULT NULL
    AFTER `void_reason`,
  ADD COLUMN IF NOT EXISTS `voided_by` INT(11) DEFAULT NULL
    AFTER `voided_at`;

CREATE INDEX IF NOT EXISTS `idx_co_supplier_inv_status`
  ON `co_supplier_invoices` (`company_id`, `status`, `invoice_date`);

CREATE INDEX IF NOT EXISTS `idx_co_supplier_inv_amended_from`
  ON `co_supplier_invoices` (`company_id`, `amended_from_invoice_id`);

-- Backfill GL presence only (allocation-based paid/partial refreshed by app helpers)
UPDATE `co_supplier_invoices`
SET `status` = 'draft'
WHERE `journal_id` IS NULL
  AND COALESCE(`status`, 'draft') NOT IN ('voided');

UPDATE `co_supplier_invoices`
SET `status` = 'posted'
WHERE `journal_id` IS NOT NULL
  AND COALESCE(`status`, 'draft') NOT IN ('voided', 'paid', 'partially_paid');
