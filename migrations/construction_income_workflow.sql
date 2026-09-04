-- Construction income workflow for Madar Alwadi.
-- Adds AR-backed income invoices, receipts, allocations, and income contract sources.

ALTER TABLE `co_clients`
  ADD COLUMN IF NOT EXISTS `client_type` ENUM('customer','tenant','related_company','maintenance_customer') NOT NULL DEFAULT 'customer' AFTER `client_name`,
  ADD COLUMN IF NOT EXISTS `billing_contact` VARCHAR(255) DEFAULT NULL AFTER `contact_person`,
  ADD COLUMN IF NOT EXISTS `status` ENUM('active','inactive') NOT NULL DEFAULT 'active' AFTER `notes`;

ALTER TABLE `co_client_invoices`
  MODIFY COLUMN `project_id` INT(11) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `source_type` ENUM('construction_project','shop_rental','shop_commission','camp_management','maintenance_service','manual') NOT NULL DEFAULT 'construction_project' AFTER `client_id`,
  ADD COLUMN IF NOT EXISTS `source_id` INT(11) DEFAULT NULL AFTER `source_type`,
  ADD COLUMN IF NOT EXISTS `due_date` DATE DEFAULT NULL AFTER `invoice_date`,
  ADD COLUMN IF NOT EXISTS `subtotal` DECIMAL(15,2) NOT NULL DEFAULT 0.00 AFTER `due_date`,
  ADD COLUMN IF NOT EXISTS `description` VARCHAR(500) DEFAULT NULL AFTER `vat_amount`,
  ADD COLUMN IF NOT EXISTS `income_account_id` INT(11) DEFAULT NULL AFTER `description`;

CREATE INDEX IF NOT EXISTS `idx_co_client_invoices_source`
  ON `co_client_invoices` (`company_id`, `source_type`, `source_id`);

CREATE INDEX IF NOT EXISTS `idx_co_client_invoices_due`
  ON `co_client_invoices` (`company_id`, `due_date`, `status`);

UPDATE `co_client_invoices`
SET `source_type` = COALESCE(`source_type`, 'construction_project'),
    `due_date` = COALESCE(`due_date`, `invoice_date`),
    `subtotal` = CASE WHEN COALESCE(`subtotal`, 0) = 0 THEN GREATEST(`total_amount` - COALESCE(`vat_amount`, 0), 0) ELSE `subtotal` END
WHERE `company_id` IS NOT NULL;

ALTER TABLE `co_client_payments`
  ADD COLUMN IF NOT EXISTS `client_id` INT(11) DEFAULT NULL AFTER `invoice_id`,
  ADD COLUMN IF NOT EXISTS `pay_account_id` INT(11) DEFAULT NULL AFTER `amount`;

CREATE INDEX IF NOT EXISTS `idx_co_client_payments_client`
  ON `co_client_payments` (`company_id`, `client_id`);

CREATE INDEX IF NOT EXISTS `idx_co_client_payments_pay_account`
  ON `co_client_payments` (`pay_account_id`);

UPDATE `co_client_payments` p
JOIN `co_client_invoices` i ON i.id = p.invoice_id AND i.company_id = p.company_id
SET p.client_id = i.client_id
WHERE p.client_id IS NULL;

CREATE TABLE IF NOT EXISTS `co_client_payment_allocations` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `company_id` INT(11) NOT NULL,
  `payment_id` INT(11) NOT NULL,
  `invoice_id` INT(11) NOT NULL,
  `allocated_amount` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_co_client_payment_invoice` (`payment_id`, `invoice_id`),
  KEY `idx_company` (`company_id`),
  KEY `idx_payment` (`payment_id`),
  KEY `idx_invoice` (`invoice_id`),
  CONSTRAINT `fk_co_cp_alloc_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_co_cp_alloc_payment` FOREIGN KEY (`payment_id`) REFERENCES `co_client_payments` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_co_cp_alloc_invoice` FOREIGN KEY (`invoice_id`) REFERENCES `co_client_invoices` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT IGNORE INTO `co_client_payment_allocations`
    (`company_id`, `payment_id`, `invoice_id`, `allocated_amount`)
SELECT p.company_id, p.id, p.invoice_id, p.amount
FROM co_client_payments p
WHERE p.invoice_id IS NOT NULL;

