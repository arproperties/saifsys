-- ============================================================================
-- Real Estate Accounting - Critical Controls (Feature Parity)
-- Period locking, opening balance, duplicate prevention, audit fields
-- ============================================================================
-- Run after create_real_estate_accounting_tables.sql and seed_real_estate_chart_of_accounts.sql
-- ============================================================================

SET @dbname = DATABASE();

-- ----------------------------------------------------------------------------
-- 1. Ensure re_fiscal_years exists (used by periods.php / period_close.php)
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `re_fiscal_years` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `company_id` INT(11) NOT NULL,
  `year_name` VARCHAR(100) NOT NULL,
  `start_date` DATE NOT NULL,
  `end_date` DATE NOT NULL,
  `is_active` TINYINT(1) DEFAULT 1,
  `is_closed` TINYINT(1) DEFAULT 0,
  `closed_at` DATETIME DEFAULT NULL,
  `closed_by` INT(11) DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_company` (`company_id`),
  KEY `idx_dates` (`start_date`, `end_date`),
  KEY `idx_closed` (`is_closed`),
  FOREIGN KEY (`company_id`) REFERENCES `companies`(`id`) ON DELETE RESTRICT,
  FOREIGN KEY (`closed_by`) REFERENCES `user`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- If re_fiscal_years already existed without these columns, run the line below once (then comment out):
-- ALTER TABLE `re_fiscal_years` ADD COLUMN `is_closed` TINYINT(1) DEFAULT 0, ADD COLUMN `closed_at` DATETIME DEFAULT NULL, ADD COLUMN `closed_by` INT(11) DEFAULT NULL;

-- ----------------------------------------------------------------------------
-- 2. Add 'opening_balance' to journal_type ENUM (re_journal_headers)
-- ----------------------------------------------------------------------------
ALTER TABLE `re_journal_headers`
  MODIFY COLUMN `journal_type` ENUM(
    'manual', 'invoice', 'payment', 'deposit', 'refund', 'adjustment',
    'recurring', 'reversal', 'opening_balance', 'closing'
  ) NOT NULL;

-- ----------------------------------------------------------------------------
-- 3. Audit trail: modified_by, modified_at on journal_headers
-- ----------------------------------------------------------------------------
-- If you get "Duplicate column" error, the columns already exist — comment out the next 3 lines.
ALTER TABLE `re_journal_headers`
  ADD COLUMN `modified_by` INT(11) DEFAULT NULL AFTER `created_by`,
  ADD COLUMN `modified_at` DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP;

-- ----------------------------------------------------------------------------
-- 4. Duplicate journal prevention: unique (company_id, reference_type, reference_id)
--    Only for non-NULL reference_id; application will check when both are set.
--    MySQL UNIQUE allows multiple NULLs, so manual/opening_balance won't conflict.
-- ----------------------------------------------------------------------------
-- Add unique index only when reference_type and reference_id are both non-null.
-- We use a simple unique key; application code will prevent duplicate (ref_type, ref_id) for same company.
-- Optional: uncomment to enforce at DB level (then one journal per invoice/payment per company):
-- ALTER TABLE `re_journal_headers` ADD UNIQUE KEY `uq_company_reference` (`company_id`, `reference_type`(50), `reference_id`);
-- (Commented: allows multiple manual entries; duplicate check done in code for ref_type+ref_id.)

-- ============================================================================
-- END
-- ============================================================================
