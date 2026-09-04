-- =============================================================================
-- Construction Shop Rental — ALL migrations (combined)
-- =============================================================================
-- Scope: Commercial leasing (Madar Al Wadi / Construction module) workstream
--        from Phase 1 through Combined First Cheque, plus Design System themes.
--
-- Prerequisites (must already exist before this file):
--   - companies, co_clients, co_client_invoices, co_client_payments,
--     co_client_payment_allocations, re_journal_headers
--   - Base shop rental tables from construction_income_workflow.sql
--       (co_shop_units, co_shop_rental_contracts, co_shop_rent_cheques,
--        co_shop_rent_schedules, …)
--
-- Run order (sections below, in this file):
--   1) Phase 1          — VAT modes, deposits, client credit
--   2) Phase 1.6        — Tenant commission
--   3) Phase 1.75       — Multi-shop contracts
--   4) Phase 2A         — Lifecycle (renew / terminate / deposit settle)
--   5) Phase 2B         — Analytics indexes
--   6) Combined cheque  — Opt-in first-cheque face (rent+VAT+commission)
--   7) Theme settings   — erp_module_themes (Construction Design System)
--
-- Source files (kept for reference; this combined file is authoritative for deploy):
--   migrations/construction_shop_rental_phase1.sql
--   migrations/construction_shop_rental_phase16_commission.sql
--   migrations/construction_shop_rental_phase175.sql
--   migrations/construction_shop_rental_phase2a.sql
--   migrations/construction_shop_rental_phase2b_indexes.sql
--   migrations/construction_shop_rental_combined_first_cheque.sql
--   migrations/erp_module_themes.sql
--
-- Notes:
--   - Additive / mostly idempotent (IF NOT EXISTS where supported).
--   - Does NOT touch Real Estate lease tables or Cleaning GL.
--   - After migrate: Construction → Setup Accounts so COA seeds (e.g. 2410,
--     4150, 4160) exist for the company.
--   - Human approval still required before running on production.
--   - Safe to skip sections already applied; re-running some ALTER/INDEX
--     statements may error if objects already exist — that is expected.
-- =============================================================================

-- -----------------------------------------------------------------------------
-- 1) Phase 1 — VAT, deposit receipts, client credit
-- -----------------------------------------------------------------------------

ALTER TABLE `co_shop_rental_contracts`
  ADD COLUMN IF NOT EXISTS `vat_mode` ENUM('exclusive','inclusive') NOT NULL DEFAULT 'exclusive'
    AFTER `vat_rate`,
  ADD COLUMN IF NOT EXISTS `vat_collection_method` ENUM('included_in_installment','proportional','separate') NOT NULL DEFAULT 'included_in_installment'
    AFTER `vat_mode`;

ALTER TABLE `co_shop_rental_contracts`
  MODIFY COLUMN `accrual_deferred_rent` TINYINT(1) NOT NULL DEFAULT 1;

ALTER TABLE `co_shop_rent_cheques`
  ADD COLUMN IF NOT EXISTS `vat_amount` DECIMAL(15,2) NOT NULL DEFAULT 0.00 AFTER `amount`,
  ADD COLUMN IF NOT EXISTS `net_amount` DECIMAL(15,2) NOT NULL DEFAULT 0.00 AFTER `vat_amount`,
  ADD COLUMN IF NOT EXISTS `deposit_receipt_id` INT(11) DEFAULT NULL AFTER `journal_id`;

ALTER TABLE `co_shop_rent_schedules`
  ADD COLUMN IF NOT EXISTS `schedule_type` ENUM('rent','vat') NOT NULL DEFAULT 'rent' AFTER `contract_id`,
  ADD COLUMN IF NOT EXISTS `net_amount` DECIMAL(15,2) NOT NULL DEFAULT 0.00 AFTER `amount`;

UPDATE `co_shop_rent_schedules`
SET `net_amount` = CASE
  WHEN COALESCE(`net_amount`, 0) = 0 THEN GREATEST(COALESCE(`amount`, 0), 0)
  ELSE `net_amount`
END
WHERE `company_id` IS NOT NULL;

