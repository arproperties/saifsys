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

-- Investments: money invested TO a person or a company (Owner and Admin only).
CREATE TABLE IF NOT EXISTS lt_investments (
  id INT AUTO_INCREMENT PRIMARY KEY,
  company_id INT NOT NULL,
  invest_to ENUM('person','company') NOT NULL,
  name VARCHAR(200) NOT NULL,
  contact_name VARCHAR(200) NULL,
  phone VARCHAR(50) NULL,
  email VARCHAR(150) NULL,
  amount DECIMAL(14,2) NOT NULL,
  invested_on DATE NOT NULL,
  purpose VARCHAR(500) NULL,
  paid_by ENUM('cash','bank','cheque') NOT NULL DEFAULT 'bank',
  reference VARCHAR(100) NULL,
  proof_path VARCHAR(255) NULL,
  proof_name VARCHAR(255) NULL,
  duration_months INT NOT NULL,
  end_date DATE NOT NULL,
  return_type ENUM('percent','fixed','none') NOT NULL DEFAULT 'percent',
  return_value DECIMAL(14,3) NULL COMMENT 'percent: % of the amount per period; fixed: AED per period',
  return_frequency ENUM('monthly','quarterly','end') NULL,
  notes VARCHAR(1000) NULL,
  status ENUM('active','closed') NOT NULL DEFAULT 'active',
  closed_at DATETIME NULL,
  closed_by INT NULL,
  close_note VARCHAR(255) NULL,
  created_by INT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_lt_inv_company (company_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- What secures an investment: cheques, property, gold, a guarantor, an agreement…
CREATE TABLE IF NOT EXISTS lt_investment_security (
  id INT AUTO_INCREMENT PRIMARY KEY,
  investment_id INT NOT NULL,
  kind ENUM('cheque','property','gold','guarantor','agreement','other') NOT NULL,
  details VARCHAR(255) NULL,
  cheque_no VARCHAR(50) NULL,
  bank VARCHAR(100) NULL,
  amount DECIMAL(14,2) NULL,
  item_date DATE NULL COMMENT 'cheque date, or date of the document',
  file_path VARCHAR(255) NULL,
  original_name VARCHAR(255) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_lt_invsec (investment_id),
  CONSTRAINT fk_lt_invsec FOREIGN KEY (investment_id) REFERENCES lt_investments (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Documents of an investment: agreement, Emirates ID / passport, trade licence…
CREATE TABLE IF NOT EXISTS lt_investment_documents (
  id INT AUTO_INCREMENT PRIMARY KEY,
  investment_id INT NOT NULL,
  title VARCHAR(150) NOT NULL,
  file_path VARCHAR(255) NOT NULL,
  original_name VARCHAR(255) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_lt_invdoc (investment_id),
  CONSTRAINT fk_lt_invdoc FOREIGN KEY (investment_id) REFERENCES lt_investments (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Returns received on an investment (profit, rent, dividend…).
CREATE TABLE IF NOT EXISTS lt_investment_returns (
  id INT AUTO_INCREMENT PRIMARY KEY,
  investment_id INT NOT NULL,
  received_on DATE NOT NULL,
  amount DECIMAL(14,2) NOT NULL,
  paid_by ENUM('cash','bank','cheque') NOT NULL DEFAULT 'bank',
  reference VARCHAR(100) NULL,
  notes VARCHAR(200) NULL,
  file_path VARCHAR(255) NULL,
  original_name VARCHAR(255) NULL,
  created_by INT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_lt_invret (investment_id, received_on),
  CONSTRAINT fk_lt_invret FOREIGN KEY (investment_id) REFERENCES lt_investments (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Investments: how the return is paid and where it stands (both optional).
ALTER TABLE lt_investments
  ADD COLUMN IF NOT EXISTS return_mode ENUM('cash','bank','cheque') NULL AFTER return_frequency,
  ADD COLUMN IF NOT EXISTS return_status ENUM('not_started','ongoing','late','completed') NULL AFTER return_mode;

-- Investments: who else the owners (LT_INV_EMPLOYEES) have shared the pages with, read only or edit.
CREATE TABLE IF NOT EXISTS lt_investment_access (
  id INT AUTO_INCREMENT PRIMARY KEY,
  employee_id INT NOT NULL,
  level ENUM('view','edit') NOT NULL DEFAULT 'view',
  granted_by INT NULL,
  granted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_lt_inv_access (employee_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Investments: access requests from people without access, for the owners to approve or decline.
CREATE TABLE IF NOT EXISTS lt_investment_access_requests (
  id INT AUTO_INCREMENT PRIMARY KEY,
  employee_id INT NOT NULL,
  user_id INT NULL,
  reason VARCHAR(500) NULL,
  status ENUM('pending','approved','declined') NOT NULL DEFAULT 'pending',
  decided_by INT NULL,
  decided_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_lt_inv_req (status, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Post-dated cheques (Recurring Payments → Post-dated cheques): any cheque we give or receive.
-- Separate from Real Estate's tenant cheques (re_post_dated_cheques).
CREATE TABLE IF NOT EXISTS lt_pdc (
  id INT AUTO_INCREMENT PRIMARY KEY,
  company_id INT NOT NULL,
  direction ENUM('issued','received') NOT NULL DEFAULT 'issued' COMMENT 'issued: we gave it; received: given to us',
  party_name VARCHAR(200) NOT NULL COMMENT 'paid to (issued) or received from (received)',
  cheque_no VARCHAR(50) NOT NULL,
  bank VARCHAR(120) NULL,
  cheque_date DATE NOT NULL,
  amount DECIMAL(14,2) NOT NULL,
  purpose VARCHAR(255) NULL,
  reference VARCHAR(100) NULL,
  status ENUM('pending','deposited','cleared','bounced','cancelled','replaced','on_hold') NOT NULL DEFAULT 'pending',
  status_date DATE NULL,
  notes VARCHAR(1000) NULL,
  file_path VARCHAR(255) NULL,
  original_name VARCHAR(255) NULL,
  created_by INT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_by INT NULL,
  updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_lt_pdc_company (company_id, status, cheque_date),
  KEY idx_lt_pdc_date (cheque_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Every status change of a post-dated cheque, with the date and a note.
CREATE TABLE IF NOT EXISTS lt_pdc_history (
  id INT AUTO_INCREMENT PRIMARY KEY,
  pdc_id INT NOT NULL,
  from_status VARCHAR(20) NULL,
  to_status VARCHAR(20) NOT NULL,
  changed_on DATE NOT NULL,
  note VARCHAR(255) NULL,
  changed_by INT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_lt_pdc_hist (pdc_id),
  CONSTRAINT fk_lt_pdc_hist FOREIGN KEY (pdc_id) REFERENCES lt_pdc (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Post Dated Cheques: who approved the cheque (filled with the person adding it, can be changed).
ALTER TABLE lt_pdc ADD COLUMN IF NOT EXISTS approved_by VARCHAR(150) NULL AFTER reference;

-- Post Dated Cheques: status "Other" with its own name (each name typed once can be picked again).
ALTER TABLE lt_pdc
  MODIFY status ENUM('pending','deposited','cleared','bounced','cancelled','replaced','on_hold','other') NOT NULL DEFAULT 'pending',
  ADD COLUMN IF NOT EXISTS status_other VARCHAR(60) NULL AFTER status;
ALTER TABLE lt_pdc_history
  ADD COLUMN IF NOT EXISTS from_other VARCHAR(60) NULL AFTER from_status,
  ADD COLUMN IF NOT EXISTS to_other VARCHAR(60) NULL AFTER to_status;

-- Post Dated Cheques: who gets the email alerts (as well as the person who added the cheque).
CREATE TABLE IF NOT EXISTS lt_pdc_alert_recipients (
  id INT AUTO_INCREMENT PRIMARY KEY,
  company_id INT NOT NULL,
  email VARCHAR(150) NOT NULL,
  on_due TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'due within 7 days, and overdue',
  on_bounce TINYINT(1) NOT NULL DEFAULT 1,
  created_by INT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_lt_pdc_rcpt (company_id, email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Each alert sent, once: due_soon / overdue per cheque date, bounced per status change.
CREATE TABLE IF NOT EXISTS lt_pdc_alert_log (
  id INT AUTO_INCREMENT PRIMARY KEY,
  pdc_id INT NOT NULL,
  kind ENUM('due_soon','overdue','bounced') NOT NULL,
  ref_key VARCHAR(30) NOT NULL COMMENT 'cheque date for due_soon/overdue, history id for bounced',
  sent_to VARCHAR(1000) NULL,
  result ENUM('sent','failed','no_recipients') NOT NULL,
  error VARCHAR(255) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_lt_pdc_alert (pdc_id, kind, ref_key),
  CONSTRAINT fk_lt_pdc_alert FOREIGN KEY (pdc_id) REFERENCES lt_pdc (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- The day the due-date check last ran (so opening the page runs it at most once a day).
CREATE TABLE IF NOT EXISTS lt_pdc_alert_runs (
  run_date DATE PRIMARY KEY,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Investments: "custom plan" payouts — each payout typed in by hand (date, amount, note).
ALTER TABLE lt_investments
  MODIFY return_type ENUM('percent','fixed','none','custom') NOT NULL DEFAULT 'percent',
  ADD COLUMN IF NOT EXISTS plan_total DECIMAL(14,2) NULL AFTER return_status,
  ADD COLUMN IF NOT EXISTS plan_count INT NULL AFTER plan_total;
CREATE TABLE IF NOT EXISTS lt_investment_plan (
  id INT AUTO_INCREMENT PRIMARY KEY,
  investment_id INT NOT NULL,
  seq INT NOT NULL,
  due_date DATE NOT NULL,
  amount DECIMAL(14,2) NOT NULL,
  note VARCHAR(150) NULL,
  KEY idx_lt_invplan (investment_id, seq),
  CONSTRAINT fk_lt_invplan FOREIGN KEY (investment_id) REFERENCES lt_investments (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Investments plan: "received after N months" (from the date invested); the date is worked out from it.
ALTER TABLE lt_investment_plan ADD COLUMN IF NOT EXISTS after_months INT NULL AFTER seq;

-- Post Dated Cheques: location (building, office, site…), also a column of the Excel import.
ALTER TABLE lt_pdc ADD COLUMN IF NOT EXISTS location VARCHAR(150) NULL AFTER purpose;
ALTER TABLE lt_pdc ADD COLUMN IF NOT EXISTS issued_by VARCHAR(150) NULL AFTER approved_by;

-- Investments: notes added on the investment page (shown in its timeline).
CREATE TABLE IF NOT EXISTS lt_investment_notes (
  id INT AUTO_INCREMENT PRIMARY KEY,
  investment_id INT NOT NULL,
  note VARCHAR(1000) NOT NULL,
  created_by INT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_lt_invnote (investment_id),
  CONSTRAINT fk_lt_invnote FOREIGN KEY (investment_id) REFERENCES lt_investments (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
ALTER TABLE lt_investment_plan ADD COLUMN IF NOT EXISTS after_unit ENUM('days','weeks','months','years') NOT NULL DEFAULT 'months' AFTER after_months;
-- "Receive after" unit: days / weeks / months / years, or any word typed in.
ALTER TABLE lt_investment_plan MODIFY after_unit VARCHAR(30) NOT NULL DEFAULT 'months';
