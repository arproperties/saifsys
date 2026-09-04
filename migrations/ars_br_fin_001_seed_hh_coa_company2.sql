-- =============================================================================
-- BR-ARS-FIN-001 — Seed Holiday Homes COA on Real Estate company (company_id = 2)
-- =============================================================================
-- Purpose:
--   Additive leaf accounts for ARS / Holiday Homes posting under RE books.
--   Does NOT modify existing accounts, journals, or PHP.
--   Does NOT touch company 8 (ARS ops) COA or historical journals.
--
-- Target company (localhost / confirmed):
--   id = 2 · AIN AL REEM PROPERTIES L.L.C · business_type = realestate
--
-- Safe to re-run: each INSERT is guarded by NOT EXISTS on (company_id, account_code).
--
-- BEFORE LIVE:
--   1) Confirm: SELECT id, name, code, business_type FROM companies WHERE id = 2;
--      Must be the Real Estate company that owns banks 1210–1250.
--   2) If live RE company id is NOT 2, stop and adjust @re_company_id below.
--   3) Take a DB backup.
--
-- Related: docs/business-rules/BR-ARS-FIN-001-posting-company-and-coa-picker.md
-- =============================================================================

SET NAMES utf8mb4;
SET @re_company_id := 2;

-- Guard: abort inserts if company 2 is not Real Estate (no rows will match JOIN)
-- Review this result before relying on inserts:
SELECT
  id,
  name,
  code,
  business_type,
  CASE
    WHEN id = @re_company_id AND COALESCE(business_type, '') = 'realestate' THEN 'OK — proceed'
    ELSE 'STOP — company_id 2 is not realestate; do not use this file as-is'
  END AS safety_check
FROM companies
WHERE id = @re_company_id;

-- Parent lookups (ids differ per environment; resolve by account_code)
SET @parent_cash     := (SELECT id FROM re_chart_of_accounts WHERE company_id = @re_company_id AND account_code = '1100' LIMIT 1);
SET @parent_ar       := (SELECT id FROM re_chart_of_accounts WHERE company_id = @re_company_id AND account_code = '1300' LIMIT 1);
SET @parent_liab     := (SELECT id FROM re_chart_of_accounts WHERE company_id = @re_company_id AND account_code = '2000' LIMIT 1);
SET @parent_accrued  := (SELECT id FROM re_chart_of_accounts WHERE company_id = @re_company_id AND account_code = '2400' LIMIT 1);
SET @parent_income   := (SELECT id FROM re_chart_of_accounts WHERE company_id = @re_company_id AND account_code = '4000' LIMIT 1);
SET @parent_opex     := (SELECT id FROM re_chart_of_accounts WHERE company_id = @re_company_id AND account_code = '5000' LIMIT 1);

SELECT
  @re_company_id AS re_company_id,
  @parent_cash AS parent_1100,
  @parent_ar AS parent_1300,
  @parent_liab AS parent_2000,
  @parent_accrued AS parent_2400,
  @parent_income AS parent_4000,
  @parent_opex AS parent_5000;

-- -----------------------------------------------------------------------------
-- Assets
-- -----------------------------------------------------------------------------

-- 1140 HH Stripe Clearing (1130 on co.2 is already a named cash account)
INSERT INTO re_chart_of_accounts
  (company_id, account_code, account_name, account_type, parent_id, normal_balance, is_header, is_active, description, created_at, updated_at)
SELECT c.id, '1140', 'HH Stripe Clearing', 'Asset', @parent_cash, 'debit', 0, 1,
       'Holiday Homes Stripe clearing asset (ARS). Distinct from cash 1130.', NOW(), NOW()
FROM companies c
WHERE c.id = @re_company_id
  AND COALESCE(c.business_type, '') = 'realestate'
  AND @parent_cash IS NOT NULL
  AND NOT EXISTS (
    SELECT 1 FROM re_chart_of_accounts x
    WHERE x.company_id = c.id AND x.account_code = '1140'
  );

