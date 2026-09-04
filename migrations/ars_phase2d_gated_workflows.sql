-- ============================================================================
-- ARS Phase 2D — Guest credit, Stripe settlement sim, policy, COA roles
-- Additive only. Localhost. Re-runnable.
-- ============================================================================

SET @db := DATABASE();

-- Policy table
CREATE TABLE IF NOT EXISTS `ars_financial_policy` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `company_id` INT(11) NOT NULL,
  `early_checkout_refundable` TINYINT(1) NOT NULL DEFAULT 1,
  `cancellation_fee_percent` DECIMAL(5,2) NOT NULL DEFAULT 0.00,
  `no_show_fee_mode` VARCHAR(32) NOT NULL DEFAULT 'keep_revenue',
  `stripe_fee_percent` DECIMAL(5,2) NOT NULL DEFAULT 0.00,
  `overpay_to_guest_credit` TINYINT(1) NOT NULL DEFAULT 1,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_ars_fin_policy_co` (`company_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `ars_financial_policy` (`company_id`)
SELECT id FROM `companies` WHERE code = 'ARS' AND is_active = 1;

-- Guest credits
CREATE TABLE IF NOT EXISTS `ars_guest_credits` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `company_id` INT(11) NOT NULL,
  `guest_id` INT(11) NOT NULL,
  `booking_id` INT(11) DEFAULT NULL,
  `payment_id` INT(11) DEFAULT NULL,
  `credit_note_document_id` BIGINT UNSIGNED DEFAULT NULL,
  `source_type` VARCHAR(40) NOT NULL,
  `amount` DECIMAL(12,2) NOT NULL,
  `amount_applied` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `amount_refunded` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `balance` DECIMAL(12,2) NOT NULL,
  `status` ENUM('open','partial','closed','refunded') NOT NULL DEFAULT 'open',
  `journal_id` INT(11) DEFAULT NULL,
  `idempotency_key` VARCHAR(120) DEFAULT NULL,
  `notes` VARCHAR(255) DEFAULT NULL,
  `created_by` INT(11) DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_ars_gcred_idem` (`company_id`, `idempotency_key`),
  KEY `idx_ars_gcred_guest` (`company_id`, `guest_id`, `status`),
  KEY `idx_ars_gcred_booking` (`company_id`, `booking_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ars_guest_credit_applications` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `company_id` INT(11) NOT NULL,
  `credit_id` BIGINT UNSIGNED NOT NULL,
  `document_id` BIGINT UNSIGNED NOT NULL,
  `booking_id` INT(11) NOT NULL,
  `amount` DECIMAL(12,2) NOT NULL,
  `journal_id` INT(11) DEFAULT NULL,
  `idempotency_key` VARCHAR(120) DEFAULT NULL,
  `created_by` INT(11) DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_ars_gcred_app_idem` (`company_id`, `idempotency_key`),
  KEY `idx_ars_gcred_app_cred` (`credit_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Stripe settlements (simulation)
CREATE TABLE IF NOT EXISTS `ars_stripe_settlements` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `company_id` INT(11) NOT NULL,
  `settlement_number` VARCHAR(40) NOT NULL,
  `settlement_date` DATE NOT NULL,
  `gross_amount` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `fee_amount` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `net_amount` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `status` ENUM('draft','posted','reversed') NOT NULL DEFAULT 'draft',
  `journal_id` INT(11) DEFAULT NULL,
  `idempotency_key` VARCHAR(120) DEFAULT NULL,
  `notes` VARCHAR(255) DEFAULT NULL,
  `created_by` INT(11) DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `posted_at` DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_ars_stripe_set_num` (`company_id`, `settlement_number`),
  UNIQUE KEY `uq_ars_stripe_set_idem` (`company_id`, `idempotency_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ars_stripe_settlement_lines` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `company_id` INT(11) NOT NULL,
  `settlement_id` BIGINT UNSIGNED NOT NULL,
  `payment_id` INT(11) NOT NULL,
  `amount` DECIMAL(12,2) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_ars_ssl_set` (`settlement_id`),
  UNIQUE KEY `uq_ars_ssl_pay` (`company_id`, `payment_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Service catalog (optional)
CREATE TABLE IF NOT EXISTS `ars_service_catalog` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `company_id` INT(11) NOT NULL,
  `service_code` VARCHAR(40) NOT NULL,
  `name` VARCHAR(120) NOT NULL,
  `default_price` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `account_role` VARCHAR(64) NOT NULL DEFAULT 'ADDITIONAL_SERVICE_REVENUE',
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_ars_svc` (`company_id`, `service_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `ars_service_catalog` (`company_id`, `service_code`, `name`, `default_price`)
SELECT c.id, v.code, v.name, v.price
FROM `companies` c
CROSS JOIN (
  SELECT 'EXTRA_CLEAN' AS code, 'Extra cleaning' AS name, 150.00 AS price
  UNION ALL SELECT 'LINEN', 'Additional linen', 50.00
  UNION ALL SELECT 'EXTRA_BED', 'Extra bed', 100.00
  UNION ALL SELECT 'LATE_CO', 'Late checkout', 75.00
  UNION ALL SELECT 'TRANSFER', 'Airport transfer', 120.00
) v
WHERE c.code = 'ARS' AND c.is_active = 1;

-- Expand document_type if needed (MySQL may need MODIFY)
-- Keep existing ENUM; adjustment/credit/extension/service already in Phase 2B.

-- Seed COA accounts for ARS company if missing
INSERT INTO `re_chart_of_accounts` (
  `company_id`, `account_code`, `account_name`, `account_type`, `parent_id`, `normal_balance`, `is_active`, `created_at`
)
SELECT c.id, v.code, v.name, v.atype, NULL, v.nb, 1, NOW()
FROM `companies` c
CROSS JOIN (
  SELECT '1130' AS code, 'Stripe Clearing' AS name, 'Asset' AS atype, 'debit' AS nb
  UNION ALL SELECT '2210', 'Guest Credit Liability', 'Liability', 'credit'
  UNION ALL SELECT '5510', 'Stripe Processing Fees', 'Expense', 'debit'
) v
WHERE c.code = 'ARS' AND c.is_active = 1
  AND NOT EXISTS (
    SELECT 1 FROM `re_chart_of_accounts` x
    WHERE x.company_id = c.id AND x.account_code = v.code
  );

-- Role map updates / inserts
INSERT INTO `ars_account_role_map` (`company_id`, `role_code`, `account_code`, `notes`)
SELECT c.id, v.role_code, v.account_code, v.notes
FROM `companies` c
CROSS JOIN (
  SELECT 'GUEST_CREDIT' AS role_code, '2210' AS account_code, 'Guest overpayment credit' AS notes
  UNION ALL SELECT 'STRIPE_CLEARING', '1130', 'Stripe clearing asset'
  UNION ALL SELECT 'STRIPE_FEE', '5510', 'Stripe fee expense'
  UNION ALL SELECT 'DAMAGE_REVENUE', '4200', 'Damage recovery income'
  UNION ALL SELECT 'ADDITIONAL_SERVICE_REVENUE', '4200', 'Extra services'
  UNION ALL SELECT 'FORFEIT_REVENUE', '4900', 'Deposit forfeiture income'
  UNION ALL SELECT 'LATE_FEE_REVENUE', '4300', 'Cancellation/late fees'
) v
WHERE c.code = 'ARS' AND c.is_active = 1
ON DUPLICATE KEY UPDATE account_code = VALUES(account_code), notes = VALUES(notes), is_active = 1;

-- Update STRIPE_CLEARING if previously seeded to 1110
UPDATE `ars_account_role_map` m
INNER JOIN `companies` c ON c.id = m.company_id AND c.code = 'ARS'
SET m.account_code = '1130', m.notes = 'Stripe clearing asset'
WHERE m.role_code = 'STRIPE_CLEARING';

SELECT 'ARS Phase 2D gated workflows migration complete.' AS notice;
