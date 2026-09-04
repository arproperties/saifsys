-- Construction Suppliers / AP — Phase 3 Advance VAT Documents + Refunds
-- Document-driven Input VAT (Dr 2130 / Cr Advances) and unused advance refunds (Dr Bank / Cr Advances).
-- Does NOT modify RE vendor tables or accounting_engine.
-- Human-applied. Run after construction_supplier_ap_phase2_advances.sql.

CREATE TABLE IF NOT EXISTS `co_supplier_advance_vat_documents` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `company_id` INT(11) NOT NULL,
    `supplier_id` INT(11) NOT NULL,
    `supplier_payment_id` INT(11) NOT NULL,
    `supplier_invoice_number` VARCHAR(100) NOT NULL,
    `supplier_invoice_date` DATE NOT NULL,
    `supplier_trn` VARCHAR(50) DEFAULT NULL,
    `taxable_amount` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    `vat_amount` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    `gross_amount` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    `currency_code` CHAR(3) NOT NULL DEFAULT 'AED',
    `journal_id` INT(11) DEFAULT NULL,
    `status` ENUM('draft','posted','reversed') NOT NULL DEFAULT 'draft',
    `notes` TEXT DEFAULT NULL,
    `posted_by` INT(11) DEFAULT NULL,
    `posted_at` DATETIME DEFAULT NULL,
    `reversed_by` INT(11) DEFAULT NULL,
    `reversed_at` DATETIME DEFAULT NULL,
    `reversal_journal_id` INT(11) DEFAULT NULL,
    `created_by` INT(11) DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_co_adv_vat_supplier_inv` (`company_id`, `supplier_id`, `supplier_invoice_number`),
    KEY `idx_co_adv_vat_payment` (`company_id`, `supplier_payment_id`, `status`),
    KEY `idx_co_adv_vat_supplier` (`company_id`, `supplier_id`, `status`),
    KEY `idx_co_adv_vat_journal` (`journal_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Supplier advance tax invoices linked to advance payments.';

CREATE TABLE IF NOT EXISTS `co_supplier_advance_vat_attachments` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `company_id` INT(11) NOT NULL,
    `advance_vat_document_id` BIGINT UNSIGNED NOT NULL,
    `file_name` VARCHAR(255) NOT NULL,
    `file_path` VARCHAR(500) NOT NULL,
    `mime_type` VARCHAR(100) DEFAULT NULL,
    `file_size` INT(11) DEFAULT NULL,
    `uploaded_by` INT(11) DEFAULT NULL,
    `uploaded_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_co_adv_vat_attach_doc` (`company_id`, `advance_vat_document_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Attachments for supplier advance VAT documents.';

CREATE TABLE IF NOT EXISTS `co_supplier_advance_vat_invoice_links` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `company_id` INT(11) NOT NULL,
    `advance_vat_document_id` BIGINT UNSIGNED NOT NULL,
    `supplier_invoice_id` INT(11) NOT NULL,
    `vat_amount_linked` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    `taxable_amount_linked` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    `status` ENUM('posted','reversed') NOT NULL DEFAULT 'posted',
    `created_by` INT(11) DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `reversed_by` INT(11) DEFAULT NULL,
    `reversed_at` DATETIME DEFAULT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_co_adv_vat_link_doc` (`company_id`, `advance_vat_document_id`, `status`),
    KEY `idx_co_adv_vat_link_inv` (`company_id`, `supplier_invoice_id`, `status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Consumption of advance VAT documents against final supplier invoices.';

CREATE TABLE IF NOT EXISTS `co_supplier_advance_refunds` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `company_id` INT(11) NOT NULL,
    `supplier_id` INT(11) NOT NULL,
    `supplier_payment_id` INT(11) NOT NULL,
    `refund_date` DATE NOT NULL,
    `amount` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    `pay_account_id` INT(11) DEFAULT NULL,
    `reference` VARCHAR(100) DEFAULT NULL,
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
    KEY `idx_co_adv_refund_payment` (`company_id`, `supplier_payment_id`, `status`),
    KEY `idx_co_adv_refund_supplier` (`company_id`, `supplier_id`, `status`),
    KEY `idx_co_adv_refund_journal` (`journal_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Refunds of unused supplier advances to bank/cash (Dr Bank / Cr Advances).';
