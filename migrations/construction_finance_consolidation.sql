-- Construction finance consolidation
-- Adds supplier payment account selection and payment-to-invoice allocations.

ALTER TABLE `co_supplier_payments`
  ADD COLUMN IF NOT EXISTS `pay_account_id` INT(11) DEFAULT NULL AFTER `amount`;

CREATE INDEX IF NOT EXISTS `idx_co_supplier_payments_pay_account`
  ON `co_supplier_payments` (`pay_account_id`);

CREATE TABLE IF NOT EXISTS `co_supplier_payment_allocations` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `company_id` INT(11) NOT NULL,
  `payment_id` INT(11) NOT NULL,
  `invoice_id` INT(11) NOT NULL,
  `allocated_amount` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_payment_invoice` (`payment_id`, `invoice_id`),
  KEY `idx_company` (`company_id`),
  KEY `idx_payment` (`payment_id`),
  KEY `idx_invoice` (`invoice_id`),
  CONSTRAINT `fk_co_sp_alloc_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_co_sp_alloc_payment` FOREIGN KEY (`payment_id`) REFERENCES `co_supplier_payments` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_co_sp_alloc_invoice` FOREIGN KEY (`invoice_id`) REFERENCES `co_supplier_invoices` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
