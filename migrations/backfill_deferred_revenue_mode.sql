-- =============================================================================
-- Migration: Backfill Deferred Revenue Mode for existing leases
-- =============================================================================
-- PURPOSE:
--   All leases in this company use post-dated cheques (PDCs) and must follow
--   accrual accounting. This migration enables deferred_revenue_mode = 1 on
--   all existing leases that do NOT already have posted cash-basis payment
--   journals (to avoid double-counting income).
--
-- SAFE LEASES  : Those with 0 posted payment journals → converted to accrual.
-- SKIPPED LEASE: 222-0001 (lease_id=5) → already has 2 posted cash-basis
--               journals. Marked with a comment for manual review below.
--
-- DUPLICATE SAFETY:
--   The re_rent_recognition_schedule table has UNIQUE KEY uq_installment_recognition
--   (company_id, installment_id). INSERT IGNORE guarantees no duplicates even if
--   this migration is run more than once.
--
-- Run this on: localhost first, then live server.
-- =============================================================================

START TRANSACTION;

-- -----------------------------------------------------------------------------
-- STEP 1: Enable deferred_revenue_mode for leases with NO existing cash-basis
--         payment journals. This excludes lease_id=5 (222-0001) which already
--         has 2 posted journals under cash-basis accounting.
-- -----------------------------------------------------------------------------
UPDATE re_leases l
SET    l.deferred_revenue_mode = 1
WHERE  l.company_id = 2
  AND  l.deferred_revenue_mode != 1          -- skip already-enabled (e.g. lease 7)
  AND  NOT EXISTS (
         SELECT 1
         FROM   re_payments p
         JOIN   re_journal_headers jh
                ON  jh.reference_type = 'payment'
                AND jh.reference_id   = p.id
                AND jh.is_posted      = 1
                AND jh.company_id     = l.company_id
         WHERE  p.lease_id    = l.id
           AND  p.company_id  = l.company_id
       );

-- Verify which leases were updated (for your records):
-- SELECT id, lease_number, deferred_revenue_mode FROM re_leases WHERE company_id = 2 ORDER BY id;

-- -----------------------------------------------------------------------------
-- STEP 2: Seed the recognition schedule for all installments belonging to
--         leases that now have deferred_revenue_mode = 1.
--         INSERT IGNORE prevents duplicates (lease 7 already has 3 rows — safe).
-- -----------------------------------------------------------------------------
INSERT IGNORE INTO re_rent_recognition_schedule
    (company_id, lease_id, installment_id, recognition_date, amount, status)
SELECT
    li.company_id,
    li.lease_id,
    li.id            AS installment_id,
    li.installment_date AS recognition_date,
    li.amount,
    'pending'
FROM  re_lease_installments li
JOIN  re_leases l ON l.id = li.lease_id AND l.company_id = li.company_id
WHERE l.company_id          = 2
  AND l.deferred_revenue_mode = 1
ORDER BY li.lease_id, li.installment_date, li.id;

COMMIT;

-- =============================================================================
-- STEP 3 (MANUAL — read before acting):
--   Lease 222-0001 (lease_id = 5) was SKIPPED because it already has 2 posted
--   payment journals under cash-basis accounting (Dr Bank / Cr Rental Income).
--
--   You have two options for this lease:
--
--   OPTION A — Leave as cash-basis (do nothing):
--     The old journals stay. Future payments will also use cash-basis.
--     The P&L for past periods is already recorded. Simplest choice if you
--     are OK with the historical entries as-is.
--
--   OPTION B — Convert to accrual (reverse old journals + re-enable):
--     Step 1: Run payment_edit.php for each of the 3 payments on this lease.
--             Saving an edit automatically reverses the old journal and
--             reposts it correctly under the selected mode.
--     Step 2: Run the UPDATE below to flip deferred_revenue_mode = 1.
--     Step 3: Re-save the lease from lease_add.php to seed the schedule rows.
--
--   To execute Option B step 2, run this separately AFTER doing step 1:
-- =============================================================================

-- (Uncomment only if you chose OPTION B for lease 222-0001)
-- UPDATE re_leases SET deferred_revenue_mode = 1 WHERE id = 5 AND company_id = 2;
-- INSERT IGNORE INTO re_rent_recognition_schedule (company_id, lease_id, installment_id, recognition_date, amount, status)
-- SELECT li.company_id, li.lease_id, li.id, li.installment_date, li.amount, 'pending'
-- FROM re_lease_installments li WHERE li.lease_id = 5 AND li.company_id = 2
-- ORDER BY li.installment_date, li.id;
