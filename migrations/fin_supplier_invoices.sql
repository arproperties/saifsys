-- Finance > Supplier Invoices (voucher type si). The voucher is the header and
-- posts its lines like any other (expense Dr, Input VAT Dr, supplier Cr); this
-- adds what only a supplier invoice has: the supplier, its own invoice number,
-- the VAT rate and the split of the amount. Suppliers come from Vendor
-- Management (re_vendors).
-- fin_voucher_attachments holds the scanned invoice (image or PDF) of a voucher,
-- kept in uploads/fin_attachments and served only through accounts/fin_attachment_file.php.
-- fin_settings names the accounts the supplier invoice posts to, per company.
-- Run after fin_vouchers.sql and fin_partners.sql. Safe to re-run.
ALTER TABLE `fin_vouchers`
  MODIFY `type` enum('jv','pc','pv','rv','si') NOT NULL;

CREATE TABLE IF NOT EXISTS `fin_supplier_invoices` (
  `voucher_id` int(11) NOT NULL,
  `company_id` int(11) NOT NULL,
  `vendor_id` int(11) NOT NULL,
  `invoice_no` varchar(100) NOT NULL,
  `vat_rate` decimal(5,2) NOT NULL,
  `net_amount` decimal(15,2) NOT NULL,
  `vat_amount` decimal(15,2) NOT NULL,
  `total_amount` decimal(15,2) NOT NULL,
  PRIMARY KEY (`voucher_id`),
  KEY `idx_fin_si_vendor_no` (`company_id`,`vendor_id`,`invoice_no`),
  CONSTRAINT `fk_fin_si_voucher` FOREIGN KEY (`voucher_id`) REFERENCES `fin_vouchers` (`id`),
  CONSTRAINT `fk_fin_si_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- A removed file keeps its row (removed_at) and its file on disk, for the record.
CREATE TABLE IF NOT EXISTS `fin_voucher_attachments` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `company_id` int(11) NOT NULL,
  `voucher_id` int(11) NOT NULL,
  `file_name` varchar(255) NOT NULL,
  `stored_name` varchar(120) NOT NULL,
  `file_size` int(11) NOT NULL,
  `uploaded_by` int(11) DEFAULT NULL,
  `uploaded_at` datetime NOT NULL DEFAULT current_timestamp(),
  `removed_by` int(11) DEFAULT NULL,
  `removed_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_fin_va_voucher` (`voucher_id`),
  CONSTRAINT `fk_fin_va_voucher` FOREIGN KEY (`voucher_id`) REFERENCES `fin_vouchers` (`id`),
  CONSTRAINT `fk_fin_va_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `fin_settings` (
  `company_id` int(11) NOT NULL,
  `setting_key` varchar(50) NOT NULL,
  `account_id` int(11) DEFAULT NULL,
  `updated_by` int(11) DEFAULT NULL,
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`company_id`,`setting_key`),
  CONSTRAINT `fk_fin_set_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`),
  CONSTRAINT `fk_fin_set_account` FOREIGN KEY (`account_id`) REFERENCES `fin_coa_accounts` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
