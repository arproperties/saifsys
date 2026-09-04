-- ============================================================
-- Migration: Deferred Revenue (Accrual Accounting) System
-- Run this on BOTH localhost and live server.
-- Safe to run multiple times (uses IF NOT EXISTS / IF EXISTS).
-- ============================================================

-- ────────────────────────────────────────────────────────────
-- 1. Add deferred_revenue_mode column to re_leases
--    Controls whether payments for this lease go to
--    Deferred Rent Revenue (2410) instead of directly to AR.
-- ────────────────────────────────────────────────────────────
ALTER TABLE `re_leases`
    ADD COLUMN IF NOT EXISTS `deferred_revenue_mode` TINYINT(1) NOT NULL DEFAULT 0
        COMMENT '1 = payments go to Deferred Rent Revenue (accrual); 0 = standard AR posting';

-- ────────────────────────────────────────────────────────────
-- 2. Add income_account_id to re_invoices (explicit, no keyword guessing)
-- ────────────────────────────────────────────────────────────
ALTER TABLE `re_invoices`
    ADD COLUMN IF NOT EXISTS `income_account_id` INT(11) NULL DEFAULT NULL
        COMMENT 'FK to re_chart_of_accounts — explicit income account for this invoice';

-- ────────────────────────────────────────────────────────────
-- 3. Add income_account_id to re_invoice_items (per-line override)
-- ────────────────────────────────────────────────────────────
ALTER TABLE `re_invoice_items`
    ADD COLUMN IF NOT EXISTS `income_account_id` INT(11) NULL DEFAULT NULL
        COMMENT 'FK to re_chart_of_accounts — overrides parent invoice income account for this line';

-- ────────────────────────────────────────────────────────────
-- 4. Create re_rent_recognition_schedule
--    Tracks which installments have had revenue recognised
--    and which journals were posted for deferred → income.
-- ────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `re_rent_recognition_schedule` (
    `id`                    INT(11)        NOT NULL AUTO_INCREMENT,
    `company_id`            INT(11)        NOT NULL,
    `lease_id`              INT(11)        NOT NULL,
    `installment_id`        INT(11)        NOT NULL,
    `recognition_date`      DATE           NOT NULL
        COMMENT 'Date income should be / was recognised (usually = installment_date)',
    `amount`                DECIMAL(15,2)  NOT NULL DEFAULT 0.00
        COMMENT 'Amount to recognise as income for this period',
    `status`                ENUM('pending','recognised','skipped') NOT NULL DEFAULT 'pending'
        COMMENT 'pending = not yet processed; recognised = journal posted; skipped = manually excluded',
    `recognition_journal_id` INT(11)       NULL DEFAULT NULL
        COMMENT 'FK to re_journal_headers — the journal that recognised this revenue',
    `deferred_payment_id`   INT(11)        NULL DEFAULT NULL
        COMMENT 'FK to re_payments — the payment that funded this deferred balance',
    `notes`                 TEXT           NULL,
    `created_at`            DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `recognised_at`         DATETIME       NULL,
    `recognised_by`         INT(11)        NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_installment_recognition` (`company_id`, `installment_id`),
    KEY `idx_recognition_lease` (`company_id`, `lease_id`),
    KEY `idx_recognition_date` (`company_id`, `recognition_date`, `status`),
    KEY `idx_recognition_journal` (`recognition_journal_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Tracks deferred rent revenue recognition per installment';

-- ────────────────────────────────────────────────────────────
-- 5. Ensure account 2410 (Deferred Rent Revenue) exists in COA.
--    Only inserts if not already present; safe on re-run.
-- ────────────────────────────────────────────────────────────
INSERT IGNORE INTO `re_chart_of_accounts`
    (`company_id`, `account_code`, `account_name`, `account_type`, `normal_balance`,
     `is_header`, `is_active`, `description`)
SELECT
    c.id,
    '2410',
    'Deferred Rent Revenue',
    'Liability',
    'credit',
    0,
    1,
    'Rent received in advance — recognised monthly as income'
FROM (SELECT DISTINCT company_id AS id FROM re_chart_of_accounts) c
WHERE NOT EXISTS (
    SELECT 1 FROM re_chart_of_accounts x
    WHERE x.company_id = c.id AND x.account_code = '2410'
);

-- ────────────────────────────────────────────────────────────
-- Done.
-- ────────────────────────────────────────────────────────────
