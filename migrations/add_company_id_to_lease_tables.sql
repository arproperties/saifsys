-- ============================================================
-- Migration: Add company_id to re_lease_installments,
--            re_lease_cheques, and re_payment_allocations
--
-- These tables were missing company_id, making multi-company
-- isolation rely solely on JOIN through re_leases.
-- This migration adds the column, backfills from the parent
-- table, then enforces NOT NULL.
--
-- SAFE TO RUN: uses IF NOT EXISTS / column existence checks.
-- Run on BOTH localhost and live server.
-- ============================================================

-- ────────────────────────────────────────────────────────────
-- 1. re_lease_installments
-- ────────────────────────────────────────────────────────────

-- Step 1a: Add column (nullable first so backfill can run)
ALTER TABLE `re_lease_installments`
    ADD COLUMN IF NOT EXISTS `company_id` INT(11) NULL DEFAULT NULL
        COMMENT 'FK to companies — copied from re_leases for fast multi-company filtering'
    AFTER `id`;

-- Step 1b: Backfill from parent re_leases
UPDATE `re_lease_installments` li
INNER JOIN `re_leases` l ON l.id = li.lease_id
SET li.company_id = l.company_id
WHERE li.company_id IS NULL;

-- Step 1c: Make NOT NULL now that all rows are filled
ALTER TABLE `re_lease_installments`
    MODIFY COLUMN `company_id` INT(11) NOT NULL
        COMMENT 'FK to companies — copied from re_leases for fast multi-company filtering';

-- Step 1d: Add index for fast company + date queries
ALTER TABLE `re_lease_installments`
    ADD INDEX IF NOT EXISTS `idx_li_company_lease` (`company_id`, `lease_id`);

-- ────────────────────────────────────────────────────────────
-- 2. re_lease_cheques
-- ────────────────────────────────────────────────────────────

ALTER TABLE `re_lease_cheques`
    ADD COLUMN IF NOT EXISTS `company_id` INT(11) NULL DEFAULT NULL
        COMMENT 'FK to companies — copied from re_leases for fast multi-company filtering'
    AFTER `id`;

UPDATE `re_lease_cheques` lc
INNER JOIN `re_leases` l ON l.id = lc.lease_id
SET lc.company_id = l.company_id
WHERE lc.company_id IS NULL;

ALTER TABLE `re_lease_cheques`
    MODIFY COLUMN `company_id` INT(11) NOT NULL
        COMMENT 'FK to companies — copied from re_leases for fast multi-company filtering';

ALTER TABLE `re_lease_cheques`
    ADD INDEX IF NOT EXISTS `idx_lc_company_lease` (`company_id`, `lease_id`);

-- ────────────────────────────────────────────────────────────
-- 3. re_payment_allocations
-- ────────────────────────────────────────────────────────────

ALTER TABLE `re_payment_allocations`
    ADD COLUMN IF NOT EXISTS `company_id` INT(11) NULL DEFAULT NULL
        COMMENT 'FK to companies — copied from re_payments for fast multi-company filtering'
    AFTER `id`;

UPDATE `re_payment_allocations` pa
INNER JOIN `re_payments` p ON p.id = pa.payment_id
SET pa.company_id = p.company_id
WHERE pa.company_id IS NULL;

ALTER TABLE `re_payment_allocations`
    MODIFY COLUMN `company_id` INT(11) NOT NULL
        COMMENT 'FK to companies — copied from re_payments for fast multi-company filtering';

ALTER TABLE `re_payment_allocations`
    ADD INDEX IF NOT EXISTS `idx_pa_company` (`company_id`);

-- ────────────────────────────────────────────────────────────
-- Done. Verify with:
--   SELECT company_id, COUNT(*) FROM re_lease_installments GROUP BY company_id;
--   SELECT company_id, COUNT(*) FROM re_lease_cheques GROUP BY company_id;
--   SELECT company_id, COUNT(*) FROM re_payment_allocations GROUP BY company_id;
-- ────────────────────────────────────────────────────────────
