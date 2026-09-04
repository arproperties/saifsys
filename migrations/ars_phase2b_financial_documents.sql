-- ============================================================================
-- ARS Phase 2B — Option B Financial Documents + Account Role Mapping + Adapter flag
-- Additive only. Re-runnable. Does NOT modify accounting_engine / re_invoices / re_leases / re_tenants.
-- Live: do not run without separate human approval.
-- ============================================================================

SET @db := DATABASE();

-- ---------------------------------------------------------------------------
-- M4-ish: adapter feature flag on ars_company_settings
-- ---------------------------------------------------------------------------
SET @sql := (
  SELECT IF(
    EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='ars_company_settings' AND COLUMN_NAME='financial_adapter_enabled'),
    'SELECT 1',
    "ALTER TABLE `ars_company_settings`
       ADD COLUMN `financial_adapter_enabled` TINYINT(1) NOT NULL DEFAULT 0
         COMMENT '1 = ARS Financial Adapter path for new posts'"
  )
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------------
-- Account role → GL account mapping (never hard-code account IDs in adapter)
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `ars_account_role_map` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `company_id` INT(11) NOT NULL,
  `role_code` VARCHAR(64) NOT NULL,
  `account_code` VARCHAR(32) NOT NULL COMMENT 'Resolved via find_account_by_code',
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `notes` VARCHAR(255) DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_ars_role_company` (`company_id`, `role_code`),
  KEY `idx_ars_role_active` (`company_id`, `is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed default roles for ARS company (code ARS) if present — codes match current bridge
INSERT IGNORE INTO `ars_account_role_map` (`company_id`, `role_code`, `account_code`, `notes`)
SELECT c.id, v.role_code, v.account_code, v.notes
FROM `companies` c
CROSS JOIN (
  SELECT 'AR_GUEST' AS role_code, '1310' AS account_code, 'AR - Guests' AS notes
  UNION ALL SELECT 'ROOM_REVENUE', '4100', 'Room Revenue'
  UNION ALL SELECT 'ADDITIONAL_SERVICE_REVENUE', '4100', 'Default same as room until BC confirms split'
  UNION ALL SELECT 'DAMAGE_REVENUE', '4100', 'Default same as room until BC-11'
  UNION ALL SELECT 'VAT_OUTPUT', '2310', 'Output VAT'
  UNION ALL SELECT 'SECURITY_DEPOSIT', '2200', 'Guest deposits held'
  UNION ALL SELECT 'DEFERRED_REVENUE', '2400', 'Seeded; unused until BC-02/03'
  UNION ALL SELECT 'CASH', '1110', 'Cash on hand'
  UNION ALL SELECT 'BANK', '1210', 'Bank'
  UNION ALL SELECT 'STRIPE_CLEARING', '1110', 'Interim=cash until BC-12/13 Confirmed'
  UNION ALL SELECT 'REFUND', '1110', 'Refund cash/bank default'
  UNION ALL SELECT 'DISCOUNT', '4100', 'Contra via lower revenue until BC'
  UNION ALL SELECT 'BAD_DEBT', '4100', 'Placeholder until BC'
  UNION ALL SELECT 'ROUNDING', '4100', 'Placeholder until BC'
) v
WHERE c.code = 'ARS' AND c.is_active = 1;

