-- Construction Shop Rental Phase 1 (Madar Al Wadi commercial leasing).
-- Additive only. Company-isolated co_* tables. Does not touch RE lease tables.
-- Rollback intent: DROP new columns/tables listed below after backup.

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

-- Backfill net_amount from amount where needed
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
