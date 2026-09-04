-- HR: Evolve cash advances into Loans / Salary Advances (shared multi-company HR).
-- Safe additive migration. Run with human approval on live.
-- Preserves existing cash_advances rows and history.

-- Loan fields on cash_advances
SET @db := DATABASE();

SET @sql := (
  SELECT IF(
    COUNT(*) = 0,
    'ALTER TABLE cash_advances ADD COLUMN product_type ENUM(''salary_advance'',''loan'') NOT NULL DEFAULT ''salary_advance'' AFTER employee_id',
    'SELECT 1'
  )
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'cash_advances' AND COLUMN_NAME = 'product_type'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := (
  SELECT IF(
    COUNT(*) = 0,
    'ALTER TABLE cash_advances ADD COLUMN principal DECIMAL(12,2) NULL AFTER amount',
    'SELECT 1'
  )
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'cash_advances' AND COLUMN_NAME = 'principal'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := (
  SELECT IF(
    COUNT(*) = 0,
    'ALTER TABLE cash_advances ADD COLUMN installment_count INT UNSIGNED NOT NULL DEFAULT 1 AFTER principal',
    'SELECT 1'
  )
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'cash_advances' AND COLUMN_NAME = 'installment_count'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := (
  SELECT IF(
    COUNT(*) = 0,
    'ALTER TABLE cash_advances ADD COLUMN installment_amount DECIMAL(12,2) NULL AFTER installment_count',
    'SELECT 1'
  )
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'cash_advances' AND COLUMN_NAME = 'installment_amount'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := (
  SELECT IF(
    COUNT(*) = 0,
    'ALTER TABLE cash_advances ADD COLUMN remaining_balance DECIMAL(12,2) NULL AFTER installment_amount',
    'SELECT 1'
  )
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'cash_advances' AND COLUMN_NAME = 'remaining_balance'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := (
  SELECT IF(
    COUNT(*) = 0,
    'ALTER TABLE cash_advances ADD COLUMN preferred_settle_method ENUM(''payroll'',''cash'') NOT NULL DEFAULT ''payroll'' AFTER remaining_balance',
    'SELECT 1'
  )
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'cash_advances' AND COLUMN_NAME = 'preferred_settle_method'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := (
  SELECT IF(
    COUNT(*) = 0,
    'ALTER TABLE cash_advances ADD COLUMN start_date DATE NULL AFTER preferred_settle_method',
    'SELECT 1'
  )
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'cash_advances' AND COLUMN_NAME = 'start_date'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Backfill principal / remaining from amount for existing rows
UPDATE cash_advances
SET principal = COALESCE(principal, amount),
    remaining_balance = COALESCE(
      remaining_balance,
      CASE
        WHEN status = 'settled' THEN 0
        WHEN status = 'void' THEN 0
        ELSE amount
      END
    ),
    installment_count = GREATEST(COALESCE(installment_count, 1), 1),
    installment_amount = COALESCE(
      installment_amount,
      ROUND(COALESCE(principal, amount) / GREATEST(COALESCE(installment_count, 1), 1), 2)
    ),
    start_date = COALESCE(start_date, tx_date),
    product_type = COALESCE(product_type, 'salary_advance')
WHERE remaining_balance IS NULL
   OR principal IS NULL
   OR start_date IS NULL;

CREATE TABLE IF NOT EXISTS hr_loan_settlements (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  loan_id INT NOT NULL,
  employee_id INT NOT NULL,
  company_id INT NULL,
  settle_date DATE NOT NULL,
  amount DECIMAL(12,2) NOT NULL,
  method ENUM('payroll','cash') NOT NULL,
  payroll_run_id INT NULL,
  payroll_item_id INT NULL,
  notes VARCHAR(255) NULL,
  created_by INT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_hr_loan_settlements_loan (loan_id),
  KEY idx_hr_loan_settlements_employee (employee_id),
  KEY idx_hr_loan_settlements_company (company_id),
  KEY idx_hr_loan_settlements_run (payroll_run_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Fines / other deductions: settlement tracking
SET @sql := (
  SELECT IF(
    COUNT(*) = 0,
    'ALTER TABLE employee_deductions ADD COLUMN remaining_balance DECIMAL(12,2) NULL AFTER amount',
    'SELECT 1'
  )
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'employee_deductions' AND COLUMN_NAME = 'remaining_balance'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := (
  SELECT IF(
    COUNT(*) = 0,
    'ALTER TABLE employee_deductions ADD COLUMN preferred_settle_method ENUM(''payroll'',''cash'') NOT NULL DEFAULT ''payroll'' AFTER remaining_balance',
    'SELECT 1'
  )
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'employee_deductions' AND COLUMN_NAME = 'preferred_settle_method'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := (
  SELECT IF(
    COUNT(*) = 0,
    'ALTER TABLE employee_deductions ADD COLUMN settle_status ENUM(''open'',''settled'',''void'') NOT NULL DEFAULT ''open'' AFTER preferred_settle_method',
    'SELECT 1'
  )
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'employee_deductions' AND COLUMN_NAME = 'settle_status'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

UPDATE employee_deductions
SET remaining_balance = COALESCE(remaining_balance, amount),
    preferred_settle_method = COALESCE(preferred_settle_method, 'payroll'),
    settle_status = COALESCE(settle_status, 'open')
WHERE remaining_balance IS NULL OR settle_status IS NULL OR settle_status = '';

CREATE TABLE IF NOT EXISTS hr_deduction_settlements (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  deduction_id INT NOT NULL,
  employee_id INT NOT NULL,
  company_id INT NULL,
  settle_date DATE NOT NULL,
  amount DECIMAL(12,2) NOT NULL,
  method ENUM('payroll','cash') NOT NULL,
  payroll_run_id INT NULL,
  payroll_item_id INT NULL,
  notes VARCHAR(255) NULL,
  created_by INT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_hr_ded_settle_deduction (deduction_id),
  KEY idx_hr_ded_settle_employee (employee_id),
  KEY idx_hr_ded_settle_company (company_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
