-- ============================================================================
-- REAL ESTATE ACCOUNTING - Default Chart of Accounts
-- ============================================================================
-- This migration seeds the default Chart of Accounts for Real Estate companies
-- Accounts are organized hierarchically with parent-child relationships
-- All accounts are company-specific (company_id = 1 for default)
-- ============================================================================

-- Note: This script should be run AFTER create_real_estate_accounting_tables.sql
-- It creates default accounts for company_id = 1
-- For other companies, run this script with appropriate company_id

SET @company_id = 3; -- Default company ID, change as needed

-- ============================================================================
-- ASSETS (1000-1999)
-- ============================================================================

-- 1000 - Current Assets (Header)
INSERT INTO `re_chart_of_accounts` (`company_id`, `account_code`, `account_name`, `account_type`, `parent_id`, `normal_balance`, `is_header`, `is_active`, `description`)
VALUES (@company_id, '1000', 'Current Assets', 'Asset', NULL, 'debit', 1, 1, 'All current assets');

SET @asset_header_id = LAST_INSERT_ID();

-- 1100 - Cash and Cash Equivalents (Header)
INSERT INTO `re_chart_of_accounts` (`company_id`, `account_code`, `account_name`, `account_type`, `parent_id`, `normal_balance`, `is_header`, `is_active`, `description`)
VALUES (@company_id, '1100', 'Cash and Cash Equivalents', 'Asset', @asset_header_id, 'debit', 1, 1, 'Cash and cash equivalent accounts');

SET @cash_header_id = LAST_INSERT_ID();

-- 1110 - Cash on Hand
INSERT INTO `re_chart_of_accounts` (`company_id`, `account_code`, `account_name`, `account_type`, `parent_id`, `normal_balance`, `is_header`, `is_active`, `description`)
VALUES (@company_id, '1110', 'Cash on Hand', 'Asset', @cash_header_id, 'debit', 0, 1, 'Physical cash held in office');

-- 1120 - Petty Cash
INSERT INTO `re_chart_of_accounts` (`company_id`, `account_code`, `account_name`, `account_type`, `parent_id`, `normal_balance`, `is_header`, `is_active`, `description`)
VALUES (@company_id, '1120', 'Petty Cash', 'Asset', @cash_header_id, 'debit', 0, 1, 'Petty cash fund for small expenses');

-- 1200 - Bank Accounts (Header)
INSERT INTO `re_chart_of_accounts` (`company_id`, `account_code`, `account_name`, `account_type`, `parent_id`, `normal_balance`, `is_header`, `is_active`, `description`)
VALUES (@company_id, '1200', 'Bank Accounts', 'Asset', @asset_header_id, 'debit', 1, 1, 'All bank accounts');

SET @bank_header_id = LAST_INSERT_ID();

-- 1210 - Bank Account - ADCB
INSERT INTO `re_chart_of_accounts` (`company_id`, `account_code`, `account_name`, `account_type`, `parent_id`, `normal_balance`, `is_header`, `is_active`, `description`)
VALUES (@company_id, '1210', 'Bank Account - ADCB', 'Asset', @bank_header_id, 'debit', 0, 1, 'Abu Dhabi Commercial Bank account');

-- 1220 - Bank Account - Emirates NBD
INSERT INTO `re_chart_of_accounts` (`company_id`, `account_code`, `account_name`, `account_type`, `parent_id`, `normal_balance`, `is_header`, `is_active`, `description`)
VALUES (@company_id, '1220', 'Bank Account - Emirates NBD', 'Asset', @bank_header_id, 'debit', 0, 1, 'Emirates NBD bank account');

-- 1230 - Bank Account - Other
INSERT INTO `re_chart_of_accounts` (`company_id`, `account_code`, `account_name`, `account_type`, `parent_id`, `normal_balance`, `is_header`, `is_active`, `description`)
VALUES (@company_id, '1230', 'Bank Account - Other', 'Asset', @bank_header_id, 'debit', 0, 1, 'Other bank accounts');

-- 1300 - Accounts Receivable (Header)
INSERT INTO `re_chart_of_accounts` (`company_id`, `account_code`, `account_name`, `account_type`, `parent_id`, `normal_balance`, `is_header`, `is_active`, `description`)
VALUES (@company_id, '1300', 'Accounts Receivable', 'Asset', @asset_header_id, 'debit', 1, 1, 'Amounts owed by tenants and others');

