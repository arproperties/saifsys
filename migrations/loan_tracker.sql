-- Loan Tracker: the loan and the borrower's documents.
-- Safe to run twice. Amounts and instalments come with the next step (loan details).

CREATE TABLE IF NOT EXISTS lt_loans (
  id INT AUTO_INCREMENT PRIMARY KEY,
  company_id INT NOT NULL,
  loan_to ENUM('employee','person','company') NOT NULL,
  employee_id INT NULL,
  borrower_name VARCHAR(200) NOT NULL,
  contact_name VARCHAR(200) NULL,
  phone VARCHAR(50) NULL,
  email VARCHAR(150) NULL,
  status ENUM('draft','active','closed','cancelled') NOT NULL DEFAULT 'draft',
  created_by INT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_lt_loans_company (company_id, status),
  KEY idx_lt_loans_employee (employee_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS lt_loan_documents (
  id INT AUTO_INCREMENT PRIMARY KEY,
  loan_id INT NOT NULL,
  doc_kind ENUM('emirates_id','passport','visa','trade_licence','other') NOT NULL,
  title VARCHAR(150) NOT NULL,
  doc_number VARCHAR(100) NULL,
  expires_at DATE NULL,
  -- 'hr' = the employee's file in HR (employee_documents), 'upload' = attached here
  source ENUM('hr','upload') NOT NULL DEFAULT 'upload',
  hr_document_id INT NULL,
  file_path VARCHAR(255) NOT NULL,
  original_name VARCHAR(255) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_lt_docs_loan (loan_id),
  KEY idx_lt_docs_expiry (expires_at),
  CONSTRAINT fk_lt_docs_loan FOREIGN KEY (loan_id) REFERENCES lt_loans (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- A required document can be skipped for now, with the reason why.
ALTER TABLE lt_loan_documents
  ADD COLUMN IF NOT EXISTS skipped TINYINT(1) NOT NULL DEFAULT 0 AFTER original_name,
  ADD COLUMN IF NOT EXISTS skip_reason VARCHAR(255) NULL AFTER skipped,
  MODIFY file_path VARCHAR(255) NULL;

-- HR staff loans hidden from the Loan Tracker dashboard. HR keeps the loan; this only hides it here.
CREATE TABLE IF NOT EXISTS lt_hidden_hr_loans (
  cash_advance_id INT NOT NULL PRIMARY KEY,
  hidden_by INT NULL,
  hidden_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Proof of a repayment recorded from Loan Tracker (receipt, bank slip). The repayment itself is HR's hr_loan_settlements row.
CREATE TABLE IF NOT EXISTS lt_repayment_files (
  id INT AUTO_INCREMENT PRIMARY KEY,
  settlement_id INT UNSIGNED NOT NULL,
  paid_by ENUM('cash','bank','cheque') NOT NULL DEFAULT 'cash',
  reference VARCHAR(100) NULL,
  file_path VARCHAR(255) NULL,
  original_name VARCHAR(255) NULL,
  created_by INT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_lt_rep_settlement (settlement_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Loan details (step 3) and submit (step 4).
ALTER TABLE lt_loans
  ADD COLUMN IF NOT EXISTS issue_date DATE NULL AFTER email,
  ADD COLUMN IF NOT EXISTS amount DECIMAL(14,2) NULL AFTER issue_date,
  ADD COLUMN IF NOT EXISTS installments INT NULL AFTER amount,
  ADD COLUMN IF NOT EXISTS interest_rate DECIMAL(6,3) NULL AFTER installments,
  ADD COLUMN IF NOT EXISTS total_payable DECIMAL(14,2) NULL AFTER interest_rate,
  ADD COLUMN IF NOT EXISTS repayment_mode ENUM('salary','cash','bank','cheque') NULL AFTER total_payable,
  ADD COLUMN IF NOT EXISTS paid_out_by ENUM('cash','bank') NULL AFTER repayment_mode,
  ADD COLUMN IF NOT EXISTS first_due_date DATE NULL AFTER paid_out_by,
  ADD COLUMN IF NOT EXISTS proof_path VARCHAR(255) NULL AFTER first_due_date,
  ADD COLUMN IF NOT EXISTS proof_name VARCHAR(255) NULL AFTER proof_path,
  ADD COLUMN IF NOT EXISTS details_saved_at DATETIME NULL AFTER proof_name,
  ADD COLUMN IF NOT EXISTS issued_by INT NULL AFTER details_saved_at,
  ADD COLUMN IF NOT EXISTS submitted_at DATETIME NULL AFTER issued_by;

-- The instalments of a submitted loan.
CREATE TABLE IF NOT EXISTS lt_loan_installments (
  id INT AUTO_INCREMENT PRIMARY KEY,
  loan_id INT NOT NULL,
  seq INT NOT NULL,
  due_date DATE NOT NULL,
  amount DECIMAL(14,2) NOT NULL,
  paid_amount DECIMAL(14,2) NOT NULL DEFAULT 0,
  UNIQUE KEY uq_lt_inst (loan_id, seq),
  KEY idx_lt_inst_due (due_date),
  CONSTRAINT fk_lt_inst_loan FOREIGN KEY (loan_id) REFERENCES lt_loans (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Repayments on loans made in Loan Tracker (staff loans from HR use HR's hr_loan_settlements).
CREATE TABLE IF NOT EXISTS lt_loan_repayments (
  id INT AUTO_INCREMENT PRIMARY KEY,
  loan_id INT NOT NULL,
  paid_on DATE NOT NULL,
  amount DECIMAL(14,2) NOT NULL,
  paid_by ENUM('salary','cash','bank','cheque') NOT NULL DEFAULT 'cash',
  reference VARCHAR(100) NULL,
  notes VARCHAR(200) NULL,
  file_path VARCHAR(255) NULL,
  original_name VARCHAR(255) NULL,
  created_by INT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_lt_rep_loan (loan_id),
  KEY idx_lt_rep_date (paid_on),
  CONSTRAINT fk_lt_rep_loan FOREIGN KEY (loan_id) REFERENCES lt_loans (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Closing a loan by hand, with the reason.
ALTER TABLE lt_loans
  ADD COLUMN IF NOT EXISTS closed_at DATETIME NULL AFTER submitted_at,
  ADD COLUMN IF NOT EXISTS closed_by INT NULL AFTER closed_at,
  ADD COLUMN IF NOT EXISTS close_note VARCHAR(255) NULL AFTER closed_by;

-- Employee loans made here are also an HR loan (cash_advances), so payroll can deduct them.
-- HR's recoveries (payroll or cash) are copied into lt_loan_repayments, once each.
ALTER TABLE lt_loans
  ADD COLUMN IF NOT EXISTS hr_loan_id INT NULL AFTER employee_id,
  ADD KEY IF NOT EXISTS idx_lt_hr_loan (hr_loan_id);
ALTER TABLE lt_loan_repayments
  ADD COLUMN IF NOT EXISTS hr_settlement_id INT UNSIGNED NULL AFTER loan_id,
  ADD UNIQUE KEY IF NOT EXISTS uq_lt_rep_hr (hr_settlement_id);
