-- ===================================================================================
-- Fix installment statuses that are incorrectly set to 'paid' despite having
-- outstanding balances (based on re_payment_allocations actual amounts).
-- Run this on the LIVE server once.
-- ===================================================================================

SET @re_company_id = 2;

-- Step 1: Show BEFORE counts
SELECT 'BEFORE — installments with status=paid but allocation outstanding > 0' AS info,
       COUNT(*) AS count
FROM re_lease_installments li
JOIN re_leases l ON l.id = li.lease_id
WHERE l.company_id = @re_company_id
  AND li.status = 'paid'
  AND li.amount > (
      SELECT COALESCE(SUM(pa.amount_allocated), 0)
      FROM re_payment_allocations pa
      WHERE pa.installment_id = li.id
  );

-- Step 2: Recalculate status for ALL installments in the company
--  • If allocation sum >= amount → 'paid'
--  • If allocation sum > 0 and < amount → 'partial'
--  • If allocation sum = 0 and date < today → 'overdue'
--  • Otherwise → 'pending'
UPDATE re_lease_installments li
JOIN re_leases l ON l.id = li.lease_id
SET li.status = (
    SELECT
        CASE
            WHEN COALESCE(SUM(pa.amount_allocated), 0) >= li.amount THEN 'paid'
            WHEN COALESCE(SUM(pa.amount_allocated), 0) > 0           THEN 'partial'
            WHEN li.installment_date < CURDATE()                     THEN 'overdue'
            ELSE 'pending'
        END
    FROM re_payment_allocations pa
    WHERE pa.installment_id = li.id
)
WHERE l.company_id = @re_company_id
  AND li.status != 'paid';  -- Only fix non-paid ones (partial/overdue/pending recalc)

-- Also fix incorrectly-paid installments (paid in DB but outstanding in allocations)
UPDATE re_lease_installments li
JOIN re_leases l ON l.id = li.lease_id
SET li.status = (
    SELECT
        CASE
            WHEN COALESCE(SUM(pa.amount_allocated), 0) >= li.amount THEN 'paid'
            WHEN COALESCE(SUM(pa.amount_allocated), 0) > 0           THEN 'partial'
            WHEN li.installment_date < CURDATE()                     THEN 'overdue'
            ELSE 'pending'
        END
    FROM re_payment_allocations pa
    WHERE pa.installment_id = li.id
)
WHERE l.company_id = @re_company_id
  AND li.status = 'paid'
  AND li.amount > (
      SELECT COALESCE(SUM(pa2.amount_allocated), 0)
      FROM re_payment_allocations pa2
      WHERE pa2.installment_id = li.id
  );

-- Step 3: Show AFTER counts
SELECT 'AFTER — installments with status=paid but allocation outstanding > 0' AS info,
       COUNT(*) AS count
FROM re_lease_installments li
JOIN re_leases l ON l.id = li.lease_id
WHERE l.company_id = @re_company_id
  AND li.status = 'paid'
  AND li.amount > (
      SELECT COALESCE(SUM(pa.amount_allocated), 0)
      FROM re_payment_allocations pa
      WHERE pa.installment_id = li.id
  );

-- Step 4: Summary of final statuses
SELECT 'Final installment status breakdown' AS info, li.status, COUNT(*) AS count
FROM re_lease_installments li
JOIN re_leases l ON l.id = li.lease_id
WHERE l.company_id = @re_company_id
GROUP BY li.status;
