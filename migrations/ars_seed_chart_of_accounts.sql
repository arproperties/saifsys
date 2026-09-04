-- ============================================================================
-- ARS Home Rentals — Chart of Accounts Seed  (procedure-free version)
-- Run AFTER ars_phase0_foundation.sql
-- Safe for re-run: uses INSERT ... SELECT with NOT EXISTS guard per row.
-- Works on MySQL 5.7+, MariaDB 10.x, and shared-hosting environments.
-- ============================================================================

SET @cid = (SELECT `id` FROM `companies` WHERE `code` = 'ARS' LIMIT 1);

-- ============================================================================
-- ASSETS (1000-1999)
-- ============================================================================

-- 1000 Current Assets (header)
INSERT INTO `re_chart_of_accounts` (`company_id`,`account_code`,`account_name`,`account_type`,`parent_id`,`normal_balance`,`is_header`,`is_active`,`description`)
SELECT @cid, '1000', 'Current Assets', 'Asset', NULL, 'debit', 1, 1, 'All current assets'
FROM DUAL WHERE @cid IS NOT NULL AND NOT EXISTS (
    SELECT 1 FROM `re_chart_of_accounts` WHERE `company_id` = @cid AND `account_code` = '1000'
);
SET @asset_h = (SELECT `id` FROM `re_chart_of_accounts` WHERE `company_id` = @cid AND `account_code` = '1000' LIMIT 1);

-- 1100 Cash and Cash Equivalents (header)
INSERT INTO `re_chart_of_accounts` (`company_id`,`account_code`,`account_name`,`account_type`,`parent_id`,`normal_balance`,`is_header`,`is_active`,`description`)
SELECT @cid, '1100', 'Cash and Cash Equivalents', 'Asset', @asset_h, 'debit', 1, 1, 'Cash and bank accounts'
FROM DUAL WHERE @cid IS NOT NULL AND NOT EXISTS (
    SELECT 1 FROM `re_chart_of_accounts` WHERE `company_id` = @cid AND `account_code` = '1100'
);
SET @cash_h = (SELECT `id` FROM `re_chart_of_accounts` WHERE `company_id` = @cid AND `account_code` = '1100' LIMIT 1);

-- 1110 Cash on Hand
INSERT INTO `re_chart_of_accounts` (`company_id`,`account_code`,`account_name`,`account_type`,`parent_id`,`normal_balance`,`is_header`,`is_active`,`description`)
SELECT @cid, '1110', 'Cash on Hand', 'Asset', @cash_h, 'debit', 0, 1, 'Physical cash held'
FROM DUAL WHERE @cid IS NOT NULL AND NOT EXISTS (
    SELECT 1 FROM `re_chart_of_accounts` WHERE `company_id` = @cid AND `account_code` = '1110'
);

-- 1120 Petty Cash
INSERT INTO `re_chart_of_accounts` (`company_id`,`account_code`,`account_name`,`account_type`,`parent_id`,`normal_balance`,`is_header`,`is_active`,`description`)
SELECT @cid, '1120', 'Petty Cash', 'Asset', @cash_h, 'debit', 0, 1, 'Petty cash fund'
FROM DUAL WHERE @cid IS NOT NULL AND NOT EXISTS (
    SELECT 1 FROM `re_chart_of_accounts` WHERE `company_id` = @cid AND `account_code` = '1120'
);

-- 1200 Bank Accounts (header)
INSERT INTO `re_chart_of_accounts` (`company_id`,`account_code`,`account_name`,`account_type`,`parent_id`,`normal_balance`,`is_header`,`is_active`,`description`)
SELECT @cid, '1200', 'Bank Accounts', 'Asset', @asset_h, 'debit', 1, 1, 'Bank accounts'
FROM DUAL WHERE @cid IS NOT NULL AND NOT EXISTS (
    SELECT 1 FROM `re_chart_of_accounts` WHERE `company_id` = @cid AND `account_code` = '1200'
);
SET @bank_h = (SELECT `id` FROM `re_chart_of_accounts` WHERE `company_id` = @cid AND `account_code` = '1200' LIMIT 1);