SET @ar_header_id = LAST_INSERT_ID();

-- 1310 - Rent Receivable
INSERT INTO `re_chart_of_accounts` (`company_id`, `account_code`, `account_name`, `account_type`, `parent_id`, `normal_balance`, `is_header`, `is_active`, `description`)
VALUES (@company_id, '1310', 'Rent Receivable', 'Asset', @ar_header_id, 'debit', 0, 1, 'Outstanding rent from tenants');

-- 1320 - Service Charges Receivable
INSERT INTO `re_chart_of_accounts` (`company_id`, `account_code`, `account_name`, `account_type`, `parent_id`, `normal_balance`, `is_header`, `is_active`, `description`)
VALUES (@company_id, '1320', 'Service Charges Receivable', 'Asset', @ar_header_id, 'debit', 0, 1, 'Outstanding service charges');

-- 1330 - Penalties Receivable
INSERT INTO `re_chart_of_accounts` (`company_id`, `account_code`, `account_name`, `account_type`, `parent_id`, `normal_balance`, `is_header`, `is_active`, `description`)
VALUES (@company_id, '1330', 'Penalties Receivable', 'Asset', @ar_header_id, 'debit', 0, 1, 'Outstanding penalties and late fees');

-- 1400 - Security Deposits Receivable
INSERT INTO `re_chart_of_accounts` (`company_id`, `account_code`, `account_name`, `account_type`, `parent_id`, `normal_balance`, `is_header`, `is_active`, `description`)
VALUES (@company_id, '1400', 'Security Deposits Receivable', 'Asset', @asset_header_id, 'debit', 0, 1, 'Security deposits collected from tenants');

-- 1500 - Prepaid Expenses (Header)
INSERT INTO `re_chart_of_accounts` (`company_id`, `account_code`, `account_name`, `account_type`, `parent_id`, `normal_balance`, `is_header`, `is_active`, `description`)
VALUES (@company_id, '1500', 'Prepaid Expenses', 'Asset', @asset_header_id, 'debit', 1, 1, 'Prepaid expenses and advance payments');

SET @prepaid_header_id = LAST_INSERT_ID();

-- 1510 - Prepaid Rent
INSERT INTO `re_chart_of_accounts` (`company_id`, `account_code`, `account_name`, `account_type`, `parent_id`, `normal_balance`, `is_header`, `is_active`, `description`)
VALUES (@company_id, '1510', 'Prepaid Rent', 'Asset', @prepaid_header_id, 'debit', 0, 1, 'Rent paid in advance');

-- 1520 - Prepaid Insurance
INSERT INTO `re_chart_of_accounts` (`company_id`, `account_code`, `account_name`, `account_type`, `parent_id`, `normal_balance`, `is_header`, `is_active`, `description`)
VALUES (@company_id, '1520', 'Prepaid Insurance', 'Asset', @prepaid_header_id, 'debit', 0, 1, 'Insurance premiums paid in advance');

-- ============================================================================
-- LIABILITIES (2000-2999)
-- ============================================================================

-- 2000 - Current Liabilities (Header)
INSERT INTO `re_chart_of_accounts` (`company_id`, `account_code`, `account_name`, `account_type`, `parent_id`, `normal_balance`, `is_header`, `is_active`, `description`)
VALUES (@company_id, '2000', 'Current Liabilities', 'Liability', NULL, 'credit', 1, 1, 'All current liabilities');

SET @liability_header_id = LAST_INSERT_ID();

-- 2100 - Accounts Payable (Header)
INSERT INTO `re_chart_of_accounts` (`company_id`, `account_code`, `account_name`, `account_type`, `parent_id`, `normal_balance`, `is_header`, `is_active`, `description`)
VALUES (@company_id, '2100', 'Accounts Payable', 'Liability', @liability_header_id, 'credit', 1, 1, 'Amounts owed to vendors and suppliers');

SET @ap_header_id = LAST_INSERT_ID();

-- 2110 - Maintenance Payable
INSERT INTO `re_chart_of_accounts` (`company_id`, `account_code`, `account_name`, `account_type`, `parent_id`, `normal_balance`, `is_header`, `is_active`, `description`)
VALUES (@company_id, '2110', 'Maintenance Payable', 'Liability', @ap_header_id, 'credit', 0, 1, 'Outstanding maintenance bills');

