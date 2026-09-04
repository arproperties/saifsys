-- Supplier documents & invoice attachments (PDFs)
-- Run after construction_suppliers_phase1.sql and construction_supplier_invoices_phase2.sql

CREATE TABLE IF NOT EXISTS `co_supplier_documents` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `company_id` INT(11) NOT NULL,
  `supplier_id` INT(11) NOT NULL,
  `doc_type` VARCHAR(50) NOT NULL DEFAULT 'other',
  `title` VARCHAR(255) NOT NULL,
  `file_path` VARCHAR(500) NOT NULL,
  `notes` TEXT DEFAULT NULL,
  `uploaded_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `uploaded_by` INT(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_company` (`company_id`),
  KEY `idx_supplier` (`supplier_id`),
  FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE RESTRICT,
  FOREIGN KEY (`supplier_id`) REFERENCES `co_suppliers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `co_supplier_invoice_documents` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `company_id` INT(11) NOT NULL,
  `supplier_invoice_id` INT(11) NOT NULL,
  `title` VARCHAR(255) NOT NULL DEFAULT 'Invoice PDF',
  `file_path` VARCHAR(500) NOT NULL,
  `uploaded_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `uploaded_by` INT(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_company` (`company_id`),
  KEY `idx_invoice` (`supplier_invoice_id`),
  FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE RESTRICT,
  FOREIGN KEY (`supplier_invoice_id`) REFERENCES `co_supplier_invoices` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
