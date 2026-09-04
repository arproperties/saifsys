-- HR Payroll dual payment type and accounting integration.
-- Run this before uploading the PHP changes.

ALTER TABLE employees
  ADD COLUMN IF NOT EXISTS payment_type ENUM('wps','cash') NOT NULL DEFAULT 'wps' AFTER total_salary,
  ADD INDEX IF NOT EXISTS idx_employees_payment_type (payment_type);

ALTER TABLE payroll_runs
  ADD COLUMN IF NOT EXISTS payroll_type ENUM('wps','cash') NOT NULL DEFAULT 'wps' AFTER company_id,
  ADD COLUMN IF NOT EXISTS accounting_journal_id BIGINT(20) UNSIGNED NULL AFTER status,
  ADD COLUMN IF NOT EXISTS accounting_system ENUM('standalone','shared') NULL AFTER accounting_journal_id,
  ADD COLUMN IF NOT EXISTS accounting_posted_at DATETIME NULL AFTER accounting_journal_id,
  ADD COLUMN IF NOT EXISTS accounting_error VARCHAR(500) NULL AFTER accounting_posted_at,
  ADD INDEX IF NOT EXISTS idx_payroll_runs_company_type_period (company_id, payroll_type, period_from, period_to),
  ADD INDEX IF NOT EXISTS idx_payroll_runs_accounting_journal (accounting_journal_id);

ALTER TABLE chart_of_accounts
  ADD COLUMN IF NOT EXISTS company_id INT(11) NOT NULL DEFAULT 1 AFTER updated_at,
  ADD INDEX IF NOT EXISTS idx_chart_of_accounts_company (company_id);

ALTER TABLE gl_journals
  MODIFY source ENUM('invoice','receipt','expense','manual','adjustment','reversal','payroll') NOT NULL,
  ADD COLUMN IF NOT EXISTS company_id INT(11) NOT NULL DEFAULT 1 AFTER created_at,
  ADD INDEX IF NOT EXISTS idx_gl_journals_company_date (company_id, journal_date),
  ADD INDEX IF NOT EXISTS idx_gl_journals_company_source (company_id, source, source_id);

INSERT IGNORE INTO chart_of_accounts
  (account_no, name, type, normal_balance, is_active, is_header, description, company_id)
VALUES
  ('1010', 'Petty Cash', 'Asset', 'debit', 1, 0, 'Default cash account for cash payroll posting', 1),
  ('1020', 'Main Bank Account', 'Asset', 'debit', 1, 0, 'Default bank account for WPS payroll posting', 1),
  ('2130', 'Accrued Salaries & Wages', 'Liability', 'credit', 1, 0, 'Default WPS payroll payable account', 1),
  ('5210', 'Salaries & Wages (Admin)', 'Expense', 'debit', 1, 0, 'Default salary expense account for payroll posting', 1);

CREATE TABLE IF NOT EXISTS payroll_account_settings (
  id INT(11) NOT NULL AUTO_INCREMENT,
  company_id INT(11) NOT NULL,
  payroll_type ENUM('wps','cash') NOT NULL,
  salary_expense_account_id INT(10) UNSIGNED NOT NULL,
  credit_account_id INT(10) UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_payroll_account_company_type (company_id, payroll_type),
  KEY idx_payroll_account_company (company_id),
  KEY idx_payroll_account_salary (salary_expense_account_id),
  KEY idx_payroll_account_credit (credit_account_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO payroll_account_settings
  (company_id, payroll_type, salary_expense_account_id, credit_account_id)
SELECT c.id, 'wps', se.id, cr.id
FROM companies c
JOIN chart_of_accounts se ON se.account_no = '5210'
JOIN chart_of_accounts cr ON cr.account_no = '2130'
WHERE c.is_active = 1
  AND NOT EXISTS (
    SELECT 1 FROM payroll_account_settings pas
    WHERE pas.company_id = c.id AND pas.payroll_type = 'wps'
  );

INSERT INTO payroll_account_settings
  (company_id, payroll_type, salary_expense_account_id, credit_account_id)
SELECT c.id, 'cash', se.id, cr.id
FROM companies c
JOIN chart_of_accounts se ON se.account_no = '5210'
JOIN chart_of_accounts cr ON cr.account_no = '1010'
WHERE c.is_active = 1
  AND NOT EXISTS (
    SELECT 1 FROM payroll_account_settings pas
    WHERE pas.company_id = c.id AND pas.payroll_type = 'cash'
  );

DELETE pas
FROM payroll_account_settings pas
JOIN companies c ON c.id = pas.company_id
WHERE COALESCE(c.business_type, '') <> 'cleaning';

INSERT INTO re_chart_of_accounts
  (company_id, account_code, account_name, account_type, normal_balance, is_header, is_active, description)
SELECT c.id, '1110', 'Cash on Hand', 'Asset', 'debit', 0, 1, 'Payroll cash account'
FROM companies c
WHERE c.is_active = 1
  AND COALESCE(c.business_type, '') <> 'cleaning'
  AND NOT EXISTS (
    SELECT 1 FROM re_chart_of_accounts coa
    WHERE coa.company_id = c.id AND coa.account_code = '1110'
  );

INSERT INTO re_chart_of_accounts
  (company_id, account_code, account_name, account_type, normal_balance, is_header, is_active, description)
SELECT c.id, '2135', 'Payroll / WPS Payable', 'Liability', 'credit', 0, 1, 'Payroll payable account for WPS salary runs'
FROM companies c
WHERE c.is_active = 1
  AND COALESCE(c.business_type, '') <> 'cleaning'
  AND NOT EXISTS (
    SELECT 1 FROM re_chart_of_accounts coa
    WHERE coa.company_id = c.id AND coa.account_code = '2135'
  );

INSERT INTO re_chart_of_accounts
  (company_id, account_code, account_name, account_type, normal_balance, is_header, is_active, description)
SELECT c.id, '5290', 'Payroll Salaries & Wages', 'Expense', 'debit', 0, 1, 'Payroll salary expense account'
FROM companies c
WHERE c.is_active = 1
  AND COALESCE(c.business_type, '') <> 'cleaning'
  AND NOT EXISTS (
    SELECT 1 FROM re_chart_of_accounts coa
    WHERE coa.company_id = c.id AND coa.account_code = '5290'
  );
