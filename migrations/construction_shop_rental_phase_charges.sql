-- Construction Shop Rental Phase 1 (Payment Maturity) — Contract Charges
-- Company-scoped charge catalogue + per-contract charge lines.
-- Legacy rent/deposit/commission columns remain; charges sync from them (compat layer).
-- Key Money is seeded but invoicing/posting is blocked until Confirmed BR.
-- Rollback intent: DROP contract_charge_id column; DROP charge tables (after clearing FKs).
-- Does NOT modify Real Estate tables or accounting_engine.

CREATE TABLE IF NOT EXISTS `co_shop_charge_types` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `company_id` INT(11) NOT NULL,
  `code` VARCHAR(50) NOT NULL,
  `name` VARCHAR(150) NOT NULL,
  `nature` ENUM('income','liability','passthrough') NOT NULL DEFAULT 'income',
  `default_coa_code` VARCHAR(20) DEFAULT NULL,
  `vat_eligible` TINYINT(1) NOT NULL DEFAULT 1,
  `is_system` TINYINT(1) NOT NULL DEFAULT 0,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `invoicing_enabled` TINYINT(1) NOT NULL DEFAULT 1 COMMENT '0 = reserved / blocked (e.g. key_money until BR)',
  `allocation_priority` INT(11) NOT NULL DEFAULT 100,
  `sort_order` INT(11) NOT NULL DEFAULT 100,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_co_shop_charge_type_code` (`company_id`, `code`),
  KEY `idx_co_shop_charge_types_company` (`company_id`),
  CONSTRAINT `fk_co_shop_charge_types_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `co_shop_contract_charges` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `company_id` INT(11) NOT NULL,
  `contract_id` INT(11) NOT NULL,
  `charge_type_id` INT(11) NOT NULL,
  `amount` DECIMAL(15,2) NOT NULL DEFAULT 0.00 COMMENT 'Net amount (ex-VAT) for income; face for liability',
  `vat_mode` ENUM('exclusive','inclusive','none') NOT NULL DEFAULT 'exclusive',
  `vat_rate` DECIMAL(5,2) NOT NULL DEFAULT 0.00,
  `vat_amount` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  `gross_amount` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  `gl_account_override` VARCHAR(20) DEFAULT NULL,
  `status` ENUM('active','disabled','invoiced','cancelled') NOT NULL DEFAULT 'active',
  `notes` VARCHAR(500) DEFAULT NULL,
  `sort_order` INT(11) NOT NULL DEFAULT 100,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `created_by` INT(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_co_shop_contract_charge_type` (`contract_id`, `charge_type_id`),
  KEY `idx_co_shop_contract_charges_company` (`company_id`),
  KEY `idx_co_shop_contract_charges_contract` (`contract_id`),
  KEY `idx_co_shop_contract_charges_type` (`charge_type_id`),
  CONSTRAINT `fk_co_shop_cc_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_co_shop_cc_contract` FOREIGN KEY (`contract_id`) REFERENCES `co_shop_rental_contracts` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_co_shop_cc_type` FOREIGN KEY (`charge_type_id`) REFERENCES `co_shop_charge_types` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Link invoices to contract charge lines (nullable; Construction only)
ALTER TABLE `co_client_invoices`
  ADD COLUMN IF NOT EXISTS `contract_charge_id` INT(11) DEFAULT NULL AFTER `source_id`;

CREATE INDEX IF NOT EXISTS `idx_co_client_invoices_contract_charge`
  ON `co_client_invoices` (`company_id`, `contract_charge_id`);

-- Allow custom charge invoices without inventing RE types
ALTER TABLE `co_client_invoices`
  MODIFY COLUMN `source_type` ENUM(
    'construction_project',
    'shop_rental',
    'shop_commission',
    'shop_termination_penalty',
    'shop_charge',
    'camp_management',
    'maintenance_service',
    'manual'
  ) NOT NULL DEFAULT 'construction_project';