-- 1210 Bank Account - Primary
INSERT INTO `re_chart_of_accounts` (`company_id`,`account_code`,`account_name`,`account_type`,`parent_id`,`normal_balance`,`is_header`,`is_active`,`description`)
SELECT @cid, '1210', 'Bank Account - Primary', 'Asset', @bank_h, 'debit', 0, 1, 'Primary bank account'
FROM DUAL WHERE @cid IS NOT NULL AND NOT EXISTS (
    SELECT 1 FROM `re_chart_of_accounts` WHERE `company_id` = @cid AND `account_code` = '1210'
);

-- 1300 Accounts Receivable (header)
INSERT INTO `re_chart_of_accounts` (`company_id`,`account_code`,`account_name`,`account_type`,`parent_id`,`normal_balance`,`is_header`,`is_active`,`description`)
SELECT @cid, '1300', 'Accounts Receivable', 'Asset', @asset_h, 'debit', 1, 1, 'Amounts owed by guests'
FROM DUAL WHERE @cid IS NOT NULL AND NOT EXISTS (
    SELECT 1 FROM `re_chart_of_accounts` WHERE `company_id` = @cid AND `account_code` = '1300'
);
SET @ar_h = (SELECT `id` FROM `re_chart_of_accounts` WHERE `company_id` = @cid AND `account_code` = '1300' LIMIT 1);

-- 1310 AR - Guests
INSERT INTO `re_chart_of_accounts` (`company_id`,`account_code`,`account_name`,`account_type`,`parent_id`,`normal_balance`,`is_header`,`is_active`,`description`)
SELECT @cid, '1310', 'AR - Guests', 'Asset', @ar_h, 'debit', 0, 1, 'Receivables from short-term rental guests'
FROM DUAL WHERE @cid IS NOT NULL AND NOT EXISTS (
    SELECT 1 FROM `re_chart_of_accounts` WHERE `company_id` = @cid AND `account_code` = '1310'
);

-- 1400 Security Deposits Receivable
INSERT INTO `re_chart_of_accounts` (`company_id`,`account_code`,`account_name`,`account_type`,`parent_id`,`normal_balance`,`is_header`,`is_active`,`description`)
SELECT @cid, '1400', 'Security Deposits Receivable', 'Asset', @asset_h, 'debit', 0, 1, 'Refundable security deposits'
FROM DUAL WHERE @cid IS NOT NULL AND NOT EXISTS (
    SELECT 1 FROM `re_chart_of_accounts` WHERE `company_id` = @cid AND `account_code` = '1400'
);

-- ============================================================================
-- LIABILITIES (2000-2999)
-- ============================================================================

-- 2000 Current Liabilities (header)
INSERT INTO `re_chart_of_accounts` (`company_id`,`account_code`,`account_name`,`account_type`,`parent_id`,`normal_balance`,`is_header`,`is_active`,`description`)
SELECT @cid, '2000', 'Current Liabilities', 'Liability', NULL, 'credit', 1, 1, 'All current liabilities'
FROM DUAL WHERE @cid IS NOT NULL AND NOT EXISTS (
    SELECT 1 FROM `re_chart_of_accounts` WHERE `company_id` = @cid AND `account_code` = '2000'
);
SET @liab_h = (SELECT `id` FROM `re_chart_of_accounts` WHERE `company_id` = @cid AND `account_code` = '2000' LIMIT 1);

-- 2100 Accounts Payable
INSERT INTO `re_chart_of_accounts` (`company_id`,`account_code`,`account_name`,`account_type`,`parent_id`,`normal_balance`,`is_header`,`is_active`,`description`)
SELECT @cid, '2100', 'Accounts Payable', 'Liability', @liab_h, 'credit', 0, 1, 'Amounts owed to vendors'
FROM DUAL WHERE @cid IS NOT NULL AND NOT EXISTS (
    SELECT 1 FROM `re_chart_of_accounts` WHERE `company_id` = @cid AND `account_code` = '2100'
);

-- 2200 Guest Deposits Held
INSERT INTO `re_chart_of_accounts` (`company_id`,`account_code`,`account_name`,`account_type`,`parent_id`,`normal_balance`,`is_header`,`is_active`,`description`)
SELECT @cid, '2200', 'Guest Deposits Held', 'Liability', @liab_h, 'credit', 0, 1, 'Refundable deposits held from guests'
FROM DUAL WHERE @cid IS NOT NULL AND NOT EXISTS (
    SELECT 1 FROM `re_chart_of_accounts` WHERE `company_id` = @cid AND `account_code` = '2200'
);