CREATE TABLE IF NOT EXISTS `co_shop_deposit_receipts` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `company_id` INT(11) NOT NULL,
  `contract_id` INT(11) NOT NULL,
  `cheque_id` INT(11) DEFAULT NULL,
  `amount` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  `receipt_date` DATE NOT NULL,
  `pay_account_id` INT(11) NOT NULL,
  `reference` VARCHAR(255) DEFAULT NULL,
  `journal_id` INT(11) DEFAULT NULL,
  `notes` TEXT DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `created_by` INT(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_co_shop_deposit_cheque` (`cheque_id`),
  KEY `idx_company_contract` (`company_id`, `contract_id`),
  KEY `idx_journal` (`journal_id`),
  CONSTRAINT `fk_co_shop_dep_rcp_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_co_shop_dep_rcp_contract` FOREIGN KEY (`contract_id`) REFERENCES `co_shop_rental_contracts` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_co_shop_dep_rcp_cheque` FOREIGN KEY (`cheque_id`) REFERENCES `co_shop_rent_cheques` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_co_shop_dep_rcp_journal` FOREIGN KEY (`journal_id`) REFERENCES `re_journal_headers` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `co_client_credit_balances` (
  `client_id` INT(11) NOT NULL,
  `company_id` INT(11) NOT NULL,
  `balance_aed` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`client_id`, `company_id`),
  KEY `idx_company` (`company_id`),
  CONSTRAINT `fk_co_client_credit_bal_client` FOREIGN KEY (`client_id`) REFERENCES `co_clients` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_co_client_credit_bal_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `co_client_credit_transactions` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `company_id` INT(11) NOT NULL,
  `client_id` INT(11) NOT NULL,
  `payment_id` INT(11) DEFAULT NULL,
  `invoice_id` INT(11) DEFAULT NULL,
  `txn_type` ENUM('overpayment','apply','adjustment') NOT NULL,
  `amount` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  `notes` VARCHAR(500) DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `created_by` INT(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_company_client` (`company_id`, `client_id`),
  KEY `idx_payment` (`payment_id`),
  KEY `idx_invoice` (`invoice_id`),
  CONSTRAINT `fk_co_client_credit_txn_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_co_client_credit_txn_client` FOREIGN KEY (`client_id`) REFERENCES `co_clients` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_co_client_credit_txn_payment` FOREIGN KEY (`payment_id`) REFERENCES `co_client_payments` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_co_client_credit_txn_invoice` FOREIGN KEY (`invoice_id`) REFERENCES `co_client_invoices` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

ALTER TABLE `co_client_payments`
  ADD COLUMN IF NOT EXISTS `unallocated_amount` DECIMAL(15,2) NOT NULL DEFAULT 0.00 AFTER `amount`,
  ADD COLUMN IF NOT EXISTS `credit_amount` DECIMAL(15,2) NOT NULL DEFAULT 0.00 AFTER `unallocated_amount`;

-- -----------------------------------------------------------------------------
-- 2) Phase 1.6 — Tenant commission
-- -----------------------------------------------------------------------------

ALTER TABLE `co_shop_rental_contracts`
  ADD COLUMN IF NOT EXISTS `commission_enabled` TINYINT(1) NOT NULL DEFAULT 1 AFTER `notes`,
  ADD COLUMN IF NOT EXISTS `commission_basis` ENUM('percent','fixed') NOT NULL DEFAULT 'percent' AFTER `commission_enabled`,
  ADD COLUMN IF NOT EXISTS `commission_percent` DECIMAL(8,4) NOT NULL DEFAULT 5.0000 AFTER `commission_basis`,
  ADD COLUMN IF NOT EXISTS `commission_fixed_amount` DECIMAL(15,2) NOT NULL DEFAULT 0.00 AFTER `commission_percent`,
  ADD COLUMN IF NOT EXISTS `commission_net_amount` DECIMAL(15,2) NOT NULL DEFAULT 0.00 AFTER `commission_fixed_amount`,
  ADD COLUMN IF NOT EXISTS `commission_manual_override` TINYINT(1) NOT NULL DEFAULT 0 AFTER `commission_net_amount`,
  ADD COLUMN IF NOT EXISTS `commission_vat_enabled` TINYINT(1) NOT NULL DEFAULT 1 AFTER `commission_manual_override`,
  ADD COLUMN IF NOT EXISTS `commission_vat_rate` DECIMAL(5,2) NOT NULL DEFAULT 5.00 AFTER `commission_vat_enabled`,
  ADD COLUMN IF NOT EXISTS `commission_invoice_id` INT(11) DEFAULT NULL AFTER `commission_vat_rate`;

