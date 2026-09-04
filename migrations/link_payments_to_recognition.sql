-- ===================================================================================
-- LINK PAYMENTS TO RECOGNITION SCHEDULE
-- Run this AFTER reset_accounting_layer.sql + migrate_existing_data.php
--
-- Problem: recognition schedule rows were pre-seeded with deferred_payment_id = NULL.
-- When payments were migrated, _populate_recognition_schedule() skipped them because
-- rows already existed. This script sets deferred_payment_id on each recognition row
-- by matching it to the payment that covers that installment.
-- ===================================================================================

SET @re_company_id = 2;

-- Show before count
SELECT 'BEFORE: recognition rows with no payment link' AS info,
       COUNT(*) AS cnt
FROM re_rent_recognition_schedule
WHERE company_id = @re_company_id
  AND status = 'pending'
  AND deferred_payment_id IS NULL;

-- ── Step 1: Link via re_payment_allocations (new-style partial payments) ──────
-- Each allocation record links a payment_id to an installment_id.
-- We pick the EARLIEST payment allocated to each installment.
UPDATE re_rent_recognition_schedule rrs
JOIN (
    SELECT pa.installment_id, MIN(pa.payment_id) AS payment_id
    FROM re_payment_allocations pa
    JOIN re_payments p ON p.id = pa.payment_id
    WHERE p.company_id = @re_company_id
    GROUP BY pa.installment_id
) AS alloc ON alloc.installment_id = rrs.installment_id
SET rrs.deferred_payment_id = alloc.payment_id
WHERE rrs.company_id = @re_company_id
  AND rrs.status = 'pending'
  AND rrs.deferred_payment_id IS NULL;

-- ── Step 2: Link via re_payments.installment_id (direct-link style) ───────────
-- For payments that set installment_id directly (older recording method).
UPDATE re_rent_recognition_schedule rrs
JOIN (
    SELECT p.installment_id, MIN(p.id) AS payment_id
    FROM re_payments p
    WHERE p.company_id = @re_company_id
      AND p.installment_id IS NOT NULL
    GROUP BY p.installment_id
) AS direct ON direct.installment_id = rrs.installment_id
SET rrs.deferred_payment_id = direct.payment_id
WHERE rrs.company_id = @re_company_id
  AND rrs.status = 'pending'
  AND rrs.deferred_payment_id IS NULL;

-- ── Step 3: Link via lease — if a lease has only ONE payment and the
--           recognition rows belong to that lease, link them all to that payment.
--           Handles cases where payments have no installment_id set (e.g. lump-sum).
UPDATE re_rent_recognition_schedule rrs
JOIN (
    SELECT p.lease_id, MIN(p.id) AS payment_id, COUNT(*) AS pay_count
    FROM re_payments p
    WHERE p.company_id = @re_company_id
    GROUP BY p.lease_id
    HAVING COUNT(*) = 1
) AS single ON single.lease_id = rrs.lease_id
SET rrs.deferred_payment_id = single.payment_id
WHERE rrs.company_id = @re_company_id
  AND rrs.status = 'pending'
  AND rrs.deferred_payment_id IS NULL;

-- ── Step 4 (KEY STEP): Link via installment status ────────────────────────────
-- If re_lease_installments.status is 'paid' or 'partial', cash was clearly
-- received for that installment. Link its recognition row to the earliest
-- payment for the same lease.
-- This catches all lump-sum / multi-installment payments that have no
-- direct installment_id or allocation record.
UPDATE re_rent_recognition_schedule rrs
JOIN re_lease_installments li
       ON li.id = rrs.installment_id
      AND li.status IN ('paid', 'partial')
JOIN (
    SELECT p.lease_id, MIN(p.id) AS payment_id
    FROM re_payments p
    WHERE p.company_id = @re_company_id
    GROUP BY p.lease_id
) AS lp ON lp.lease_id = rrs.lease_id
SET rrs.deferred_payment_id = lp.payment_id
WHERE rrs.company_id = @re_company_id
  AND rrs.status = 'pending'
  AND rrs.deferred_payment_id IS NULL;

-- Show after counts
SELECT 'AFTER: recognition rows WITH payment link (ready to recognise)' AS info,
       COUNT(*) AS cnt
FROM re_rent_recognition_schedule
WHERE company_id = @re_company_id
  AND status = 'pending'
  AND deferred_payment_id IS NOT NULL

UNION ALL

SELECT 'AFTER: recognition rows STILL without payment link (genuine no-payment yet)', COUNT(*)
FROM re_rent_recognition_schedule
WHERE company_id = @re_company_id
  AND status = 'pending'
  AND deferred_payment_id IS NULL;

-- ── Breakdown: what status are the remaining unlinked installments? ───────────
SELECT 'Unlinked rows by installment status' AS info,
       COALESCE(li.status, 'NULL') AS installment_status,
       COUNT(*) AS cnt
FROM re_rent_recognition_schedule rrs
JOIN re_lease_installments li ON li.id = rrs.installment_id
WHERE rrs.company_id = @re_company_id
  AND rrs.status = 'pending'
  AND rrs.deferred_payment_id IS NULL
GROUP BY li.status
ORDER BY cnt DESC;
