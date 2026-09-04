-- Service Management Phase 7 — expenses workflow, prepaid schedules, manual & recurring JVs
-- Apply via: php tools/sm_apply_phase7_schema.php

CREATE TABLE IF NOT EXISTS sm_manual_journals (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  company_id INT NOT NULL DEFAULT 1,
  journal_date DATE NOT NULL,
  memo VARCHAR(255) DEFAULT NULL,
  status ENUM('draft','posted','void') NOT NULL DEFAULT 'draft',
  gl_journal_id BIGINT UNSIGNED DEFAULT NULL,
  created_by INT UNSIGNED DEFAULT NULL,
  posted_by INT UNSIGNED DEFAULT NULL,
  posted_at DATETIME DEFAULT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_sm_mj_status (status),
  KEY idx_sm_mj_date (journal_date),
  KEY idx_sm_mj_company (company_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS sm_manual_journal_lines (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  manual_journal_id INT UNSIGNED NOT NULL,
  line_no INT NOT NULL DEFAULT 1,
  account_no VARCHAR(20) NOT NULL,
  description VARCHAR(255) DEFAULT NULL,
  debit DECIMAL(18,2) NOT NULL DEFAULT 0.00,
  credit DECIMAL(18,2) NOT NULL DEFAULT 0.00,
  PRIMARY KEY (id),
  KEY idx_sm_mjl_mj (manual_journal_id),
  CONSTRAINT fk_sm_mjl_mj FOREIGN KEY (manual_journal_id) REFERENCES sm_manual_journals(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS sm_prepaid_schedules (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  company_id INT NOT NULL DEFAULT 1,
  expense_id BIGINT UNSIGNED DEFAULT NULL,
  vendor_id INT UNSIGNED DEFAULT NULL,
  description VARCHAR(255) NOT NULL,
  start_date DATE NOT NULL,
  months INT NOT NULL DEFAULT 12,
  total_amount DECIMAL(18,2) NOT NULL DEFAULT 0.00,
  monthly_amount DECIMAL(18,2) NOT NULL DEFAULT 0.00,
  prepaid_account_no VARCHAR(20) NOT NULL DEFAULT '1310',
  expense_account_no VARCHAR(20) NOT NULL,
  status ENUM('active','completed','cancelled') NOT NULL DEFAULT 'active',
  created_by INT UNSIGNED DEFAULT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_sm_prepaid_status (status),
  KEY idx_sm_prepaid_expense (expense_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS sm_prepaid_amortization (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  schedule_id INT UNSIGNED NOT NULL,
  period_month CHAR(7) NOT NULL COMMENT 'YYYY-MM',
  amount DECIMAL(18,2) NOT NULL DEFAULT 0.00,
  gl_journal_id BIGINT UNSIGNED DEFAULT NULL,
  amortized_at DATETIME DEFAULT NULL,
  status ENUM('pending','posted','skipped') NOT NULL DEFAULT 'pending',
  PRIMARY KEY (id),
  UNIQUE KEY uq_sm_prepaid_period (schedule_id, period_month),
  KEY idx_sm_prepaid_amort_status (status),
  CONSTRAINT fk_sm_prepaid_amort_sched FOREIGN KEY (schedule_id) REFERENCES sm_prepaid_schedules(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS sm_recurring_journals (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  company_id INT NOT NULL DEFAULT 1,
  name VARCHAR(120) NOT NULL,
  memo VARCHAR(255) DEFAULT NULL,
  frequency ENUM('monthly','quarterly','yearly') NOT NULL DEFAULT 'monthly',
  next_run_date DATE NOT NULL,
  last_run_date DATE DEFAULT NULL,
  day_of_month TINYINT UNSIGNED DEFAULT NULL,
  status ENUM('active','paused') NOT NULL DEFAULT 'active',
  created_by INT UNSIGNED DEFAULT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_sm_rj_next (next_run_date, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS sm_recurring_journal_lines (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  recurring_journal_id INT UNSIGNED NOT NULL,
  line_no INT NOT NULL DEFAULT 1,
  account_no VARCHAR(20) NOT NULL,
  description VARCHAR(255) DEFAULT NULL,
  debit DECIMAL(18,2) NOT NULL DEFAULT 0.00,
  credit DECIMAL(18,2) NOT NULL DEFAULT 0.00,
  PRIMARY KEY (id),
  KEY idx_sm_rjl_rj (recurring_journal_id),
  CONSTRAINT fk_sm_rjl_rj FOREIGN KEY (recurring_journal_id) REFERENCES sm_recurring_journals(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
