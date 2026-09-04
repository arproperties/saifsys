-- Construction Shop Rental Phase 2A — Commercial leasing lifecycle (Madar Al Wadi).
-- Additive only. Company-isolated. Does not touch Real Estate lease tables.
-- Approved GL: 4150 Early Termination Penalty Income, 4160 Deposit Recovery Income.
-- Rollback intent: DROP new tables/columns after backup; restore status ENUM carefully.

-- Status lifecycle
ALTER TABLE `co_shop_rental_contracts`
  MODIFY COLUMN `status` ENUM('draft','active','renewed','expired','terminated','archived') NOT NULL DEFAULT 'draft';

ALTER TABLE `co_shop_rental_contracts`
  ADD COLUMN IF NOT EXISTS `parent_contract_id` INT(11) DEFAULT NULL AFTER `client_id`,
  ADD COLUMN IF NOT EXISTS `renewed_to_contract_id` INT(11) DEFAULT NULL AFTER `parent_contract_id`,
  ADD COLUMN IF NOT EXISTS `terminated_at` DATETIME DEFAULT NULL AFTER `status`,
  ADD COLUMN IF NOT EXISTS `termination_reason` VARCHAR(500) DEFAULT NULL AFTER `terminated_at`,
  ADD COLUMN IF NOT EXISTS `archived_at` DATETIME DEFAULT NULL AFTER `termination_reason`;

ALTER TABLE `co_shop_rental_contracts`
  ADD KEY `idx_co_shop_parent_contract` (`company_id`, `parent_contract_id`);

-- Cheque lifecycle links
ALTER TABLE `co_shop_rent_cheques`
  ADD COLUMN IF NOT EXISTS `replaces_cheque_id` INT(11) DEFAULT NULL AFTER `deposit_receipt_id`,
  ADD COLUMN IF NOT EXISTS `replaced_by_cheque_id` INT(11) DEFAULT NULL AFTER `replaces_cheque_id`,
  ADD COLUMN IF NOT EXISTS `lifecycle_note` VARCHAR(500) DEFAULT NULL AFTER `notes`,
  ADD COLUMN IF NOT EXISTS `bounced_at` DATETIME DEFAULT NULL AFTER `lifecycle_note`,
  ADD COLUMN IF NOT EXISTS `lost_at` DATETIME DEFAULT NULL AFTER `bounced_at`;

-- Penalty invoice source
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

-- Application enforces at most one finalized settlement per contract (no partial unique index).

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

-- Seed default settings for companies that already have shop rental contracts
INSERT IGNORE INTO `co_shop_rental_settings` (`company_id`)
SELECT DISTINCT `company_id` FROM `co_shop_rental_contracts`;
