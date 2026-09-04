-- ============================================================================
-- Construction Client Invoice Detailed Lines + Documents
-- ============================================================================

CREATE TABLE IF NOT EXISTS `co_client_invoice_lines` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `company_id` INT(11) NOT NULL,
  `invoice_id` INT(11) NOT NULL,
  `line_number` INT(11) NOT NULL DEFAULT 1,
  `description` VARCHAR(500) DEFAULT NULL,
  `quantity` DECIMAL(12,2) NOT NULL DEFAULT 1.00,
  `unit_price` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  `income_account_id` INT(11) DEFAULT NULL,
  `amount` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  `vat_pct` DECIMAL(5,2) NOT NULL DEFAULT 0.00,
  `vat_amount` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  `line_total` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_co_client_lines_invoice` (`company_id`, `invoice_id`),
  KEY `idx_co_client_lines_account` (`income_account_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

ALTER TABLE `co_client_invoice_lines`
  ADD COLUMN IF NOT EXISTS `quantity` DECIMAL(12,2) NOT NULL DEFAULT 1.00 AFTER `description`,
  ADD COLUMN IF NOT EXISTS `unit_price` DECIMAL(15,2) NOT NULL DEFAULT 0.00 AFTER `quantity`,
  ADD COLUMN IF NOT EXISTS `income_account_id` INT(11) DEFAULT NULL AFTER `unit_price`,
  ADD COLUMN IF NOT EXISTS `vat_pct` DECIMAL(5,2) NOT NULL DEFAULT 0.00 AFTER `amount`,
  ADD COLUMN IF NOT EXISTS `vat_amount` DECIMAL(15,2) NOT NULL DEFAULT 0.00 AFTER `vat_pct`,
  ADD COLUMN IF NOT EXISTS `line_total` DECIMAL(15,2) NOT NULL DEFAULT 0.00 AFTER `vat_amount`;

CREATE TABLE IF NOT EXISTS `co_client_invoice_documents` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `company_id` INT(11) NOT NULL,
  `client_invoice_id` INT(11) NOT NULL,
  `title` VARCHAR(255) NOT NULL DEFAULT 'Invoice Attachment',
  `file_path` VARCHAR(500) NOT NULL,
  `uploaded_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `uploaded_by` INT(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_co_client_inv_doc_company` (`company_id`),
  KEY `idx_co_client_inv_doc_invoice` (`client_invoice_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
