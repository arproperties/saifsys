-- Phase 2: Supplier invoices for Construction Expenses (VAT + GL posting)
-- Run after construction_suppliers_phase1.sql. Requires co_suppliers and re_chart_of_accounts.

CREATE TABLE IF NOT EXISTS `co_supplier_invoices` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `company_id` INT(11) NOT NULL,
  `supplier_id` INT(11) NOT NULL,
  `project_id` INT(11) DEFAULT NULL COMMENT 'Optional link to project for CIP/COGS',
  `invoice_number` VARCHAR(100) NOT NULL,
  `invoice_date` DATE NOT NULL,
  `due_date` DATE DEFAULT NULL,
  `subtotal` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  `vat_pct` DECIMAL(5,2) NOT NULL DEFAULT 0.00,
  `vat_amount` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  `total` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  `description` VARCHAR(500) DEFAULT NULL,
  `reference` VARCHAR(100) DEFAULT NULL,
  `journal_id` INT(11) DEFAULT NULL COMMENT 're_journal_headers.id after GL post',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `created_by` INT(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_company` (`company_id`),
  KEY `idx_supplier` (`supplier_id`),
  KEY `idx_project` (`project_id`),
  KEY `idx_invoice_date` (`invoice_date`),
  KEY `idx_journal` (`journal_id`),
  UNIQUE KEY `uq_company_supplier_inv` (`company_id`, `supplier_id`, `invoice_number`),
  FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE RESTRICT,
  FOREIGN KEY (`supplier_id`) REFERENCES `co_suppliers` (`id`) ON DELETE RESTRICT,
  FOREIGN KEY (`project_id`) REFERENCES `co_projects` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