-- 1340 AR - Holiday Homes Guests (do not use 1310 Rent Receivable)
INSERT INTO re_chart_of_accounts
  (company_id, account_code, account_name, account_type, parent_id, normal_balance, is_header, is_active, description, created_at, updated_at)
SELECT c.id, '1340', 'AR - Holiday Homes Guests', 'Asset', @parent_ar, 'debit', 0, 1,
       'ARS guest receivables. Separate from lease Rent Receivable 1310.', NOW(), NOW()
FROM companies c
WHERE c.id = @re_company_id
  AND COALESCE(c.business_type, '') = 'realestate'
  AND @parent_ar IS NOT NULL
  AND NOT EXISTS (
    SELECT 1 FROM re_chart_of_accounts x
    WHERE x.company_id = c.id AND x.account_code = '1340'
  );

-- -----------------------------------------------------------------------------
-- Liabilities
-- -----------------------------------------------------------------------------

-- 2210 HH Guest Deposits Held (do not use 2200 lease Security Deposits Payable)
INSERT INTO re_chart_of_accounts
  (company_id, account_code, account_name, account_type, parent_id, normal_balance, is_header, is_active, description, created_at, updated_at)
SELECT c.id, '2210', 'HH Guest Deposits Held', 'Liability', @parent_liab, 'credit', 0, 1,
       'ARS security deposits held for Holiday Homes guests.', NOW(), NOW()
FROM companies c
WHERE c.id = @re_company_id
  AND COALESCE(c.business_type, '') = 'realestate'
  AND @parent_liab IS NOT NULL
  AND NOT EXISTS (
    SELECT 1 FROM re_chart_of_accounts x
    WHERE x.company_id = c.id AND x.account_code = '2210'
  );

-- 2220 HH Guest Credit Liability
INSERT INTO re_chart_of_accounts
  (company_id, account_code, account_name, account_type, parent_id, normal_balance, is_header, is_active, description, created_at, updated_at)
SELECT c.id, '2220', 'HH Guest Credit Liability', 'Liability', @parent_liab, 'credit', 0, 1,
       'ARS guest overpayment / credit balance liability.', NOW(), NOW()
FROM companies c
WHERE c.id = @re_company_id
  AND COALESCE(c.business_type, '') = 'realestate'
  AND @parent_liab IS NOT NULL
  AND NOT EXISTS (
    SELECT 1 FROM re_chart_of_accounts x
    WHERE x.company_id = c.id AND x.account_code = '2220'
  );

-- 2420 HH Unearned / Deferred Revenue (optional until deferred recognition used)
INSERT INTO re_chart_of_accounts
  (company_id, account_code, account_name, account_type, parent_id, normal_balance, is_header, is_active, description, created_at, updated_at)
SELECT c.id, '2420', 'HH Unearned / Deferred Revenue', 'Liability', @parent_accrued, 'credit', 0, 1,
       'Holiday Homes unearned/deferred revenue (ARS). Separate from lease 2410.', NOW(), NOW()
FROM companies c
WHERE c.id = @re_company_id
  AND COALESCE(c.business_type, '') = 'realestate'
  AND @parent_accrued IS NOT NULL
  AND NOT EXISTS (
    SELECT 1 FROM re_chart_of_accounts x
    WHERE x.company_id = c.id AND x.account_code = '2420'
  );

-- -----------------------------------------------------------------------------
-- Income
-- -----------------------------------------------------------------------------

INSERT INTO re_chart_of_accounts
  (company_id, account_code, account_name, account_type, parent_id, normal_balance, is_header, is_active, description, created_at, updated_at)
SELECT c.id, '4130', 'Holiday Homes Room Revenue', 'Income', @parent_income, 'credit', 0, 1,
       'ARS stay / room revenue. Separate from lease rent 4100–4120.', NOW(), NOW()
