-- Phase 3: Supplier payments (GL: Debit Supplier Payable, Credit Bank)
-- Run after construction_supplier_invoices_phase2.sql.

CREATE TABLE IF NOT EXISTS `co_supplier_payments` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `company_id` INT(11) NOT NULL,
  `supplier_id` INT(11) NOT NULL,
  `payment_date` DATE NOT NULL,
  `amount` DECIMAL(15,2) NOT NULL,
  `reference` VARCHAR(100) DEFAULT NULL,
  `journal_id` INT(11) DEFAULT NULL COMMENT 're_journal_headers.id after GL post',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `created_by` INT(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_company` (`company_id`),
  KEY `idx_supplier` (`supplier_id`),
  KEY `idx_payment_date` (`payment_date`),
  KEY `idx_journal` (`journal_id`),
  FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE RESTRICT,
  FOREIGN KEY (`supplier_id`) REFERENCES `co_suppliers` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
