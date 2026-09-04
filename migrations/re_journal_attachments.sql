-- Real Estate Accounting: attachments for manual journal entries.
-- Mirrors re_vendor_bill_attachments (see re_accounting_phase95_vendor_ap_cleanup.sql).
-- Files are stored under uploads/journal_attachments and served through
-- modules/realestate/accounting/journal_attachment_file.php (never linked directly).

CREATE TABLE IF NOT EXISTS `re_journal_attachments` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `company_id` INT(11) NOT NULL,
    `journal_id` INT(11) NOT NULL,
    `file_name` VARCHAR(255) NOT NULL,
    `file_path` VARCHAR(500) NOT NULL,
    `mime_type` VARCHAR(100) DEFAULT NULL,
    `file_size` INT(11) DEFAULT NULL,
    `uploaded_by` INT(11) DEFAULT NULL,
    `uploaded_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_re_journal_attach` (`company_id`, `journal_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Attachments for Real Estate manual journal entries.';
