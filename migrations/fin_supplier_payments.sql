-- Finance > Supplier payments: a payment voucher (pv) made to a supplier, in one
-- of three ways (mode):
--   bills       Against Bills - settles chosen supplier invoices at once
--   on_account  On Account    - to the supplier, no invoice yet
--   advance     Advance       - to the supplier advance account, before the invoice
-- fin_payment_allocations says which payment settled which invoice and by how
-- much. An advance linked to an invoice later also gets a transfer voucher (a
-- journal: supplier Dr, advance Cr) dated on the later of the two dates; the
-- payment and the invoice keep their own dates.
-- A removed allocation keeps its row (removed_at), and its transfer is deleted.
-- Run after fin_supplier_invoices.sql. Safe to re-run.
CREATE TABLE IF NOT EXISTS `fin_supplier_payments` (
  `voucher_id` int(11) NOT NULL,
  `company_id` int(11) NOT NULL,
  `vendor_id` int(11) NOT NULL,
  `mode` enum('bills','on_account','advance') NOT NULL,
  `amount` decimal(15,2) NOT NULL,
  PRIMARY KEY (`voucher_id`),
  KEY `idx_fin_sp_vendor` (`company_id`,`vendor_id`),
  CONSTRAINT `fk_fin_sp_voucher` FOREIGN KEY (`voucher_id`) REFERENCES `fin_vouchers` (`id`),
  CONSTRAINT `fk_fin_sp_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `fin_payment_allocations` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `company_id` int(11) NOT NULL,
  `payment_voucher_id` int(11) NOT NULL,
  `invoice_voucher_id` int(11) NOT NULL,
  `amount` decimal(15,2) NOT NULL,
  `transfer_voucher_id` int(11) DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `removed_by` int(11) DEFAULT NULL,
  `removed_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_fin_pa_payment` (`payment_voucher_id`),
  KEY `idx_fin_pa_invoice` (`invoice_voucher_id`),
  KEY `idx_fin_pa_transfer` (`transfer_voucher_id`),
  CONSTRAINT `fk_fin_pa_payment` FOREIGN KEY (`payment_voucher_id`) REFERENCES `fin_vouchers` (`id`),
  CONSTRAINT `fk_fin_pa_invoice` FOREIGN KEY (`invoice_voucher_id`) REFERENCES `fin_vouchers` (`id`),
  CONSTRAINT `fk_fin_pa_transfer` FOREIGN KEY (`transfer_voucher_id`) REFERENCES `fin_vouchers` (`id`),
  CONSTRAINT `fk_fin_pa_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
