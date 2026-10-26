-- Employee bills (HR > Employee > Bills tab).
-- An item has an approved amount per period — daily, weekly (Mon–Sun), monthly
-- or yearly (Jan–Dec) — set once on an asset marked "has bill" (a company SIM)
-- or on an item with no asset (fuel). Periods within the approved amount are
-- not stored. HR enters an actual only when it goes over; the excess becomes a
-- 'bill_excess' deduction in employee_deductions, which payroll already reads.
-- Unused allowance is not carried forward.
-- Safe to re-run, and upgrades a database that ran the first (monthly-only) version.

-- Assets: 'issued' is shown as Active. 'suspended' = still with the employee, no bills.
ALTER TABLE `employee_assets`
  MODIFY `status` enum('issued','suspended','returned','lost') NOT NULL DEFAULT 'issued';
ALTER TABLE `employee_assets`
  ADD COLUMN IF NOT EXISTS `has_bill` tinyint(1) NOT NULL DEFAULT 0 AFTER `status`;
ALTER TABLE `employee_assets`
  CHANGE COLUMN IF EXISTS `approved_monthly` `bill_approved` decimal(12,2) DEFAULT NULL;
ALTER TABLE `employee_assets`
  ADD COLUMN IF NOT EXISTS `bill_approved` decimal(12,2) DEFAULT NULL AFTER `has_bill`,
  ADD COLUMN IF NOT EXISTS `bill_frequency` enum('daily','weekly','monthly','yearly') NOT NULL DEFAULT 'monthly' AFTER `bill_approved`;

-- Deductions created from a bill's excess.
ALTER TABLE `employee_deductions`
  MODIFY `dtype` enum('fine','charge_duty','uniform','other','bill_excess') NOT NULL DEFAULT 'other';

-- Bill items with no asset behind them (fuel, phone allowance).
CREATE TABLE IF NOT EXISTS `employee_bill_items` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `employee_id` int(11) NOT NULL,
  `item_name` varchar(120) NOT NULL,
  `approved_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `frequency` enum('daily','weekly','monthly','yearly') NOT NULL DEFAULT 'monthly',
  `start_date` date DEFAULT NULL,
  `status` enum('active','suspended') NOT NULL DEFAULT 'active',
  `notes` varchar(255) DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_ebi_emp` (`employee_id`),
  CONSTRAINT `fk_ebi_emp` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
ALTER TABLE `employee_bill_items`
  CHANGE COLUMN IF EXISTS `approved_monthly` `approved_amount` decimal(12,2) NOT NULL DEFAULT 0.00;
ALTER TABLE `employee_bill_items`
  ADD COLUMN IF NOT EXISTS `frequency` enum('daily','weekly','monthly','yearly') NOT NULL DEFAULT 'monthly' AFTER `approved_amount`,
  ADD COLUMN IF NOT EXISTS `start_date` date DEFAULT NULL AFTER `frequency`;
UPDATE `employee_bill_items` SET `start_date` = DATE(`created_at`) WHERE `start_date` IS NULL;

-- One entry per item per period. bill_month holds the period's first day
-- (a date, a Monday, the 1st, or 1 Jan); period_end its last. Exactly one of
-- asset_id / bill_item_id is set when entered; item_name and approved_amount
-- are copied so history survives later edits.
CREATE TABLE IF NOT EXISTS `employee_bills` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `employee_id` int(11) NOT NULL,
  `asset_id` int(11) DEFAULT NULL,
  `bill_item_id` int(11) DEFAULT NULL,
  `item_name` varchar(120) NOT NULL,
  `frequency` enum('daily','weekly','monthly','yearly') NOT NULL DEFAULT 'monthly',
  `bill_month` date NOT NULL COMMENT 'First day of the period',
  `period_end` date DEFAULT NULL COMMENT 'Last day of the period',
  `approved_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `bill_amount` decimal(12,2) NOT NULL,
  `excess_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `deduction_id` int(11) DEFAULT NULL,
  `evidence_path` varchar(255) DEFAULT NULL,
  `evidence_name` varchar(255) DEFAULT NULL,
  `notes` varchar(255) DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_by` int(11) DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_ebill_asset_period` (`asset_id`, `frequency`, `bill_month`),
  UNIQUE KEY `uq_ebill_item_period` (`bill_item_id`, `frequency`, `bill_month`),
  KEY `idx_ebill_emp_month` (`employee_id`, `bill_month`),
  CONSTRAINT `fk_ebill_emp` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_ebill_asset` FOREIGN KEY (`asset_id`) REFERENCES `employee_assets` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_ebill_item` FOREIGN KEY (`bill_item_id`) REFERENCES `employee_bill_items` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_ebill_deduction` FOREIGN KEY (`deduction_id`) REFERENCES `employee_deductions` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
-- Upgrade from the monthly-only version: add the period columns, swap the
-- per-month unique keys for per-period ones (new key first: the FKs need an index).
ALTER TABLE `employee_bills`
  ADD COLUMN IF NOT EXISTS `frequency` enum('daily','weekly','monthly','yearly') NOT NULL DEFAULT 'monthly' AFTER `item_name`,
  ADD COLUMN IF NOT EXISTS `period_end` date DEFAULT NULL COMMENT 'Last day of the period' AFTER `bill_month`;
ALTER TABLE `employee_bills`
  ADD UNIQUE KEY IF NOT EXISTS `uq_ebill_asset_period` (`asset_id`, `frequency`, `bill_month`),
  ADD UNIQUE KEY IF NOT EXISTS `uq_ebill_item_period` (`bill_item_id`, `frequency`, `bill_month`);
ALTER TABLE `employee_bills`
  DROP INDEX IF EXISTS `uq_ebill_asset_month`,
  DROP INDEX IF EXISTS `uq_ebill_item_month`;
UPDATE `employee_bills` SET `period_end` = LAST_DAY(`bill_month`) WHERE `period_end` IS NULL;

-- Approval: an entry is Pending until HR approves it or a payroll run includes
-- its excess. Approved entries can no longer be edited or deleted.
ALTER TABLE `employee_bills`
  ADD COLUMN IF NOT EXISTS `status` enum('pending','approved') NOT NULL DEFAULT 'pending' AFTER `deduction_id`,
  ADD COLUMN IF NOT EXISTS `approved_by` int(11) DEFAULT NULL AFTER `status`,
  ADD COLUMN IF NOT EXISTS `approved_at` datetime DEFAULT NULL AFTER `approved_by`,
  ADD COLUMN IF NOT EXISTS `approved_run_id` int(11) DEFAULT NULL COMMENT 'Payroll run that included it, when approved that way' AFTER `approved_at`;