ALTER TABLE `co_client_invoices`
  MODIFY COLUMN `source_type` ENUM(
    'construction_project',
    'shop_rental',
    'shop_commission',
    'camp_management',
    'maintenance_service',
    'manual'
  ) NOT NULL DEFAULT 'construction_project';

UPDATE `co_shop_rental_contracts`
SET `commission_enabled` = 1,
    `commission_basis` = 'percent',
    `commission_percent` = 5.0000,
    `commission_net_amount` = ROUND(`rent_amount` * 5.0000 / 100, 2),
    `commission_vat_enabled` = 1,
    `commission_vat_rate` = 5.00
WHERE `commission_invoice_id` IS NULL
  AND COALESCE(`commission_net_amount`, 0) = 0
  AND `rent_amount` > 0;

-- -----------------------------------------------------------------------------
-- 3) Phase 1.75 — Multi-shop contracts
-- -----------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `co_shop_rental_contract_shops` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `company_id` INT(11) NOT NULL,
  `contract_id` INT(11) NOT NULL,
  `shop_unit_id` INT(11) NOT NULL,
  `is_primary` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_co_shop_contract_shop` (`contract_id`, `shop_unit_id`),
  KEY `idx_company_shop` (`company_id`, `shop_unit_id`),
  KEY `idx_contract_primary` (`contract_id`, `is_primary`),
  CONSTRAINT `fk_co_shop_cshop_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_co_shop_cshop_contract` FOREIGN KEY (`contract_id`) REFERENCES `co_shop_rental_contracts` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_co_shop_cshop_unit` FOREIGN KEY (`shop_unit_id`) REFERENCES `co_shop_units` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT IGNORE INTO `co_shop_rental_contract_shops` (`company_id`, `contract_id`, `shop_unit_id`, `is_primary`)
SELECT `company_id`, `id`, `shop_unit_id`, 1
FROM `co_shop_rental_contracts`
WHERE `shop_unit_id` IS NOT NULL AND `shop_unit_id` > 0;

-- -----------------------------------------------------------------------------
-- 4) Phase 2A — Lifecycle (renew / terminate / inspections / deposit settle)
-- -----------------------------------------------------------------------------

ALTER TABLE `co_shop_rental_contracts`
  MODIFY COLUMN `status` ENUM('draft','active','renewed','expired','terminated','archived') NOT NULL DEFAULT 'draft';

ALTER TABLE `co_shop_rental_contracts`
  ADD COLUMN IF NOT EXISTS `parent_contract_id` INT(11) DEFAULT NULL AFTER `client_id`,
  ADD COLUMN IF NOT EXISTS `renewed_to_contract_id` INT(11) DEFAULT NULL AFTER `parent_contract_id`,
  ADD COLUMN IF NOT EXISTS `terminated_at` DATETIME DEFAULT NULL AFTER `status`,
  ADD COLUMN IF NOT EXISTS `termination_reason` VARCHAR(500) DEFAULT NULL AFTER `terminated_at`,
  ADD COLUMN IF NOT EXISTS `archived_at` DATETIME DEFAULT NULL AFTER `termination_reason`;

-- May error if index already exists — safe to ignore on re-run
ALTER TABLE `co_shop_rental_contracts`
  ADD KEY `idx_co_shop_parent_contract` (`company_id`, `parent_contract_id`);

ALTER TABLE `co_shop_rent_cheques`
  ADD COLUMN IF NOT EXISTS `replaces_cheque_id` INT(11) DEFAULT NULL AFTER `deposit_receipt_id`,
  ADD COLUMN IF NOT EXISTS `replaced_by_cheque_id` INT(11) DEFAULT NULL AFTER `replaces_cheque_id`,
  ADD COLUMN IF NOT EXISTS `lifecycle_note` VARCHAR(500) DEFAULT NULL AFTER `notes`,
  ADD COLUMN IF NOT EXISTS `bounced_at` DATETIME DEFAULT NULL AFTER `lifecycle_note`,
  ADD COLUMN IF NOT EXISTS `lost_at` DATETIME DEFAULT NULL AFTER `bounced_at`;

ALTER TABLE `co_client_invoices`
  MODIFY COLUMN `source_type` ENUM(
    'construction_project',
    'shop_rental',
    'shop_commission',
    'shop_termination_penalty',
    'camp_management',
    'maintenance_service',
    'manual'
  ) NOT NULL DEFAULT 'construction_project';

