-- ===================================================================================
-- TRIAL BALANCE DIAGNOSTIC
-- Run this on the LIVE server to identify why the Trial Balance is out of balance.
-- ===================================================================================

SET @re_company_id = 2;

-- ── Query 1: Which accounts are the GL entries actually going to? ──────────────
-- This shows every account touched by journals for company 2, including
-- the company_id of that account (should always = 2).
SELECT 'GL entries by account' AS query,
       coa.account_code,
       coa.account_name,
       coa.company_id AS coa_company_id,
       coa.account_type,
       COUNT(gl.id)          AS entry_count,
       SUM(gl.debit_amount)  AS total_debit,
       SUM(gl.credit_amount) AS total_credit
FROM re_general_ledger gl
JOIN re_chart_of_accounts coa ON coa.id = gl.account_id
WHERE gl.company_id = @re_company_id
GROUP BY coa.id
ORDER BY coa.company_id, coa.account_code;

-- ── Query 2: Are any GL entries pointing to accounts from WRONG companies? ──────
SELECT 'PROBLEM: GL entries going to wrong-company accounts' AS query,
       gl.account_id,
       coa.account_code,
       coa.account_name,
       coa.company_id AS coa_company_id,
       COUNT(*)            AS entry_count,
       SUM(gl.debit_amount)  AS total_debit,
       SUM(gl.credit_amount) AS total_credit
FROM re_general_ledger gl
JOIN re_chart_of_accounts coa ON coa.id = gl.account_id
WHERE gl.company_id = @re_company_id
  AND coa.company_id != @re_company_id
GROUP BY gl.account_id, coa.account_code, coa.account_name, coa.company_id;

-- ── Query 3: What bank/cash accounts exist in company 2's COA? ──────────────────
SELECT 'Company 2 bank/cash accounts in COA' AS query,
       account_code, account_name, is_active
FROM re_chart_of_accounts
WHERE company_id = @re_company_id
  AND account_type = 'Asset'
  AND account_code LIKE '1%'
ORDER BY account_code;

-- ── Query 4: Total debits vs credits in the GL for company 2 ────────────────────
SELECT 'GL totals for company 2' AS query,
       SUM(debit_amount)  AS total_debit,
       SUM(credit_amount) AS total_credit,
       SUM(debit_amount) - SUM(credit_amount) AS difference
FROM re_general_ledger
WHERE company_id = @re_company_id;
