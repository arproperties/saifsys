-- ============================================================================
-- Construction Supplier Invoice Lines + Recurring Supplier Invoices
-- ============================================================================

CREATE TABLE IF NOT EXISTS `co_supplier_invoice_items` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `company_id` INT(11) NOT NULL,
  `invoice_id` INT(11) NOT NULL,
  `project_id` INT(11) DEFAULT NULL,
  `expense_account_id` INT(11) DEFAULT NULL,
  `description` VARCHAR(500) DEFAULT NULL,
  `quantity` DECIMAL(12,2) NOT NULL DEFAULT 1.00,
  `unit_price` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  `subtotal` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  `vat_pct` DECIMAL(5,2) NOT NULL DEFAULT 0.00,
  `vat_amount` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  `line_total` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_co_supplier_items_invoice` (`company_id`, `invoice_id`),
  KEY `idx_co_supplier_items_project` (`project_id`),
  KEY `idx_co_supplier_items_account` (`expense_account_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `co_supplier_recurring_invoices` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `company_id` INT(11) NOT NULL,
  `supplier_id` INT(11) NOT NULL,
  `source_invoice_id` INT(11) DEFAULT NULL,
  `template_name` VARCHAR(255) NOT NULL,
  `invoice_number_prefix` VARCHAR(100) DEFAULT NULL,
  `frequency` ENUM('weekly','monthly','quarterly','semi_annual','annual') NOT NULL DEFAULT 'monthly',
  `next_invoice_date` DATE DEFAULT NULL,
  `end_date` DATE DEFAULT NULL,
  `due_days` INT(11) NOT NULL DEFAULT 0,
  `description` VARCHAR(500) DEFAULT NULL,
  `reference` VARCHAR(100) DEFAULT NULL,
  `subtotal` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  `vat_amount` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  `total` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  `lines_json` LONGTEXT DEFAULT NULL,
  `status` ENUM('active','paused','cancelled') NOT NULL DEFAULT 'active',
  `last_generated_invoice_date` DATE DEFAULT NULL,
  `last_generated_invoice_id` INT(11) DEFAULT NULL,
  `created_by` INT(11) DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_co_rec_supplier_company` (`company_id`, `status`, `next_invoice_date`),
  KEY `idx_co_rec_supplier_supplier` (`company_id`, `supplier_id`),
  KEY `idx_co_rec_supplier_source` (`source_invoice_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