CREATE TABLE IF NOT EXISTS `co_labor_camps` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `company_id` INT(11) NOT NULL,
  `camp_name` VARCHAR(255) NOT NULL,
  `location` VARCHAR(255) DEFAULT NULL,
  `notes` TEXT DEFAULT NULL,
  `status` ENUM('active','inactive') NOT NULL DEFAULT 'active',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `created_by` INT(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_co_labor_camp` (`company_id`, `camp_name`),
  KEY `idx_company` (`company_id`),
  CONSTRAINT `fk_co_labor_camps_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `co_shop_units` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `company_id` INT(11) NOT NULL,
  `camp_id` INT(11) DEFAULT NULL,
  `shop_number` VARCHAR(100) NOT NULL,
  `shop_name` VARCHAR(255) DEFAULT NULL,
  `location` VARCHAR(255) DEFAULT NULL,
  `status` ENUM('available','occupied','inactive') NOT NULL DEFAULT 'available',
  `notes` TEXT DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `created_by` INT(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_co_shop_unit` (`company_id`, `shop_number`),
  KEY `idx_company` (`company_id`),
  KEY `idx_camp` (`camp_id`),
  CONSTRAINT `fk_co_shop_units_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_co_shop_units_camp` FOREIGN KEY (`camp_id`) REFERENCES `co_labor_camps` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `co_shop_rental_contracts` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `company_id` INT(11) NOT NULL,
  `shop_unit_id` INT(11) NOT NULL,
  `client_id` INT(11) NOT NULL,
  `contract_number` VARCHAR(100) NOT NULL,
  `start_date` DATE NOT NULL,
  `end_date` DATE NOT NULL,
  `rent_amount` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  `payment_frequency` ENUM('monthly','quarterly','semi_annual','annual') NOT NULL DEFAULT 'monthly',
  `rent_cheque_count` INT(11) NOT NULL DEFAULT 0,
  `deposit_cheque_count` INT(11) NOT NULL DEFAULT 0,
  `accrual_deferred_rent` TINYINT(1) NOT NULL DEFAULT 0,
  `vat_rate` DECIMAL(5,2) NOT NULL DEFAULT 5.00,
  `security_deposit` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  `deposit_received_amount` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  `deposit_journal_id` INT(11) DEFAULT NULL,
  `payment_terms` VARCHAR(255) DEFAULT NULL,
  `status` ENUM('draft','active','expired','terminated') NOT NULL DEFAULT 'draft',
  `notes` TEXT DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `created_by` INT(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_co_shop_contract` (`company_id`, `contract_number`),
  KEY `idx_company` (`company_id`),
  KEY `idx_shop` (`shop_unit_id`),
  KEY `idx_client` (`client_id`),
  KEY `idx_dates` (`company_id`, `start_date`, `end_date`, `status`),
  CONSTRAINT `fk_co_shop_contract_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_co_shop_contract_shop` FOREIGN KEY (`shop_unit_id`) REFERENCES `co_shop_units` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_co_shop_contract_client` FOREIGN KEY (`client_id`) REFERENCES `co_clients` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_co_shop_contract_deposit_journal` FOREIGN KEY (`deposit_journal_id`) REFERENCES `re_journal_headers` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

ALTER TABLE `co_shop_rental_contracts`
  ADD COLUMN IF NOT EXISTS `rent_cheque_count` INT(11) NOT NULL DEFAULT 0 AFTER `payment_frequency`,
  ADD COLUMN IF NOT EXISTS `deposit_cheque_count` INT(11) NOT NULL DEFAULT 0 AFTER `rent_cheque_count`,
  ADD COLUMN IF NOT EXISTS `accrual_deferred_rent` TINYINT(1) NOT NULL DEFAULT 0 AFTER `deposit_cheque_count`;

CREATE TABLE IF NOT EXISTS `co_shop_rent_schedules` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `company_id` INT(11) NOT NULL,
  `contract_id` INT(11) NOT NULL,
  `period_start` DATE NOT NULL,
  `period_end` DATE NOT NULL,
  `due_date` DATE NOT NULL,
  `amount` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  `vat_amount` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  `cheque_id` INT(11) DEFAULT NULL,
  `invoice_id` INT(11) DEFAULT NULL,
  `recognized_at` DATETIME DEFAULT NULL,
  `recognized_journal_id` INT(11) DEFAULT NULL,
  `status` ENUM('pending','invoiced','cancelled') NOT NULL DEFAULT 'pending',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_co_shop_schedule` (`contract_id`, `period_start`, `period_end`),
  KEY `idx_company` (`company_id`),
  KEY `idx_contract` (`contract_id`),
  KEY `idx_cheque` (`cheque_id`),
  KEY `idx_invoice` (`invoice_id`),
  KEY `idx_recognized_journal` (`recognized_journal_id`),
  CONSTRAINT `fk_co_shop_schedule_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_co_shop_schedule_contract` FOREIGN KEY (`contract_id`) REFERENCES `co_shop_rental_contracts` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_co_shop_schedule_invoice` FOREIGN KEY (`invoice_id`) REFERENCES `co_client_invoices` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_co_shop_schedule_rec_journal` FOREIGN KEY (`recognized_journal_id`) REFERENCES `re_journal_headers` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

ALTER TABLE `co_shop_rent_schedules`
  ADD COLUMN IF NOT EXISTS `cheque_id` INT(11) DEFAULT NULL AFTER `vat_amount`,
  ADD COLUMN IF NOT EXISTS `recognized_at` DATETIME DEFAULT NULL AFTER `invoice_id`,
  ADD COLUMN IF NOT EXISTS `recognized_journal_id` INT(11) DEFAULT NULL AFTER `recognized_at`;

CREATE INDEX IF NOT EXISTS `idx_co_shop_rent_schedules_cheque`
  ON `co_shop_rent_schedules` (`cheque_id`);

CREATE TABLE IF NOT EXISTS `co_shop_rent_cheques` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `company_id` INT(11) NOT NULL,
  `contract_id` INT(11) NOT NULL,
  `schedule_id` INT(11) DEFAULT NULL,
  `cheque_type` ENUM('rent','security_deposit') NOT NULL DEFAULT 'rent',
  `cheque_number` VARCHAR(100) DEFAULT NULL,
  `bank_name` VARCHAR(255) DEFAULT NULL,
  `cheque_date` DATE NOT NULL,
  `amount` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  `status` ENUM('received','deposited','cleared','bounced','returned','replaced','cancelled') NOT NULL DEFAULT 'received',
  `invoice_id` INT(11) DEFAULT NULL,
  `payment_id` INT(11) DEFAULT NULL,
  `journal_id` INT(11) DEFAULT NULL,
  `notes` TEXT DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `created_by` INT(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_company` (`company_id`),
  KEY `idx_contract` (`contract_id`),
  KEY `idx_schedule` (`schedule_id`),
  KEY `idx_invoice` (`invoice_id`),
  KEY `idx_payment` (`payment_id`),
  KEY `idx_status_date` (`company_id`, `status`, `cheque_date`),
  CONSTRAINT `fk_co_shop_cheques_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_co_shop_cheques_contract` FOREIGN KEY (`contract_id`) REFERENCES `co_shop_rental_contracts` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_co_shop_cheques_schedule` FOREIGN KEY (`schedule_id`) REFERENCES `co_shop_rent_schedules` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_co_shop_cheques_invoice` FOREIGN KEY (`invoice_id`) REFERENCES `co_client_invoices` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_co_shop_cheques_payment` FOREIGN KEY (`payment_id`) REFERENCES `co_client_payments` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_co_shop_cheques_journal` FOREIGN KEY (`journal_id`) REFERENCES `re_journal_headers` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `co_shop_rent_recognitions` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `company_id` INT(11) NOT NULL,
  `schedule_id` INT(11) NOT NULL,
  `recognition_month` DATE NOT NULL,
  `amount` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  `journal_id` INT(11) DEFAULT NULL,
  `recognized_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `recognized_by` INT(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_co_shop_recognition_month` (`schedule_id`, `recognition_month`),
  KEY `idx_company_month` (`company_id`, `recognition_month`),
  KEY `idx_journal` (`journal_id`),
  CONSTRAINT `fk_co_shop_rec_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_co_shop_rec_schedule` FOREIGN KEY (`schedule_id`) REFERENCES `co_shop_rent_schedules` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_co_shop_rec_journal` FOREIGN KEY (`journal_id`) REFERENCES `re_journal_headers` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `co_camp_management_contracts` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `company_id` INT(11) NOT NULL,
  `camp_id` INT(11) NOT NULL,
  `client_id` INT(11) NOT NULL,
  `contract_number` VARCHAR(100) NOT NULL,
  `start_date` DATE NOT NULL,
  `end_date` DATE NOT NULL,
  `monthly_amount` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  `commission_rate` DECIMAL(5,2) NOT NULL DEFAULT 0.00,
  `commission_vat_rate` DECIMAL(5,2) NOT NULL DEFAULT 0.00,
  `remittance_frequency` ENUM('monthly','quarterly','semi_annual','annual','custom') NOT NULL DEFAULT 'monthly',
  `vat_rate` DECIMAL(5,2) NOT NULL DEFAULT 5.00,
  `payment_terms` VARCHAR(255) DEFAULT NULL,
  `status` ENUM('draft','active','expired','terminated') NOT NULL DEFAULT 'draft',
  `notes` TEXT DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `created_by` INT(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_co_camp_mgmt_contract` (`company_id`, `contract_number`),
  KEY `idx_company` (`company_id`),
  KEY `idx_camp` (`camp_id`),
  KEY `idx_client` (`client_id`),
  CONSTRAINT `fk_co_camp_contract_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_co_camp_contract_camp` FOREIGN KEY (`camp_id`) REFERENCES `co_labor_camps` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_co_camp_contract_client` FOREIGN KEY (`client_id`) REFERENCES `co_clients` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

ALTER TABLE `co_camp_management_contracts`
  ADD COLUMN IF NOT EXISTS `commission_rate` DECIMAL(5,2) NOT NULL DEFAULT 0.00 AFTER `monthly_amount`,
  ADD COLUMN IF NOT EXISTS `commission_vat_rate` DECIMAL(5,2) NOT NULL DEFAULT 0.00 AFTER `commission_rate`,
  ADD COLUMN IF NOT EXISTS `remittance_frequency` ENUM('monthly','quarterly','semi_annual','annual','custom') NOT NULL DEFAULT 'monthly' AFTER `commission_vat_rate`;

CREATE TABLE IF NOT EXISTS `co_camp_agent_settlements` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `company_id` INT(11) NOT NULL,
  `contract_id` INT(11) NOT NULL,
  `settlement_number` VARCHAR(100) NOT NULL,
  `period_start` DATE NOT NULL,
  `period_end` DATE NOT NULL,
  `settlement_date` DATE NOT NULL,
  `gross_rent_amount` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  `output_vat_rate` DECIMAL(5,2) NOT NULL DEFAULT 0.00,
  `output_vat_amount` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  `commission_rate` DECIMAL(5,2) NOT NULL DEFAULT 0.00,
  `commission_amount` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  `commission_vat_rate` DECIMAL(5,2) NOT NULL DEFAULT 0.00,
  `commission_vat_amount` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  `other_deductions` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  `net_receivable` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  `status` ENUM('posted','partial','paid','cancelled') NOT NULL DEFAULT 'posted',
  `journal_id` INT(11) DEFAULT NULL,
  `notes` TEXT DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `created_by` INT(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_co_camp_settlement_number` (`company_id`, `settlement_number`),
  KEY `idx_company` (`company_id`),
  KEY `idx_contract` (`contract_id`),
  KEY `idx_dates` (`company_id`, `settlement_date`, `status`),
  KEY `idx_journal` (`journal_id`),
  CONSTRAINT `fk_co_camp_settlement_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_co_camp_settlement_contract` FOREIGN KEY (`contract_id`) REFERENCES `co_camp_management_contracts` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_co_camp_settlement_journal` FOREIGN KEY (`journal_id`) REFERENCES `re_journal_headers` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `co_camp_agent_receipts` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `company_id` INT(11) NOT NULL,
  `settlement_id` INT(11) NOT NULL,
  `receipt_date` DATE NOT NULL,
  `amount` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  `pay_account_id` INT(11) NOT NULL,
  `reference` VARCHAR(255) DEFAULT NULL,
  `journal_id` INT(11) DEFAULT NULL,
  `notes` TEXT DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `created_by` INT(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_company` (`company_id`),
  KEY `idx_settlement` (`settlement_id`),
  KEY `idx_pay_account` (`pay_account_id`),
  KEY `idx_journal` (`journal_id`),
  CONSTRAINT `fk_co_camp_receipt_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_co_camp_receipt_settlement` FOREIGN KEY (`settlement_id`) REFERENCES `co_camp_agent_settlements` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_co_camp_receipt_pay_account` FOREIGN KEY (`pay_account_id`) REFERENCES `re_chart_of_accounts` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_co_camp_receipt_journal` FOREIGN KEY (`journal_id`) REFERENCES `re_journal_headers` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `co_camp_income_schedules` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `company_id` INT(11) NOT NULL,
  `contract_id` INT(11) NOT NULL,
  `period_start` DATE NOT NULL,
  `period_end` DATE NOT NULL,
  `due_date` DATE NOT NULL,
  `amount` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  `vat_amount` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  `invoice_id` INT(11) DEFAULT NULL,
  `status` ENUM('pending','invoiced','cancelled') NOT NULL DEFAULT 'pending',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_co_camp_schedule` (`contract_id`, `period_start`, `period_end`),
  KEY `idx_company` (`company_id`),
  KEY `idx_contract` (`contract_id`),
  KEY `idx_invoice` (`invoice_id`),
  CONSTRAINT `fk_co_camp_schedule_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_co_camp_schedule_contract` FOREIGN KEY (`contract_id`) REFERENCES `co_camp_management_contracts` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_co_camp_schedule_invoice` FOREIGN KEY (`invoice_id`) REFERENCES `co_client_invoices` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `co_maintenance_contracts` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `company_id` INT(11) NOT NULL,
  `client_id` INT(11) NOT NULL,
  `project_id` INT(11) DEFAULT NULL,
  `contract_number` VARCHAR(100) NOT NULL,
  `service_name` VARCHAR(255) NOT NULL,
  `building_or_project_name` VARCHAR(255) DEFAULT NULL,
  `service_type` ENUM('one_time','recurring') NOT NULL DEFAULT 'one_time',
  `start_date` DATE NOT NULL,
  `end_date` DATE DEFAULT NULL,
  `amount` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  `billing_frequency` ENUM('one_time','monthly','quarterly','semi_annual','annual') NOT NULL DEFAULT 'one_time',
  `vat_rate` DECIMAL(5,2) NOT NULL DEFAULT 5.00,
  `payment_terms` VARCHAR(255) DEFAULT NULL,
  `status` ENUM('draft','active','completed','expired','terminated') NOT NULL DEFAULT 'draft',
  `notes` TEXT DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `created_by` INT(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_co_maintenance_contract` (`company_id`, `contract_number`),
  KEY `idx_company` (`company_id`),
  KEY `idx_client` (`client_id`),
  KEY `idx_project` (`project_id`),
  CONSTRAINT `fk_co_maint_contract_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_co_maint_contract_client` FOREIGN KEY (`client_id`) REFERENCES `co_clients` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_co_maint_contract_project` FOREIGN KEY (`project_id`) REFERENCES `co_projects` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `co_maintenance_invoice_schedules` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `company_id` INT(11) NOT NULL,
  `contract_id` INT(11) NOT NULL,
  `period_start` DATE NOT NULL,
  `period_end` DATE NOT NULL,
  `due_date` DATE NOT NULL,
  `amount` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  `vat_amount` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  `invoice_id` INT(11) DEFAULT NULL,
  `status` ENUM('pending','invoiced','cancelled') NOT NULL DEFAULT 'pending',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_co_maint_schedule` (`contract_id`, `period_start`, `period_end`),
  KEY `idx_company` (`company_id`),
  KEY `idx_contract` (`contract_id`),
  KEY `idx_invoice` (`invoice_id`),
  CONSTRAINT `fk_co_maint_schedule_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_co_maint_schedule_contract` FOREIGN KEY (`contract_id`) REFERENCES `co_maintenance_contracts` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_co_maint_schedule_invoice` FOREIGN KEY (`invoice_id`) REFERENCES `co_client_invoices` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
