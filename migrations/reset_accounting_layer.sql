-- ===================================================================================
-- ACCOUNTING LAYER RESET
-- Safe reset: ONLY removes journal entries, GL postings, and recognition results.
-- PRESERVES: re_payments, re_payment_allocations, re_leases, re_lease_installments,
--            re_tenants, re_post_dated_cheques, re_invoices, re_buildings, re_units,
--            re_chart_of_accounts, re_bank_accounts — i.e. ALL operational data.
--
-- Run this on the live server BEFORE running the migrate_existing_data.php page.
-- After this script, the Trial Balance will be zero — that is expected and correct.
-- ===================================================================================

-- Change this to your Real Estate company_id if different:
SET @re_company_id = 2;

-- ----- Safety check: show counts before deleting --------------------------------
SELECT 'BEFORE RESET — counts' AS info;
SELECT 're_journal_headers' AS tbl, COUNT(*) AS cnt
  FROM re_journal_headers WHERE company_id = @re_company_id
UNION ALL
SELECT 're_journal_lines', COUNT(*)
  FROM re_journal_lines jl
  JOIN re_journal_headers jh ON jh.id = jl.journal_id
  WHERE jh.company_id = @re_company_id
UNION ALL
SELECT 're_account_ledger_entries', COUNT(*)
  FROM re_account_ledger_entries ale
  JOIN re_journal_lines jl ON jl.id = ale.journal_line_id
  JOIN re_journal_headers jh ON jh.id = jl.journal_id
  WHERE jh.company_id = @re_company_id
UNION ALL
SELECT 're_accounting_postings', COUNT(*)
  FROM re_accounting_postings ap
  JOIN re_journal_headers jh ON jh.id = ap.journal_id
  WHERE jh.company_id = @re_company_id
UNION ALL
SELECT 're_general_ledger', COUNT(*)
  FROM re_general_ledger WHERE company_id = @re_company_id
UNION ALL
SELECT 're_rent_recognition_schedule (recognised)', COUNT(*)
  FROM re_rent_recognition_schedule
  WHERE company_id = @re_company_id AND status = 'recognised'
UNION ALL
SELECT 're_payments (KEPT)', COUNT(*)
  FROM re_payments WHERE company_id = @re_company_id
UNION ALL
SELECT 're_lease_installments (KEPT)', COUNT(*)
  FROM re_lease_installments WHERE company_id = @re_company_id
UNION ALL
SELECT 're_leases already on deferred mode', COUNT(*)
  FROM re_leases WHERE company_id = @re_company_id AND deferred_revenue_mode = 1
UNION ALL
SELECT 're_leases on cash-basis (will be switched)', COUNT(*)
  FROM re_leases WHERE company_id = @re_company_id AND (deferred_revenue_mode = 0 OR deferred_revenue_mode IS NULL);

-- Step 0: Set ALL leases to accrual / deferred revenue mode.
--         This ensures that when payments are re-posted by migrate_existing_data.php
--         every payment will be recorded as:
--           Dr. Bank/Cash  →  Cr. Deferred Rent Revenue (2410)
--         and income is only recognised monthly via the Revenue Recognition page.
UPDATE re_leases
SET deferred_revenue_mode = 1
WHERE company_id = @re_company_id;

SELECT CONCAT('Leases set to deferred_revenue_mode = 1: ', ROW_COUNT()) AS step0_result;

-- Step 1: Delete GL entries for this company
DELETE FROM re_general_ledger
WHERE company_id = @re_company_id;

-- Step 2a: Delete re_account_ledger_entries (FK → re_journal_lines.id)
DELETE ale
FROM re_account_ledger_entries ale
JOIN re_journal_lines jl  ON jl.id  = ale.journal_line_id
JOIN re_journal_headers jh ON jh.id = jl.journal_id
WHERE jh.company_id = @re_company_id;

-- Step 2b: Delete re_accounting_postings (FK → re_journal_headers.id)
DELETE ap
FROM re_accounting_postings ap
JOIN re_journal_headers jh ON jh.id = ap.journal_id
WHERE jh.company_id = @re_company_id;

-- Step 2c: Delete journal lines (FK → re_journal_headers.id)
DELETE jl
FROM re_journal_lines jl
JOIN re_journal_headers jh ON jh.id = jl.journal_id
WHERE jh.company_id = @re_company_id;

-- Step 3: Delete journal headers for this company
DELETE FROM re_journal_headers
WHERE company_id = @re_company_id;

-- Step 4: Reset recognition schedule — mark all 'recognised' rows back to 'pending'
--         and clear the journal reference so recognition can run again cleanly.
--         'pending' rows that have no payment linked will show "No payment yet" in the UI
--         — that is correct behaviour until the payment is actually received.
UPDATE re_rent_recognition_schedule
SET status              = 'pending',
    recognised_at       = NULL,
    recognition_journal_id = NULL
WHERE company_id = @re_company_id;

-- ----- Confirm everything was cleared -----------------------------------------
SELECT 'AFTER RESET — counts (all accounting rows should be 0)' AS info;
SELECT 're_journal_headers' AS tbl, COUNT(*) AS cnt
  FROM re_journal_headers WHERE company_id = @re_company_id
UNION ALL
SELECT 're_journal_lines', COUNT(*)
  FROM re_journal_lines jl
  JOIN re_journal_headers jh ON jh.id = jl.journal_id
  WHERE jh.company_id = @re_company_id
UNION ALL
SELECT 're_account_ledger_entries', COUNT(*)
  FROM re_account_ledger_entries ale
  JOIN re_journal_lines jl ON jl.id = ale.journal_line_id
  JOIN re_journal_headers jh ON jh.id = jl.journal_id
  WHERE jh.company_id = @re_company_id
UNION ALL
SELECT 're_accounting_postings', COUNT(*)
  FROM re_accounting_postings ap
  JOIN re_journal_headers jh ON jh.id = ap.journal_id
  WHERE jh.company_id = @re_company_id
UNION ALL
SELECT 're_general_ledger', COUNT(*)
  FROM re_general_ledger WHERE company_id = @re_company_id
UNION ALL
SELECT 're_rent_recognition_schedule (still recognised — must be 0)', COUNT(*)
  FROM re_rent_recognition_schedule
  WHERE company_id = @re_company_id AND status = 'recognised'
UNION ALL
SELECT 're_payments (PRESERVED)', COUNT(*)
  FROM re_payments WHERE company_id = @re_company_id
UNION ALL
SELECT 're_lease_installments (PRESERVED)', COUNT(*)
  FROM re_lease_installments WHERE company_id = @re_company_id
UNION ALL
SELECT 're_leases now on deferred mode (should equal total leases)', COUNT(*)
  FROM re_leases WHERE company_id = @re_company_id AND deferred_revenue_mode = 1;

-- ===================================================================================
-- ALL DONE. Next steps:
--   1. Go to: modules/realestate/accounting/migrate_existing_data.php
--   2. Select Payments + Security Deposits, keep "Skip Already Migrated" checked
--   3. Click "Start Migration"
--      Every payment will now post: Dr. Bank/Cash  →  Cr. Deferred Rent Revenue (2410)
--   4. Go to revenue_recognition.php and click "Run Recognition" to move
--      earned income from 2410 into Rental Income (4110) month by month.
-- ===================================================================================
