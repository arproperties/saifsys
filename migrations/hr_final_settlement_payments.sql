-- Final settlement paid: amount paid and the attached documents (added 2026-11-25).
-- The date received is the sheet's existing paid_date. Run once in phpMyAdmin. Safe to run again.

-- The sheets table (already on live since 13 Nov 2026; created here only where it is missing).
CREATE TABLE IF NOT EXISTS hr_final_settlements (
  id INT AUTO_INCREMENT PRIMARY KEY,
  employee_id INT NOT NULL,
  company_id INT NULL,
  header_json TEXT NULL,
  due_json TEXT NULL,
  deduction_json TEXT NULL,
  note VARCHAR(500) NULL,
  payment_method VARCHAR(20) NULL,
  cheque_no VARCHAR(60) NULL,
  paid_date DATE NULL,
  total_due DECIMAL(12,2) NOT NULL DEFAULT 0,
  total_deduct DECIMAL(12,2) NOT NULL DEFAULT 0,
  net_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
  created_by INT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_by INT NULL,
  updated_at DATETIME NULL,
  KEY (employee_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE hr_final_settlements
  ADD COLUMN IF NOT EXISTS paid_amount DECIMAL(12,2) NULL AFTER paid_date,
  ADD COLUMN IF NOT EXISTS files_json TEXT NULL AFTER paid_amount,
  ADD COLUMN IF NOT EXISTS completed_at DATETIME NULL AFTER files_json;  -- set by the "Final settlement completed" button
