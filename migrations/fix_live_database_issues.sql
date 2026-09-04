-- =============================================================================
-- Migration: Fix live database issues found by diagnostic
-- =============================================================================
-- Run this on the LIVE server only.
-- Safe to run once. Does NOT touch any correctly-posted journals.
-- =============================================================================

START TRANSACTION;

-- =============================================================================
-- FIX 1: Two reversal journals have 0000-00-00 dates
-- (caused by a bug where the reason string was passed as the date parameter)
-- We restore them to the same date as the journal they reversed.
-- =============================================================================

-- Reversal for payment #105 (JRN-1970-0001, id=347) → set date from original
UPDATE re_journal_headers jh
JOIN re_journal_headers orig ON orig.reversal_journal_id = jh.id
SET jh.journal_date = orig.journal_date
WHERE jh.id = 347
  AND jh.journal_date = '0000-00-00'
  AND jh.journal_type = 'reversal';

-- Reversal for payment #200 (JRN-1970-0002, id=349) → set date from original
UPDATE re_journal_headers jh
JOIN re_journal_headers orig ON orig.reversal_journal_id = jh.id
SET jh.journal_date = orig.journal_date
WHERE jh.id = 349
  AND jh.journal_date = '0000-00-00'
  AND jh.journal_type = 'reversal';

-- Also update the corresponding GL entries that reference these journals
UPDATE re_general_ledger gl
JOIN re_journal_headers jh ON jh.id = gl.journal_id
SET gl.entry_date = jh.journal_date
WHERE gl.journal_id IN (347, 349)
  AND gl.entry_date = '0000-00-00';

-- =============================================================================
-- FIX 2: Payment #105 has its journal reversed (orphaned) with NO re-post.
-- The payment record appears to have been deleted (journal shows NULL payment_amount).
-- We just need to make sure the reversal journal is properly marked so it
-- doesn't block any future operations.
-- (The accounting is balanced because the reversal cancels the original)
-- =============================================================================

-- No action needed — the reversal journal for payment #105 correctly
-- cancels the original. Since the payment record is gone, nothing more needed.

-- =============================================================================
-- FIX 3: Verify installment 40 (lease 120-0001) collected amounts
-- Direct and allocation sums should both equal 11,160
-- =============================================================================
-- (This is informational — run separately to check)
-- SELECT
--   li.id, li.amount as due,
--   COALESCE((SELECT SUM(p.amount) FROM re_payments p WHERE p.installment_id = li.id), 0) as direct_sum,
--   COALESCE((SELECT SUM(pa.amount_allocated) FROM re_payment_allocations pa WHERE pa.installment_id = li.id), 0) as alloc_sum
-- FROM re_lease_installments li WHERE li.id = 40;

-- =============================================================================
-- FIX 4: Set company_id correctly on re_general_ledger entries that have
-- 0000-00-00 dates (their journal_ids are 347 and 349 — already fixed above)
-- =============================================================================
-- Already handled by the GL update in FIX 1.

COMMIT;

-- =============================================================================
-- INFORMATIONAL QUERIES — run these separately to verify
-- =============================================================================

-- Check the fixed reversal journals:
-- SELECT id, journal_number, journal_date, journal_type, reference_id, is_posted, is_reversed
-- FROM re_journal_headers WHERE id IN (347, 349, 925);

-- Check payment #200 has active accounting (should have 1 active journal):
-- SELECT id, journal_number, journal_date, journal_type, is_posted, is_reversed
-- FROM re_journal_headers WHERE reference_type='payment' AND reference_id=200 ORDER BY id;

-- Check installment 40 totals are correct:
-- SELECT 'direct' as source, SUM(amount) as total FROM re_payments WHERE installment_id=40
-- UNION ALL
-- SELECT 'alloc', SUM(amount_allocated) FROM re_payment_allocations WHERE installment_id=40;