-- 2120 - Utilities Payable
INSERT INTO `re_chart_of_accounts` (`company_id`, `account_code`, `account_name`, `account_type`, `parent_id`, `normal_balance`, `is_header`, `is_active`, `description`)
VALUES (@company_id, '2120', 'Utilities Payable', 'Liability', @ap_header_id, 'credit', 0, 1, 'Outstanding utility bills');

-- 2130 - Vendor Payable
INSERT INTO `re_chart_of_accounts` (`company_id`, `account_code`, `account_name`, `account_type`, `parent_id`, `normal_balance`, `is_header`, `is_active`, `description`)
VALUES (@company_id, '2130', 'Vendor Payable', 'Liability', @ap_header_id, 'credit', 0, 1, 'Outstanding vendor invoices');

-- 2200 - Security Deposits Payable
INSERT INTO `re_chart_of_accounts` (`company_id`, `account_code`, `account_name`, `account_type`, `parent_id`, `normal_balance`, `is_header`, `is_active`, `description`)
VALUES (@company_id, '2200', 'Security Deposits Payable', 'Liability', @liability_header_id, 'credit', 0, 1, 'Security deposits held for tenants (refundable)');

-- 2300 - VAT Payable (Header)
INSERT INTO `re_chart_of_accounts` (`company_id`, `account_code`, `account_name`, `account_type`, `parent_id`, `normal_balance`, `is_header`, `is_active`, `description`)
VALUES (@company_id, '2300', 'VAT Payable', 'Liability', @liability_header_id, 'credit', 1, 1, 'VAT related accounts');

SET @vat_header_id = LAST_INSERT_ID();

-- 2310 - Output VAT
INSERT INTO `re_chart_of_accounts` (`company_id`, `account_code`, `account_name`, `account_type`, `parent_id`, `normal_balance`, `is_header`, `is_active`, `description`)
VALUES (@company_id, '2310', 'Output VAT', 'Liability', @vat_header_id, 'credit', 0, 1, 'VAT collected on sales (output VAT)');

-- 2320 - Input VAT (Recoverable)
INSERT INTO `re_chart_of_accounts` (`company_id`, `account_code`, `account_name`, `account_type`, `parent_id`, `normal_balance`, `is_header`, `is_active`, `description`)
VALUES (@company_id, '2320', 'Input VAT (Recoverable)', 'Liability', @vat_header_id, 'credit', 0, 1, 'VAT paid on purchases (input VAT - recoverable)');

-- 2400 - Accrued Expenses (Header)
INSERT INTO `re_chart_of_accounts` (`company_id`, `account_code`, `account_name`, `account_type`, `parent_id`, `normal_balance`, `is_header`, `is_active`, `description`)
VALUES (@company_id, '2400', 'Accrued Expenses', 'Liability', @liability_header_id, 'credit', 1, 1, 'Accrued expenses and liabilities');

SET @accrued_header_id = LAST_INSERT_ID();

-- 2410 - Accrued Rent Income
INSERT INTO `re_chart_of_accounts` (`company_id`, `account_code`, `account_name`, `account_type`, `parent_id`, `normal_balance`, `is_header`, `is_active`, `description`)
VALUES (@company_id, '2410', 'Accrued Rent Income', 'Liability', @accrued_header_id, 'credit', 0, 1, 'Rent income earned but not yet received');

-- ============================================================================
-- EQUITY (3000-3999)
-- ============================================================================

-- 3000 - Equity (Header)
INSERT INTO `re_chart_of_accounts` (`company_id`, `account_code`, `account_name`, `account_type`, `parent_id`, `normal_balance`, `is_header`, `is_active`, `description`)
VALUES (@company_id, '3000', 'Equity', 'Equity', NULL, 'credit', 1, 1, 'Owner equity and retained earnings');

SET @equity_header_id = LAST_INSERT_ID();

-- 3100 - Capital
INSERT INTO `re_chart_of_accounts` (`company_id`, `account_code`, `account_name`, `account_type`, `parent_id`, `normal_balance`, `is_header`, `is_active`, `description`)
VALUES (@company_id, '3100', 'Capital', 'Equity', @equity_header_id, 'credit', 0, 1, 'Owner capital investment');

-- 3200 - Retained Earnings
INSERT INTO `re_chart_of_accounts` (`company_id`, `account_code`, `account_name`, `account_type`, `parent_id`, `normal_balance`, `is_header`, `is_active`, `description`)
VALUES (@company_id, '3200', 'Retained Earnings', 'Equity', @equity_header_id, 'credit', 0, 1, 'Accumulated retained earnings from previous periods');