-- 2300 Tax Liabilities (header)
INSERT INTO `re_chart_of_accounts` (`company_id`,`account_code`,`account_name`,`account_type`,`parent_id`,`normal_balance`,`is_header`,`is_active`,`description`)
SELECT @cid, '2300', 'Tax Liabilities', 'Liability', @liab_h, 'credit', 1, 1, 'Tax liabilities header'
FROM DUAL WHERE @cid IS NOT NULL AND NOT EXISTS (
    SELECT 1 FROM `re_chart_of_accounts` WHERE `company_id` = @cid AND `account_code` = '2300'
);
SET @tax_h = (SELECT `id` FROM `re_chart_of_accounts` WHERE `company_id` = @cid AND `account_code` = '2300' LIMIT 1);

-- 2310 Output VAT
INSERT INTO `re_chart_of_accounts` (`company_id`,`account_code`,`account_name`,`account_type`,`parent_id`,`normal_balance`,`is_header`,`is_active`,`description`)
SELECT @cid, '2310', 'Output VAT', 'Liability', @tax_h, 'credit', 0, 1, 'VAT collected on bookings'
FROM DUAL WHERE @cid IS NOT NULL AND NOT EXISTS (
    SELECT 1 FROM `re_chart_of_accounts` WHERE `company_id` = @cid AND `account_code` = '2310'
);

-- 2320 Input VAT
INSERT INTO `re_chart_of_accounts` (`company_id`,`account_code`,`account_name`,`account_type`,`parent_id`,`normal_balance`,`is_header`,`is_active`,`description`)
SELECT @cid, '2320', 'Input VAT', 'Liability', @tax_h, 'debit', 0, 1, 'VAT paid on purchases'
FROM DUAL WHERE @cid IS NOT NULL AND NOT EXISTS (
    SELECT 1 FROM `re_chart_of_accounts` WHERE `company_id` = @cid AND `account_code` = '2320'
);

-- 2400 Deferred Revenue
INSERT INTO `re_chart_of_accounts` (`company_id`,`account_code`,`account_name`,`account_type`,`parent_id`,`normal_balance`,`is_header`,`is_active`,`description`)
SELECT @cid, '2400', 'Deferred Revenue', 'Liability', @liab_h, 'credit', 0, 1, 'Revenue from advance bookings not yet recognized'
FROM DUAL WHERE @cid IS NOT NULL AND NOT EXISTS (
    SELECT 1 FROM `re_chart_of_accounts` WHERE `company_id` = @cid AND `account_code` = '2400'
);

-- ============================================================================
-- EQUITY (3000-3999)
-- ============================================================================

-- 3000 Equity (header)
INSERT INTO `re_chart_of_accounts` (`company_id`,`account_code`,`account_name`,`account_type`,`parent_id`,`normal_balance`,`is_header`,`is_active`,`description`)
SELECT @cid, '3000', 'Equity', 'Equity', NULL, 'credit', 1, 1, 'Owner equity'
FROM DUAL WHERE @cid IS NOT NULL AND NOT EXISTS (
    SELECT 1 FROM `re_chart_of_accounts` WHERE `company_id` = @cid AND `account_code` = '3000'
);
SET @eq_h = (SELECT `id` FROM `re_chart_of_accounts` WHERE `company_id` = @cid AND `account_code` = '3000' LIMIT 1);

-- 3100 Owner's Capital
INSERT INTO `re_chart_of_accounts` (`company_id`,`account_code`,`account_name`,`account_type`,`parent_id`,`normal_balance`,`is_header`,`is_active`,`description`)
SELECT @cid, '3100', 'Owner''s Capital', 'Equity', @eq_h, 'credit', 0, 1, 'Capital contributed by owner'
FROM DUAL WHERE @cid IS NOT NULL AND NOT EXISTS (
    SELECT 1 FROM `re_chart_of_accounts` WHERE `company_id` = @cid AND `account_code` = '3100'
);