-- ---------------------------------------------------------------------------
-- Financial documents header (state machine statuses include Phase 2B lifecycle)
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `ars_financial_documents` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `company_id` INT(11) NOT NULL,
  `booking_id` INT(11) NOT NULL,
  `guest_id` INT(11) DEFAULT NULL,
  `document_type` ENUM(
    'original_invoice','extension_invoice','service_invoice','adjustment_invoice','credit_note'
  ) NOT NULL,
  `document_number` VARCHAR(40) NOT NULL,
  `document_date` DATE NOT NULL,
  `status` ENUM(
    'draft','validated','posted','partially_paid','paid','closed',
    'cancelled','voided','reversed','refunded'
  ) NOT NULL DEFAULT 'draft',
  `currency` CHAR(3) NOT NULL DEFAULT 'AED',
  `subtotal` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `vat_amount` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `total_amount` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `amount_allocated` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `balance_due` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `vat_mode` VARCHAR(20) DEFAULT NULL,
  `vat_rate` DECIMAL(5,2) DEFAULT NULL,
  `journal_id` INT(11) DEFAULT NULL,
  `reversal_journal_id` INT(11) DEFAULT NULL,
  `parent_document_id` BIGINT UNSIGNED DEFAULT NULL,
  `idempotency_key` VARCHAR(120) DEFAULT NULL,
  `source` VARCHAR(40) NOT NULL DEFAULT 'adapter',
  `notes` TEXT DEFAULT NULL,
  `created_by` INT(11) DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `validated_at` DATETIME DEFAULT NULL,
  `validated_by` INT(11) DEFAULT NULL,
  `posted_at` DATETIME DEFAULT NULL,
  `posted_by` INT(11) DEFAULT NULL,
  `closed_at` DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_ars_fin_doc_number` (`company_id`, `document_number`),
  UNIQUE KEY `uq_ars_fin_doc_idem` (`company_id`, `idempotency_key`),
  KEY `idx_ars_fin_doc_booking` (`company_id`, `booking_id`, `document_date`),
  KEY `idx_ars_fin_doc_status` (`company_id`, `status`),
  KEY `idx_ars_fin_doc_journal` (`journal_id`),
  KEY `idx_ars_fin_doc_parent` (`parent_document_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ars_financial_document_lines` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `company_id` INT(11) NOT NULL,
  `document_id` BIGINT UNSIGNED NOT NULL,
  `line_no` INT NOT NULL,
  `line_type` VARCHAR(40) NOT NULL DEFAULT 'other',
  `description` VARCHAR(255) NOT NULL,
  `quantity` DECIMAL(12,4) NOT NULL DEFAULT 1.0000,
  `unit_price` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `line_total` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `vat_rate` DECIMAL(5,2) DEFAULT NULL,
  `vat_amount` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `account_role` VARCHAR(64) DEFAULT NULL,
  `related_charge_id` INT(11) DEFAULT NULL,
  `meta_json` JSON DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_ars_fin_line` (`document_id`, `line_no`),
  KEY `idx_ars_fin_line_doc` (`document_id`),
  KEY `idx_ars_fin_line_company` (`company_id`, `document_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ars_payment_allocations` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `company_id` INT(11) NOT NULL,
  `booking_id` INT(11) NOT NULL,
  `payment_id` INT(11) NOT NULL,
  `document_id` BIGINT UNSIGNED NOT NULL,
  `amount` DECIMAL(12,2) NOT NULL,
  `allocation_date` DATE NOT NULL,
  `journal_id` INT(11) DEFAULT NULL,
  `status` ENUM('active','reversed') NOT NULL DEFAULT 'active',
  `idempotency_key` VARCHAR(120) DEFAULT NULL,
  `created_by` INT(11) DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_ars_alloc_idem` (`company_id`, `idempotency_key`),
  KEY `idx_ars_alloc_payment` (`payment_id`),
  KEY `idx_ars_alloc_doc` (`document_id`),
  KEY `idx_ars_alloc_booking` (`company_id`, `booking_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ars_refunds` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `company_id` INT(11) NOT NULL,
  `booking_id` INT(11) NOT NULL,
  `refund_number` VARCHAR(40) NOT NULL,
  `payment_id` INT(11) DEFAULT NULL,
  `credit_note_document_id` BIGINT UNSIGNED DEFAULT NULL,
  `amount` DECIMAL(12,2) NOT NULL,
  `method` VARCHAR(30) DEFAULT NULL,
  `status` ENUM('draft','validated','posted','failed','reversed') NOT NULL DEFAULT 'draft',
  `journal_id` INT(11) DEFAULT NULL,
  `idempotency_key` VARCHAR(120) DEFAULT NULL,
  `notes` TEXT DEFAULT NULL,
  `created_by` INT(11) DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `posted_at` DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_ars_refund_number` (`company_id`, `refund_number`),
  UNIQUE KEY `uq_ars_refund_idem` (`company_id`, `idempotency_key`),
  KEY `idx_ars_refund_booking` (`company_id`, `booking_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ars_security_deposits` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `company_id` INT(11) NOT NULL,
  `booking_id` INT(11) NOT NULL,
  `deposit_number` VARCHAR(40) NOT NULL,
  `event_type` ENUM('hold_set','received','partial_refund','full_refund','forfeit') NOT NULL,
  `amount` DECIMAL(12,2) NOT NULL,
  `status` ENUM('draft','validated','posted','reversed') NOT NULL DEFAULT 'draft',
  `journal_id` INT(11) DEFAULT NULL,
  `payment_id` INT(11) DEFAULT NULL,
  `idempotency_key` VARCHAR(120) DEFAULT NULL,
  `notes` TEXT DEFAULT NULL,
  `created_by` INT(11) DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `posted_at` DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_ars_dep_number` (`company_id`, `deposit_number`),
  UNIQUE KEY `uq_ars_dep_idem` (`company_id`, `idempotency_key`),
  KEY `idx_ars_dep_booking` (`company_id`, `booking_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ars_extension_documents` (
  `document_id` BIGINT UNSIGNED NOT NULL,
  `company_id` INT(11) NOT NULL,
  `prior_check_out` DATE DEFAULT NULL,
  `new_check_out` DATE DEFAULT NULL,
  `added_nights` INT DEFAULT NULL,
  `rate_basis` VARCHAR(100) DEFAULT NULL,
  PRIMARY KEY (`document_id`),
  KEY `idx_ars_ext_company` (`company_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ars_credit_notes` (
  `document_id` BIGINT UNSIGNED NOT NULL,
  `company_id` INT(11) NOT NULL,
  `reason_code` VARCHAR(40) NOT NULL DEFAULT 'other',
  `applies_to_document_id` BIGINT UNSIGNED DEFAULT NULL,
  PRIMARY KEY (`document_id`),
  KEY `idx_ars_cn_company` (`company_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ars_adjustments` (
  `document_id` BIGINT UNSIGNED NOT NULL,
  `company_id` INT(11) NOT NULL,
  `reason_code` VARCHAR(40) NOT NULL DEFAULT 'manual',
  `applies_to_document_id` BIGINT UNSIGNED DEFAULT NULL,
  PRIMARY KEY (`document_id`),
  KEY `idx_ars_adj_company` (`company_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ars_financial_document_transitions` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `company_id` INT(11) NOT NULL,
  `document_id` BIGINT UNSIGNED NOT NULL,
  `from_status` VARCHAR(40) DEFAULT NULL,
  `to_status` VARCHAR(40) NOT NULL,
  `changed_by` INT(11) DEFAULT NULL,
  `changed_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `note` VARCHAR(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_ars_fin_tr_doc` (`document_id`, `changed_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Additive nullable links on existing tables
SET @sql := (
  SELECT IF(
    EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='ars_booking_payments' AND COLUMN_NAME='financial_document_id'),
    'SELECT 1',
    'ALTER TABLE `ars_booking_payments` ADD COLUMN `financial_document_id` BIGINT UNSIGNED DEFAULT NULL AFTER `journal_id`'
  )
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := (
  SELECT IF(
    EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='ars_bookings' AND COLUMN_NAME='primary_invoice_document_id'),
    'SELECT 1',
    'ALTER TABLE `ars_bookings` ADD COLUMN `primary_invoice_document_id` BIGINT UNSIGNED DEFAULT NULL AFTER `journal_id`'
  )
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := (
  SELECT IF(
    EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='ars_booking_charges' AND COLUMN_NAME='financial_document_id'),
    'SELECT 1',
    'ALTER TABLE `ars_booking_charges` ADD COLUMN `financial_document_id` BIGINT UNSIGNED DEFAULT NULL'
  )
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SELECT 'ARS Phase 2B Option B financial documents migration complete.' AS notice;