-- 3300 - Current Year Earnings
INSERT INTO `re_chart_of_accounts` (`company_id`, `account_code`, `account_name`, `account_type`, `parent_id`, `normal_balance`, `is_header`, `is_active`, `description`)
VALUES (@company_id, '3300', 'Current Year Earnings', 'Equity', @equity_header_id, 'credit', 0, 1, 'Current year profit/loss (temporary account)');

-- ============================================================================
-- INCOME (4000-4999)
-- ============================================================================

-- 4000 - Operating Income (Header)
INSERT INTO `re_chart_of_accounts` (`company_id`, `account_code`, `account_name`, `account_type`, `parent_id`, `normal_balance`, `is_header`, `is_active`, `description`)
VALUES (@company_id, '4000', 'Operating Income', 'Income', NULL, 'credit', 1, 1, 'All operating income');

SET @income_header_id = LAST_INSERT_ID();

-- 4100 - Rent Income (Header)
INSERT INTO `re_chart_of_accounts` (`company_id`, `account_code`, `account_name`, `account_type`, `parent_id`, `normal_balance`, `is_header`, `is_active`, `description`)
VALUES (@company_id, '4100', 'Rent Income', 'Income', @income_header_id, 'credit', 1, 1, 'Rental income from properties');

SET @rent_header_id = LAST_INSERT_ID();

-- 4110 - Residential Rent Income
INSERT INTO `re_chart_of_accounts` (`company_id`, `account_code`, `account_name`, `account_type`, `parent_id`, `normal_balance`, `is_header`, `is_active`, `description`)
VALUES (@company_id, '4110', 'Residential Rent Income', 'Income', @rent_header_id, 'credit', 0, 1, 'Rent income from residential units');

-- 4120 - Commercial Rent Income
INSERT INTO `re_chart_of_accounts` (`company_id`, `account_code`, `account_name`, `account_type`, `parent_id`, `normal_balance`, `is_header`, `is_active`, `description`)
VALUES (@company_id, '4120', 'Commercial Rent Income', 'Income', @rent_header_id, 'credit', 0, 1, 'Rent income from commercial units');

-- 4200 - Service Charge Income
INSERT INTO `re_chart_of_accounts` (`company_id`, `account_code`, `account_name`, `account_type`, `parent_id`, `normal_balance`, `is_header`, `is_active`, `description`)
VALUES (@company_id, '4200', 'Service Charge Income', 'Income', @income_header_id, 'credit', 0, 1, 'Service charges collected from tenants');

-- 4300 - Penalty Income
INSERT INTO `re_chart_of_accounts` (`company_id`, `account_code`, `account_name`, `account_type`, `parent_id`, `normal_balance`, `is_header`, `is_active`, `description`)
VALUES (@company_id, '4300', 'Penalty Income', 'Income', @income_header_id, 'credit', 0, 1, 'Late fees and penalties collected');

-- 4400 - Other Income
INSERT INTO `re_chart_of_accounts` (`company_id`, `account_code`, `account_name`, `account_type`, `parent_id`, `normal_balance`, `is_header`, `is_active`, `description`)
VALUES (@company_id, '4400', 'Other Income', 'Income', @income_header_id, 'credit', 0, 1, 'Other miscellaneous income');

-- ============================================================================
-- EXPENSES (5000-5999)
-- ============================================================================

-- 5000 - Operating Expenses (Header)
INSERT INTO `re_chart_of_accounts` (`company_id`, `account_code`, `account_name`, `account_type`, `parent_id`, `normal_balance`, `is_header`, `is_active`, `description`)
VALUES (@company_id, '5000', 'Operating Expenses', 'Expense', NULL, 'debit', 1, 1, 'All operating expenses');

SET @expense_header_id = LAST_INSERT_ID();

-- 5100 - Maintenance Expenses (Header)
INSERT INTO `re_chart_of_accounts` (`company_id`, `account_code`, `account_name`, `account_type`, `parent_id`, `normal_balance`, `is_header`, `is_active`, `description`)
VALUES (@company_id, '5100', 'Maintenance Expenses', 'Expense', @expense_header_id, 'debit', 1, 1, 'All maintenance related expenses');

SET @maint_header_id = LAST_INSERT_ID();

