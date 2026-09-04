-- Construction Shop Rental Phase 2 (Payment Maturity) — Payment Workspace / allocation
-- Construction co_* only. Does NOT modify Real Estate tables or accounting_engine.
-- Default: one receiving GL debit per payment; funding rows are operational detail.
-- Rollback intent: DROP new tables/columns after clearing FKs (company-scoped).

-- Payment lifecycle / audit
ALTER TABLE `co_client_payments`
  ADD COLUMN IF NOT EXISTS `allocation_status` ENUM(
    'unallocated','partial','allocated','overpaid','reversed','voided'
  ) NOT NULL DEFAULT 'unallocated' AFTER `credit_amount`,
  ADD COLUMN IF NOT EXISTS `contract_id` INT(11) DEFAULT NULL AFTER `client_id`,
  ADD COLUMN IF NOT EXISTS `voided_at` DATETIME DEFAULT NULL AFTER `allocation_status`,
  ADD COLUMN IF NOT EXISTS `void_reason` VARCHAR(500) DEFAULT NULL AFTER `voided_at`,
  ADD COLUMN IF NOT EXISTS `reversed_payment_id` INT(11) DEFAULT NULL COMMENT 'Successor payment after reverse/reallocate' AFTER `void_reason`;

CREATE INDEX IF NOT EXISTS `idx_co_client_payments_alloc_status`
  ON `co_client_payments` (`company_id`, `allocation_status`);

CREATE INDEX IF NOT EXISTS `idx_co_client_payments_contract`
  ON `co_client_payments` (`company_id`, `contract_id`);

-- Multi-funding sources (sum must equal payment.amount on confirm)
CREATE TABLE IF NOT EXISTS `co_client_payment_funding_sources` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `company_id` INT(11) NOT NULL,
  `payment_id` INT(11) NOT NULL,
  `method` ENUM('cash','bank_transfer','cheque') NOT NULL DEFAULT 'bank_transfer',
  `amount` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  `reference` VARCHAR(120) DEFAULT NULL,
  `funding_date` DATE DEFAULT NULL,
  `bank_account_id` INT(11) DEFAULT NULL COMMENT 'Optional COA id for funding bank detail',
  `cheque_id` INT(11) DEFAULT NULL COMMENT 'co_shop_rent_cheques.id when method=cheque',
  `remarks` VARCHAR(500) DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_co_pay_funding_payment` (`company_id`, `payment_id`),
  KEY `idx_co_pay_funding_cheque` (`company_id`, `cheque_id`),
  CONSTRAINT `fk_co_pay_funding_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_co_pay_funding_payment` FOREIGN KEY (`payment_id`) REFERENCES `co_client_payments` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Many-to-many cheque ↔ receipt (Phase 3 multi-cheque; Phase 2 enables single/multi links)
CREATE TABLE IF NOT EXISTS `co_shop_receipt_cheque_links` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `company_id` INT(11) NOT NULL,
  `payment_id` INT(11) NOT NULL,
  `cheque_id` INT(11) NOT NULL,
  `amount` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_co_receipt_cheque` (`payment_id`, `cheque_id`),
  KEY `idx_co_receipt_cheque_company` (`company_id`, `cheque_id`),
  CONSTRAINT `fk_co_receipt_cheque_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_co_receipt_cheque_payment` FOREIGN KEY (`payment_id`) REFERENCES `co_client_payments` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_co_receipt_cheque_cheque` FOREIGN KEY (`cheque_id`) REFERENCES `co_shop_rent_cheques` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Reverse / void / reallocate audit (never silent history rewrite)
CREATE TABLE IF NOT EXISTS `co_client_payment_events` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `company_id` INT(11) NOT NULL,
  `payment_id` INT(11) NOT NULL,
  `contract_id` INT(11) DEFAULT NULL,
  `event_type` VARCHAR(64) NOT NULL COMMENT 'confirm|apply_credit|reverse|void|reallocate|preview_note',
  `payload_json` LONGTEXT DEFAULT NULL,
  `created_by` INT(11) DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_co_pay_events_payment` (`company_id`, `payment_id`, `created_at`),
  KEY `idx_co_pay_events_contract` (`company_id`, `contract_id`, `created_at`),
  CONSTRAINT `fk_co_pay_events_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_co_pay_events_payment` FOREIGN KEY (`payment_id`) REFERENCES `co_client_payments` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Company default auto-allocation strategy for Payment Workspace
ALTER TABLE `co_shop_rental_settings`
  ADD COLUMN IF NOT EXISTS `allocation_strategy` ENUM(
    'fifo','oldest_due','charge_priority','manual'
  ) NOT NULL DEFAULT 'fifo' AFTER `suggested_missing_keys_amount`;