FROM companies c
WHERE c.id = @re_company_id
  AND COALESCE(c.business_type, '') = 'realestate'
  AND @parent_income IS NOT NULL
  AND NOT EXISTS (
    SELECT 1 FROM re_chart_of_accounts x
    WHERE x.company_id = c.id AND x.account_code = '4130'
  );

INSERT INTO re_chart_of_accounts
  (company_id, account_code, account_name, account_type, parent_id, normal_balance, is_header, is_active, description, created_at, updated_at)
SELECT c.id, '4140', 'Holiday Homes Extra / Damage Revenue', 'Income', @parent_income, 'credit', 0, 1,
       'ARS additional services and damage recovery revenue.', NOW(), NOW()
FROM companies c
WHERE c.id = @re_company_id
  AND COALESCE(c.business_type, '') = 'realestate'
  AND @parent_income IS NOT NULL
  AND NOT EXISTS (
    SELECT 1 FROM re_chart_of_accounts x
    WHERE x.company_id = c.id AND x.account_code = '4140'
  );

INSERT INTO re_chart_of_accounts
  (company_id, account_code, account_name, account_type, parent_id, normal_balance, is_header, is_active, description, created_at, updated_at)
SELECT c.id, '4150', 'Holiday Homes Late Fee Revenue', 'Income', @parent_income, 'credit', 0, 1,
       'ARS late / related fee revenue (when policy enabled).', NOW(), NOW()
FROM companies c
WHERE c.id = @re_company_id
  AND COALESCE(c.business_type, '') = 'realestate'
  AND @parent_income IS NOT NULL
  AND NOT EXISTS (
    SELECT 1 FROM re_chart_of_accounts x
    WHERE x.company_id = c.id AND x.account_code = '4150'
  );

INSERT INTO re_chart_of_accounts
  (company_id, account_code, account_name, account_type, parent_id, normal_balance, is_header, is_active, description, created_at, updated_at)
SELECT c.id, '4410', 'Holiday Homes Forfeit / Other HH Income', 'Income', @parent_income, 'credit', 0, 1,
       'ARS deposit forfeit / other Holiday Homes income (when policy enabled).', NOW(), NOW()
FROM companies c
WHERE c.id = @re_company_id
  AND COALESCE(c.business_type, '') = 'realestate'
  AND @parent_income IS NOT NULL
  AND NOT EXISTS (
    SELECT 1 FROM re_chart_of_accounts x
    WHERE x.company_id = c.id AND x.account_code = '4410'
  );

-- -----------------------------------------------------------------------------
-- Expense
-- -----------------------------------------------------------------------------

INSERT INTO re_chart_of_accounts
  (company_id, account_code, account_name, account_type, parent_id, normal_balance, is_header, is_active, description, created_at, updated_at)
SELECT c.id, '5510', 'Stripe Processing Fees (HH)', 'Expense', @parent_opex, 'debit', 0, 1,
       'Holiday Homes Stripe / card processing fees (ARS).', NOW(), NOW()
FROM companies c
WHERE c.id = @re_company_id
  AND COALESCE(c.business_type, '') = 'realestate'
  AND @parent_opex IS NOT NULL
  AND NOT EXISTS (
    SELECT 1 FROM re_chart_of_accounts x
    WHERE x.company_id = c.id AND x.account_code = '5510'
  );

-- -----------------------------------------------------------------------------
-- Verification
-- -----------------------------------------------------------------------------
SELECT account_code, account_name, account_type, parent_id, is_active, normal_balance
FROM re_chart_of_accounts
WHERE company_id = @re_company_id
  AND account_code IN (
    '1140', '1340', '2210', '2220', '2420',
    '4130', '4140', '4150', '4410', '5510'
  )
ORDER BY account_code;

SELECT 'BR-ARS-FIN-001 COA seed complete (or already present). No journals / PHP changed.' AS notice;