-- 5110 - Building Maintenance
INSERT INTO `re_chart_of_accounts` (`company_id`, `account_code`, `account_name`, `account_type`, `parent_id`, `normal_balance`, `is_header`, `is_active`, `description`)
VALUES (@company_id, '5110', 'Building Maintenance', 'Expense', @maint_header_id, 'debit', 0, 1, 'Building maintenance and repairs');

-- 5120 - Unit Maintenance
INSERT INTO `re_chart_of_accounts` (`company_id`, `account_code`, `account_name`, `account_type`, `parent_id`, `normal_balance`, `is_header`, `is_active`, `description`)
VALUES (@company_id, '5120', 'Unit Maintenance', 'Expense', @maint_header_id, 'debit', 0, 1, 'Unit maintenance and repairs');

-- 5200 - Utilities (Header)
INSERT INTO `re_chart_of_accounts` (`company_id`, `account_code`, `account_name`, `account_type`, `parent_id`, `normal_balance`, `is_header`, `is_active`, `description`)
VALUES (@company_id, '5200', 'Utilities', 'Expense', @expense_header_id, 'debit', 1, 1, 'Utility expenses');

SET @util_header_id = LAST_INSERT_ID();

-- 5210 - Electricity
INSERT INTO `re_chart_of_accounts` (`company_id`, `account_code`, `account_name`, `account_type`, `parent_id`, `normal_balance`, `is_header`, `is_active`, `description`)
VALUES (@company_id, '5210', 'Electricity', 'Expense', @util_header_id, 'debit', 0, 1, 'Electricity expenses');

-- 5220 - Water
INSERT INTO `re_chart_of_accounts` (`company_id`, `account_code`, `account_name`, `account_type`, `parent_id`, `normal_balance`, `is_header`, `is_active`, `description`)
VALUES (@company_id, '5220', 'Water', 'Expense', @util_header_id, 'debit', 0, 1, 'Water expenses');

-- 5230 - Chiller
INSERT INTO `re_chart_of_accounts` (`company_id`, `account_code`, `account_name`, `account_type`, `parent_id`, `normal_balance`, `is_header`, `is_active`, `description`)
VALUES (@company_id, '5230', 'Chiller', 'Expense', @util_header_id, 'debit', 0, 1, 'Chiller expenses');

-- 5300 - Administrative Expenses (Header)
INSERT INTO `re_chart_of_accounts` (`company_id`, `account_code`, `account_name`, `account_type`, `parent_id`, `normal_balance`, `is_header`, `is_active`, `description`)
VALUES (@company_id, '5300', 'Administrative Expenses', 'Expense', @expense_header_id, 'debit', 1, 1, 'Administrative and office expenses');

SET @admin_header_id = LAST_INSERT_ID();

-- 5310 - Salaries
INSERT INTO `re_chart_of_accounts` (`company_id`, `account_code`, `account_name`, `account_type`, `parent_id`, `normal_balance`, `is_header`, `is_active`, `description`)
VALUES (@company_id, '5310', 'Salaries', 'Expense', @admin_header_id, 'debit', 0, 1, 'Employee salaries and wages');

-- 5320 - Office Rent
INSERT INTO `re_chart_of_accounts` (`company_id`, `account_code`, `account_name`, `account_type`, `parent_id`, `normal_balance`, `is_header`, `is_active`, `description`)
VALUES (@company_id, '5320', 'Office Rent', 'Expense', @admin_header_id, 'debit', 0, 1, 'Office rent expenses');

-- 5330 - Professional Fees
INSERT INTO `re_chart_of_accounts` (`company_id`, `account_code`, `account_name`, `account_type`, `parent_id`, `normal_balance`, `is_header`, `is_active`, `description`)
VALUES (@company_id, '5330', 'Professional Fees', 'Expense', @admin_header_id, 'debit', 0, 1, 'Legal, accounting, and professional service fees');

-- 5400 - Depreciation
INSERT INTO `re_chart_of_accounts` (`company_id`, `account_code`, `account_name`, `account_type`, `parent_id`, `normal_balance`, `is_header`, `is_active`, `description`)
VALUES (@company_id, '5400', 'Depreciation', 'Expense', @expense_header_id, 'debit', 0, 1, 'Depreciation of fixed assets');

-- ============================================================================
-- END OF CHART OF ACCOUNTS SEEDING
-- ============================================================================
