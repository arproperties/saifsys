-- Phase 1: Suppliers master for Construction Expenses
-- Run once. For Phase 2 we will add co_supplier_invoices; Phase 3 co_supplier_payments.

CREATE TABLE IF NOT EXISTS `co_suppliers` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `company_id` INT(11) NOT NULL,
  `supplier_name` VARCHAR(255) NOT NULL,
  `contact_person` VARCHAR(255) DEFAULT NULL,
  `email` VARCHAR(255) DEFAULT NULL,
  `phone` VARCHAR(100) DEFAULT NULL,
  `address` TEXT DEFAULT NULL,
  `tax_number` VARCHAR(100) DEFAULT NULL,
  `vat_number` VARCHAR(100) DEFAULT NULL COMMENT 'VAT registration number',
  `bank_name` VARCHAR(255) DEFAULT NULL,
  `account_number` VARCHAR(100) DEFAULT NULL,
  `iban` VARCHAR(100) DEFAULT NULL,
  `swift_code` VARCHAR(50) DEFAULT NULL,
  `bank_details` TEXT DEFAULT NULL,
  `notes` TEXT DEFAULT NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_company` (`company_id`),
  KEY `idx_active` (`is_active`),
  FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
