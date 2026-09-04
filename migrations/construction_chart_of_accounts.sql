-- ============================================================================
-- Construction Module — Chart of Accounts (construction-specific accounts)
-- ============================================================================
-- Run this for each construction company after they have a base COA (e.g. from
-- seed_real_estate_chart_of_accounts.sql or equivalent). Adds accounts:
-- 1515 Construction in Progress, 5125 Project COGS, 2145 Contractor Payable,
-- 2125 Retention Payable.
--
-- Usage: Set @company_id to your construction company ID, then run this script.
-- ============================================================================

SET @company_id = 2;  -- CHANGE to your construction company ID

-- Get parent IDs (must exist from base COA)
SET @parent_asset = (SELECT id FROM re_chart_of_accounts WHERE company_id = @company_id AND account_code = '1000' LIMIT 1);
SET @parent_liability = (SELECT id FROM re_chart_of_accounts WHERE company_id = @company_id AND account_code = '2100' LIMIT 1);
SET @parent_expense = (SELECT id FROM re_chart_of_accounts WHERE company_id = @company_id AND account_code = '5000' LIMIT 1);

-- If 1000/2100/5000 don't exist, try 2000 for liabilities
SET @parent_liability = IFNULL(@parent_liability, (SELECT id FROM re_chart_of_accounts WHERE company_id = @company_id AND account_code = '2000' LIMIT 1));

-- 1515 Construction in Progress (Asset)
INSERT IGNORE INTO re_chart_of_accounts (company_id, account_code, account_name, account_type, parent_id, normal_balance, is_header, is_active, description)
SELECT @company_id, '1515', 'Construction in Progress', 'Asset', @parent_asset, 'debit', 0, 1, 'Construction work in progress (OWNER projects)'
WHERE @parent_asset IS NOT NULL AND NOT EXISTS (SELECT 1 FROM re_chart_of_accounts WHERE company_id = @company_id AND account_code = '1515');

-- 5125 Project COGS (Expense)
INSERT IGNORE INTO re_chart_of_accounts (company_id, account_code, account_name, account_type, parent_id, normal_balance, is_header, is_active, description)
SELECT @company_id, '5125', 'Project Cost / Construction COGS', 'Expense', @parent_expense, 'debit', 0, 1, 'Project costs (CLIENT projects)'
WHERE @parent_expense IS NOT NULL AND NOT EXISTS (SELECT 1 FROM re_chart_of_accounts WHERE company_id = @company_id AND account_code = '5125');

-- 2145 Contractor Payable (Liability)
INSERT IGNORE INTO re_chart_of_accounts (company_id, account_code, account_name, account_type, parent_id, normal_balance, is_header, is_active, description)
SELECT @company_id, '2145', 'Contractor Payable', 'Liability', @parent_liability, 'credit', 0, 1, 'Amounts owed to contractors'
WHERE @parent_liability IS NOT NULL AND NOT EXISTS (SELECT 1 FROM re_chart_of_accounts WHERE company_id = @company_id AND account_code = '2145');

-- 2125 Retention Payable (Liability)
INSERT IGNORE INTO re_chart_of_accounts (company_id, account_code, account_name, account_type, parent_id, normal_balance, is_header, is_active, description)
SELECT @company_id, '2125', 'Retention Payable', 'Liability', @parent_liability, 'credit', 0, 1, 'Contractor retention held'
WHERE @parent_liability IS NOT NULL AND NOT EXISTS (SELECT 1 FROM re_chart_of_accounts WHERE company_id = @company_id AND account_code = '2125');
