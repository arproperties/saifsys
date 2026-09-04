-- ERP shared operating expenses (Real Estate / Construction / ARS — re_* GL only; legacy cleaning expenses unchanged)
-- Safe re-run: CREATE TABLE IF NOT EXISTS
-- Upgrades from older installs: run migrations/erp_expenses_phase2_enhancements.sql after this base file if tables already existed without phase-2 columns.

CREATE TABLE IF NOT EXISTS `erp_expense_seq` (
  `company_id` int(11) NOT NULL,
  `last_num` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`company_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `erp_expense_headers` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `company_id` int(11) NOT NULL,
  `source_module` varchar(32) NOT NULL COMMENT 'realestate|construction|ars',
  `expense_number` varchar(32) DEFAULT NULL COMMENT 'Display id e.g. EXP-0001',
  `expense_date` date NOT NULL,
  `vendor_id` int(11) DEFAULT NULL COMMENT 're_vendors.id',
  `co_supplier_id` int(11) DEFAULT NULL COMMENT 'co_suppliers.id when source_module=construction',
  `reference_no` varchar(128) DEFAULT NULL,
  `paid_via` enum('cash','bank','credit','accounts_payable') NOT NULL DEFAULT 'bank',
  `pay_account_id` int(11) DEFAULT NULL COMMENT 're_chart_of_accounts.id (bank/cash/credit settlement)',
  `currency` varchar(3) NOT NULL DEFAULT 'AED',
  `subtotal` decimal(15,2) NOT NULL DEFAULT 0.00,
  `vat_amount` decimal(15,2) NOT NULL DEFAULT 0.00,
  `total` decimal(15,2) NOT NULL DEFAULT 0.00,
  `vat_mode` enum('inclusive','exclusive') NOT NULL DEFAULT 'exclusive',
  `notes` text DEFAULT NULL,
  `status` enum('draft','posted','cancelled') NOT NULL DEFAULT 'draft',
  `journal_id` int(11) DEFAULT NULL COMMENT 're_journal_headers.id when posted',
  `created_by` int(11) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_erp_expense_company_number` (`company_id`,`expense_number`),
  KEY `idx_company` (`company_id`),
  KEY `idx_source` (`source_module`),
  KEY `idx_expense_date` (`expense_date`),
  KEY `idx_journal` (`journal_id`),
  KEY `idx_vendor` (`vendor_id`),
  KEY `idx_erp_exp_co_supplier` (`co_supplier_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `erp_expense_lines` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `expense_id` int(11) NOT NULL,
  `line_no` int(11) NOT NULL,
  `account_id` int(11) NOT NULL COMMENT 're_chart_of_accounts.id',
  `description` varchar(512) DEFAULT NULL,
  `qty` decimal(12,3) NOT NULL DEFAULT 1.000,
  `unit_cost` decimal(15,4) NOT NULL DEFAULT 0.0000,
  `vat_rate` decimal(8,4) NOT NULL DEFAULT 0.0000,
  `line_subtotal` decimal(15,2) NOT NULL DEFAULT 0.00,
  `line_vat` decimal(15,2) NOT NULL DEFAULT 0.00,
  `line_total` decimal(15,2) NOT NULL DEFAULT 0.00,
  PRIMARY KEY (`id`),
  KEY `idx_expense` (`expense_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `erp_expense_attachments` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `expense_id` int(11) NOT NULL,
  `file_name` varchar(255) NOT NULL,
  `file_path` varchar(500) NOT NULL,
  `mime_type` varchar(100) DEFAULT NULL,
  `file_size` int(11) DEFAULT NULL,
  `uploaded_by` int(11) DEFAULT NULL,
  `uploaded_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_expense` (`expense_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