-- 3200 Retained Earnings
INSERT INTO `re_chart_of_accounts` (`company_id`,`account_code`,`account_name`,`account_type`,`parent_id`,`normal_balance`,`is_header`,`is_active`,`description`)
SELECT @cid, '3200', 'Retained Earnings', 'Equity', @eq_h, 'credit', 0, 1, 'Accumulated profits'
FROM DUAL WHERE @cid IS NOT NULL AND NOT EXISTS (
    SELECT 1 FROM `re_chart_of_accounts` WHERE `company_id` = @cid AND `account_code` = '3200'
);

-- ============================================================================
-- REVENUE (4000-4999)
-- ============================================================================

-- 4000 Revenue (header)
INSERT INTO `re_chart_of_accounts` (`company_id`,`account_code`,`account_name`,`account_type`,`parent_id`,`normal_balance`,`is_header`,`is_active`,`description`)
SELECT @cid, '4000', 'Revenue', 'Income', NULL, 'credit', 1, 1, 'All revenue accounts'
FROM DUAL WHERE @cid IS NOT NULL AND NOT EXISTS (
    SELECT 1 FROM `re_chart_of_accounts` WHERE `company_id` = @cid AND `account_code` = '4000'
);
SET @rev_h = (SELECT `id` FROM `re_chart_of_accounts` WHERE `company_id` = @cid AND `account_code` = '4000' LIMIT 1);

-- 4100 Room Revenue
INSERT INTO `re_chart_of_accounts` (`company_id`,`account_code`,`account_name`,`account_type`,`parent_id`,`normal_balance`,`is_header`,`is_active`,`description`)
SELECT @cid, '4100', 'Room Revenue', 'Income', @rev_h, 'credit', 0, 1, 'Nightly room/unit rental income'
FROM DUAL WHERE @cid IS NOT NULL AND NOT EXISTS (
    SELECT 1 FROM `re_chart_of_accounts` WHERE `company_id` = @cid AND `account_code` = '4100'
);

-- 4200 Extra Charges Revenue
INSERT INTO `re_chart_of_accounts` (`company_id`,`account_code`,`account_name`,`account_type`,`parent_id`,`normal_balance`,`is_header`,`is_active`,`description`)
SELECT @cid, '4200', 'Extra Charges Revenue', 'Income', @rev_h, 'credit', 0, 1, 'Revenue from extra services and charges'
FROM DUAL WHERE @cid IS NOT NULL AND NOT EXISTS (
    SELECT 1 FROM `re_chart_of_accounts` WHERE `company_id` = @cid AND `account_code` = '4200'
);

-- 4300 Late Fee Revenue
INSERT INTO `re_chart_of_accounts` (`company_id`,`account_code`,`account_name`,`account_type`,`parent_id`,`normal_balance`,`is_header`,`is_active`,`description`)
SELECT @cid, '4300', 'Late Fee Revenue', 'Income', @rev_h, 'credit', 0, 1, 'Late payment penalties'
FROM DUAL WHERE @cid IS NOT NULL AND NOT EXISTS (
    SELECT 1 FROM `re_chart_of_accounts` WHERE `company_id` = @cid AND `account_code` = '4300'
);

-- 4900 Other Revenue
INSERT INTO `re_chart_of_accounts` (`company_id`,`account_code`,`account_name`,`account_type`,`parent_id`,`normal_balance`,`is_header`,`is_active`,`description`)
SELECT @cid, '4900', 'Other Revenue', 'Income', @rev_h, 'credit', 0, 1, 'Miscellaneous revenue'
FROM DUAL WHERE @cid IS NOT NULL AND NOT EXISTS (
    SELECT 1 FROM `re_chart_of_accounts` WHERE `company_id` = @cid AND `account_code` = '4900'
);

-- ============================================================================
-- EXPENSES (5000-5999)
-- ============================================================================

-- 5000 Operating Expenses (header)
INSERT INTO `re_chart_of_accounts` (`company_id`,`account_code`,`account_name`,`account_type`,`parent_id`,`normal_balance`,`is_header`,`is_active`,`description`)
SELECT @cid, '5000', 'Operating Expenses', 'Expense', NULL, 'debit', 1, 1, 'All operating expenses'
FROM DUAL WHERE @cid IS NOT NULL AND NOT EXISTS (
    SELECT 1 FROM `re_chart_of_accounts` WHERE `company_id` = @cid AND `account_code` = '5000'
);
SET @exp_h = (SELECT `id` FROM `re_chart_of_accounts` WHERE `company_id` = @cid AND `account_code` = '5000' LIMIT 1);