CREATE TABLE IF NOT EXISTS `co_shop_rental_settings` (
  `company_id` INT(11) NOT NULL,
  `default_termination_penalty_months` DECIMAL(5,2) NOT NULL DEFAULT 2.00,
  `suggested_damage_amount` DECIMAL(15,2) NOT NULL DEFAULT 500.00,
  `suggested_cleaning_amount` DECIMAL(15,2) NOT NULL DEFAULT 300.00,
  `suggested_utility_amount` DECIMAL(15,2) NOT NULL DEFAULT 200.00,
  `suggested_missing_keys_amount` DECIMAL(15,2) NOT NULL DEFAULT 150.00,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `updated_by` INT(11) DEFAULT NULL,
  PRIMARY KEY (`company_id`),
  CONSTRAINT `fk_co_shop_settings_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `co_shop_contract_events` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `company_id` INT(11) NOT NULL,
  `contract_id` INT(11) NOT NULL,
  `event_type` VARCHAR(64) NOT NULL,
  `payload_json` LONGTEXT DEFAULT NULL,
  `created_by` INT(11) DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_co_shop_events_contract` (`company_id`, `contract_id`, `created_at`),
  CONSTRAINT `fk_co_shop_events_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_co_shop_events_contract` FOREIGN KEY (`contract_id`) REFERENCES `co_shop_rental_contracts` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `co_shop_contract_amendments` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `company_id` INT(11) NOT NULL,
  `contract_id` INT(11) NOT NULL,
  `reason` VARCHAR(500) NOT NULL,
  `before_json` LONGTEXT NOT NULL,
  `after_json` LONGTEXT NOT NULL,
  `created_by` INT(11) DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_co_shop_amend_contract` (`company_id`, `contract_id`, `created_at`),
  CONSTRAINT `fk_co_shop_amend_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_co_shop_amend_contract` FOREIGN KEY (`contract_id`) REFERENCES `co_shop_rental_contracts` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `co_shop_move_out_inspections` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `company_id` INT(11) NOT NULL,
  `contract_id` INT(11) NOT NULL,
  `inspection_date` DATE NOT NULL,
  `inspector_name` VARCHAR(255) NOT NULL,
  `inspector_user_id` INT(11) DEFAULT NULL,
  `checklist_json` LONGTEXT NOT NULL,
  `suggested_deductions_json` LONGTEXT DEFAULT NULL,
  `remarks` TEXT DEFAULT NULL,
  `status` ENUM('draft','completed') NOT NULL DEFAULT 'draft',
  `completed_at` DATETIME DEFAULT NULL,
  `created_by` INT(11) DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_co_shop_insp_contract` (`company_id`, `contract_id`, `status`),
  CONSTRAINT `fk_co_shop_insp_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_co_shop_insp_contract` FOREIGN KEY (`contract_id`) REFERENCES `co_shop_rental_contracts` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `co_shop_move_out_inspection_files` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `company_id` INT(11) NOT NULL,
  `inspection_id` INT(11) NOT NULL,
  `file_path` VARCHAR(500) NOT NULL,
  `original_name` VARCHAR(255) DEFAULT NULL,
  `mime_type` VARCHAR(120) DEFAULT NULL,
  `uploaded_by` INT(11) DEFAULT NULL,
  `uploaded_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_co_shop_insp_files` (`company_id`, `inspection_id`),
  CONSTRAINT `fk_co_shop_insp_files_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_co_shop_insp_files_insp` FOREIGN KEY (`inspection_id`) REFERENCES `co_shop_move_out_inspections` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `co_shop_deposit_settlements` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `company_id` INT(11) NOT NULL,
  `contract_id` INT(11) NOT NULL,
  `inspection_id` INT(11) DEFAULT NULL,
  `settlement_date` DATE NOT NULL,
  `deposit_held` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  `refund_amount` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  `damage_amount` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  `utility_amount` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  `cleaning_amount` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  `forfeit_amount` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  `apply_to_invoices_amount` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  `pay_account_id` INT(11) DEFAULT NULL,
  `notes` TEXT DEFAULT NULL,
  `status` ENUM('draft','finalized') NOT NULL DEFAULT 'draft',
  `journal_id` INT(11) DEFAULT NULL,
  `finalized_at` DATETIME DEFAULT NULL,
  `finalized_by` INT(11) DEFAULT NULL,
  `created_by` INT(11) DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_co_shop_dep_settle_contract` (`company_id`, `contract_id`),
  KEY `idx_co_shop_dep_settle_status` (`company_id`, `contract_id`, `status`),
  CONSTRAINT `fk_co_shop_dep_settle_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_co_shop_dep_settle_contract` FOREIGN KEY (`contract_id`) REFERENCES `co_shop_rental_contracts` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_co_shop_dep_settle_insp` FOREIGN KEY (`inspection_id`) REFERENCES `co_shop_move_out_inspections` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `co_shop_contract_terminations` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `company_id` INT(11) NOT NULL,
  `contract_id` INT(11) NOT NULL,
  `termination_date` DATE NOT NULL,
  `reason` VARCHAR(500) NOT NULL,
  `standard_penalty_months` DECIMAL(5,2) NOT NULL DEFAULT 2.00,
  `standard_penalty_amount` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  `approved_penalty_amount` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  `discount_amount` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  `override_reason` VARCHAR(500) DEFAULT NULL,
  `approved_by` INT(11) DEFAULT NULL,
  `settlement_method` VARCHAR(64) DEFAULT NULL,
  `penalty_invoice_id` INT(11) DEFAULT NULL,
  `deposit_settlement_id` INT(11) DEFAULT NULL,
  `inspection_id` INT(11) DEFAULT NULL,
  `status` ENUM('draft','finalized') NOT NULL DEFAULT 'draft',
  `finalized_at` DATETIME DEFAULT NULL,
  `finalized_by` INT(11) DEFAULT NULL,
  `created_by` INT(11) DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_co_shop_term_contract` (`company_id`, `contract_id`, `status`),
  CONSTRAINT `fk_co_shop_term_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_co_shop_term_contract` FOREIGN KEY (`contract_id`) REFERENCES `co_shop_rental_contracts` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO `co_shop_rental_settings` (`company_id`)
SELECT DISTINCT `company_id` FROM `co_shop_rental_contracts`;

-- -----------------------------------------------------------------------------
-- 5) Phase 2B — Analytics / list indexes
-- -----------------------------------------------------------------------------

CREATE INDEX IF NOT EXISTS `idx_co_shop_contracts_status_end`
  ON `co_shop_rental_contracts` (`company_id`, `status`, `end_date`);

CREATE INDEX IF NOT EXISTS `idx_co_shop_contracts_client_status`
  ON `co_shop_rental_contracts` (`company_id`, `client_id`, `status`);

CREATE INDEX IF NOT EXISTS `idx_co_shop_schedules_status_due`
  ON `co_shop_rent_schedules` (`company_id`, `status`, `due_date`);

CREATE INDEX IF NOT EXISTS `idx_co_shop_schedules_type_status`
  ON `co_shop_rent_schedules` (`company_id`, `schedule_type`, `status`);

CREATE INDEX IF NOT EXISTS `idx_co_client_invoices_source_date`
  ON `co_client_invoices` (`company_id`, `source_type`, `invoice_date`);

CREATE INDEX IF NOT EXISTS `idx_co_cp_alloc_company_invoice`
  ON `co_client_payment_allocations` (`company_id`, `invoice_id`);

CREATE INDEX IF NOT EXISTS `idx_co_shop_units_status`
  ON `co_shop_units` (`company_id`, `status`);

-- -----------------------------------------------------------------------------
-- 6) Combined first collection cheque (opt-in)
-- -----------------------------------------------------------------------------

ALTER TABLE `co_shop_rental_contracts`
  ADD COLUMN IF NOT EXISTS `combined_first_cheque` TINYINT(1) NOT NULL DEFAULT 0
  COMMENT '1 = first rent cheque is combined (1st rent + separate VAT + commission)'
  AFTER `deposit_cheque_count`;

-- -----------------------------------------------------------------------------
-- 7) ERP module themes (Construction Design System / Theme Settings)
-- -----------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `erp_module_themes` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `company_id` INT NOT NULL,
  `module_key` VARCHAR(32) NOT NULL COMMENT 'e.g. construction, realestate, cleaning',
  `theme_json` JSON NOT NULL,
  `updated_by` INT NULL,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_company_module` (`company_id`, `module_key`),
  KEY `idx_module` (`module_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
-- End of combined Construction Shop Rental migrations
-- =============================================================================