-- 5100 Cleaning Expense
INSERT INTO `re_chart_of_accounts` (`company_id`,`account_code`,`account_name`,`account_type`,`parent_id`,`normal_balance`,`is_header`,`is_active`,`description`)
SELECT @cid, '5100', 'Cleaning Expense', 'Expense', @exp_h, 'debit', 0, 1, 'Turnover and regular cleaning costs'
FROM DUAL WHERE @cid IS NOT NULL AND NOT EXISTS (
    SELECT 1 FROM `re_chart_of_accounts` WHERE `company_id` = @cid AND `account_code` = '5100'
);

-- 5200 Maintenance Expense
INSERT INTO `re_chart_of_accounts` (`company_id`,`account_code`,`account_name`,`account_type`,`parent_id`,`normal_balance`,`is_header`,`is_active`,`description`)
SELECT @cid, '5200', 'Maintenance Expense', 'Expense', @exp_h, 'debit', 0, 1, 'Unit maintenance and repairs'
FROM DUAL WHERE @cid IS NOT NULL AND NOT EXISTS (
    SELECT 1 FROM `re_chart_of_accounts` WHERE `company_id` = @cid AND `account_code` = '5200'
);

-- 5300 Supplies Expense
INSERT INTO `re_chart_of_accounts` (`company_id`,`account_code`,`account_name`,`account_type`,`parent_id`,`normal_balance`,`is_header`,`is_active`,`description`)
SELECT @cid, '5300', 'Supplies Expense', 'Expense', @exp_h, 'debit', 0, 1, 'Linens, toiletries, and consumables'
FROM DUAL WHERE @cid IS NOT NULL AND NOT EXISTS (
    SELECT 1 FROM `re_chart_of_accounts` WHERE `company_id` = @cid AND `account_code` = '5300'
);

-- 5400 Utilities Expense
INSERT INTO `re_chart_of_accounts` (`company_id`,`account_code`,`account_name`,`account_type`,`parent_id`,`normal_balance`,`is_header`,`is_active`,`description`)
SELECT @cid, '5400', 'Utilities Expense', 'Expense', @exp_h, 'debit', 0, 1, 'Water, electricity, internet costs'
FROM DUAL WHERE @cid IS NOT NULL AND NOT EXISTS (
    SELECT 1 FROM `re_chart_of_accounts` WHERE `company_id` = @cid AND `account_code` = '5400'
);

-- 5500 Commission Expense
INSERT INTO `re_chart_of_accounts` (`company_id`,`account_code`,`account_name`,`account_type`,`parent_id`,`normal_balance`,`is_header`,`is_active`,`description`)
SELECT @cid, '5500', 'Commission Expense', 'Expense', @exp_h, 'debit', 0, 1, 'Platform commissions (OTA, Airbnb, etc.)'
FROM DUAL WHERE @cid IS NOT NULL AND NOT EXISTS (
    SELECT 1 FROM `re_chart_of_accounts` WHERE `company_id` = @cid AND `account_code` = '5500'
);

-- 5600 Insurance Expense
INSERT INTO `re_chart_of_accounts` (`company_id`,`account_code`,`account_name`,`account_type`,`parent_id`,`normal_balance`,`is_header`,`is_active`,`description`)
SELECT @cid, '5600', 'Insurance Expense', 'Expense', @exp_h, 'debit', 0, 1, 'Property and liability insurance'
FROM DUAL WHERE @cid IS NOT NULL AND NOT EXISTS (
    SELECT 1 FROM `re_chart_of_accounts` WHERE `company_id` = @cid AND `account_code` = '5600'
);

-- 5900 Other Operating Expenses
INSERT INTO `re_chart_of_accounts` (`company_id`,`account_code`,`account_name`,`account_type`,`parent_id`,`normal_balance`,`is_header`,`is_active`,`description`)
SELECT @cid, '5900', 'Other Operating Expenses', 'Expense', @exp_h, 'debit', 0, 1, 'Miscellaneous operating expenses'
FROM DUAL WHERE @cid IS NOT NULL AND NOT EXISTS (
    SELECT 1 FROM `re_chart_of_accounts` WHERE `company_id` = @cid AND `account_code` = '5900'
);

-- Done
SELECT 'ARS chart of accounts seed complete.' AS notice;
