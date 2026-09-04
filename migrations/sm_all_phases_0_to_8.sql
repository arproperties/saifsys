-- =============================================================================
-- Service Management — ALL PHASES (0 → 8) + go-live settings
-- =============================================================================
-- Idempotent: safe to re-run on live (skips existing columns/tables/settings).
--
-- Apply via MySQL client:
--   mysql -u USER -p DATABASE < migrations/sm_all_phases_0_to_8.sql
--
-- Or via PHP (recommended on shared hosting):
--   php tools/sm_apply_all_phases_schema.php
--
-- Phase map:
--   0/1  Accounting service feature flags (compare mode)
--   2    Finalize gate + financial lock columns on make_order
--   3    Adjustment requests + financial change log
--   4    Hybrid batch invoicing (BINV)
--   5    Service category foundation
--   5b   Category catalog + multi-service bookings
--   7    Expenses workflow, prepaid, manual & recurring JVs
--   8    GM dashboard / reporting settings
--   —    Live go-live health setting (orphan invoices)
-- =============================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- -----------------------------------------------------------------------------
-- PHASE 0/1 — Accounting service flags
-- -----------------------------------------------------------------------------
INSERT INTO settings (`key`, `value`)
SELECT 'sm_use_accounting_service', '0'
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM settings WHERE `key` = 'sm_use_accounting_service');

INSERT INTO settings (`key`, `value`)
SELECT 'sm_accounting_compare_mode', '1'
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM settings WHERE `key` = 'sm_accounting_compare_mode');

-- -----------------------------------------------------------------------------
-- PHASE 2 — Finalize gate (make_order columns + defer auto-invoice)
-- -----------------------------------------------------------------------------

INSERT INTO settings (`key`, `value`)
SELECT 'sm_defer_auto_invoice', '1'
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM settings WHERE `key` = 'sm_defer_auto_invoice');

-- make_order.is_finalized
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'make_order' AND COLUMN_NAME = 'is_finalized');
SET @sql = IF(@col_exists = 0, 'ALTER TABLE make_order ADD COLUMN is_finalized TINYINT(1) NOT NULL DEFAULT 0', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'make_order' AND COLUMN_NAME = 'finalized_at');
SET @sql = IF(@col_exists = 0, 'ALTER TABLE make_order ADD COLUMN finalized_at DATETIME NULL', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'make_order' AND COLUMN_NAME = 'finalized_by');
SET @sql = IF(@col_exists = 0, 'ALTER TABLE make_order ADD COLUMN finalized_by INT NULL', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'make_order' AND COLUMN_NAME = 'frozen_subtotal');
SET @sql = IF(@col_exists = 0, 'ALTER TABLE make_order ADD COLUMN frozen_subtotal DECIMAL(12,2) NULL', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'make_order' AND COLUMN_NAME = 'frozen_vat_amount');
SET @sql = IF(@col_exists = 0, 'ALTER TABLE make_order ADD COLUMN frozen_vat_amount DECIMAL(12,2) NULL', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'make_order' AND COLUMN_NAME = 'frozen_grand_total');
SET @sql = IF(@col_exists = 0, 'ALTER TABLE make_order ADD COLUMN frozen_grand_total DECIMAL(12,2) NULL', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'make_order' AND COLUMN_NAME = 'ops_status');
SET @sql = IF(@col_exists = 0, "ALTER TABLE make_order ADD COLUMN ops_status ENUM('open','completed','cancelled') NOT NULL DEFAULT 'open'", 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Backfill finalized flag for orders that already have active invoices
UPDATE make_order mo
INNER JOIN invoices i ON i.order_id = mo.id AND i.status <> 'void'
SET mo.is_finalized = 1,
    mo.finalized_at = COALESCE(mo.finalized_at, i.posted_at, i.created_at),
    mo.frozen_subtotal = COALESCE(mo.frozen_subtotal, mo.total),
    mo.frozen_vat_amount = COALESCE(mo.frozen_vat_amount, mo.vat_amount),
    mo.frozen_grand_total = COALESCE(mo.frozen_grand_total, mo.grand_total, mo.total + COALESCE(mo.vat_amount, 0)),
    mo.ops_status = IF(mo.status = 'cancelled', 'cancelled', 'completed')
WHERE mo.is_finalized = 0;

UPDATE make_order
SET ops_status = 'open'
WHERE is_finalized = 0
  AND COALESCE(status, '') NOT IN ('completed', 'invoiced', 'cancelled');

-- -----------------------------------------------------------------------------
-- PHASE 3 — Adjustment requests
-- -----------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS sm_adjustment_requests (
  id INT NOT NULL AUTO_INCREMENT,
  order_id INT NOT NULL,
  invoice_id INT NULL,
  request_type ENUM('amount_decrease','amount_increase','cancellation','other') NOT NULL DEFAULT 'other',
  status ENUM('pending','approved','rejected','cancelled') NOT NULL DEFAULT 'pending',
  reason VARCHAR(500) NOT NULL,
  notes TEXT NULL,
  current_subtotal DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  current_vat DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  current_grand DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  requested_subtotal DECIMAL(12,2) NULL,
  requested_vat DECIMAL(12,2) NULL,
  requested_grand DECIMAL(12,2) NULL,
  delta_grand DECIMAL(12,2) NULL,
  resolution_type ENUM('credit_note','supplementary_invoice','void_invoice','manual','none') NULL,
  credit_note_id INT NULL,
  adjustment_invoice_id INT NULL,
  requested_by INT NULL,
  requested_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  reviewed_by INT NULL,
  reviewed_at DATETIME NULL,
  review_notes TEXT NULL,
  PRIMARY KEY (id),
  KEY idx_sm_adj_order (order_id),
  KEY idx_sm_adj_status (status),
  KEY idx_sm_adj_invoice (invoice_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS sm_financial_change_log (
  id INT NOT NULL AUTO_INCREMENT,
  order_id INT NOT NULL,
  adjustment_request_id INT NULL,
  field_name VARCHAR(64) NOT NULL,
  old_value VARCHAR(255) NULL,
  new_value VARCHAR(255) NULL,
  change_source VARCHAR(64) NOT NULL DEFAULT 'adjustment',
  user_id INT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_sm_fcl_order (order_id),
  KEY idx_sm_fcl_adj (adjustment_request_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- -----------------------------------------------------------------------------
-- PHASE 4 — Hybrid batch invoicing (BINV)
-- -----------------------------------------------------------------------------

SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'invoices' AND COLUMN_NAME = 'is_batch_summary');
SET @sql = IF(@col_exists = 0, 'ALTER TABLE invoices ADD COLUMN is_batch_summary TINYINT(1) NOT NULL DEFAULT 0', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

CREATE TABLE IF NOT EXISTS sm_invoice_batches (
  id INT NOT NULL AUTO_INCREMENT,
  client_id INT NOT NULL,
  batch_invoice_id INT NOT NULL,
  range_start DATE NOT NULL,
  range_end DATE NOT NULL,
  child_count INT NOT NULL DEFAULT 0,
  subtotal DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  vat_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  grand_total DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  status ENUM('draft','issued','void') NOT NULL DEFAULT 'issued',
  notes VARCHAR(500) NULL,
  created_by INT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  issued_at DATETIME NULL,
  PRIMARY KEY (id),
  KEY idx_sm_batch_client (client_id),
  KEY idx_sm_batch_invoice (batch_invoice_id),
  KEY idx_sm_batch_range (range_start, range_end)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS sm_invoice_batch_lines (
  id INT NOT NULL AUTO_INCREMENT,
  batch_id INT NOT NULL,
  order_id INT NOT NULL,
  child_invoice_id INT NOT NULL,
  line_subtotal DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  line_vat DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  line_total DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  PRIMARY KEY (id),
  UNIQUE KEY uq_sm_batch_order (batch_id, order_id),
  UNIQUE KEY uq_sm_batch_child (batch_id, child_invoice_id),
  KEY idx_sm_batch_line_order (order_id),
  KEY idx_sm_batch_line_inv (child_invoice_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO settings (`key`, `value`)
SELECT 'sm_hybrid_batch_invoicing', '1'
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM settings WHERE `key` = 'sm_hybrid_batch_invoicing');

-- -----------------------------------------------------------------------------
-- PHASE 5 — Service category foundation
-- -----------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS sm_service_categories (
  id INT NOT NULL AUTO_INCREMENT,
  company_id INT NOT NULL,
  code VARCHAR(32) NOT NULL,
  name VARCHAR(100) NOT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  icon VARCHAR(50) NULL,
  sort_order INT NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_sm_svc_cat_company_code (company_id, code),
  KEY idx_sm_svc_cat_company_active (company_id, is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS sm_field_definitions (
  id INT NOT NULL AUTO_INCREMENT,
  service_category_id INT NOT NULL,
  field_key VARCHAR(64) NOT NULL,
  field_label VARCHAR(128) NOT NULL,
  field_type ENUM('text','number','date','select','boolean','textarea') NOT NULL DEFAULT 'text',
  options_json JSON NULL,
  is_required TINYINT(1) NOT NULL DEFAULT 0,
  show_on_booking TINYINT(1) NOT NULL DEFAULT 1,
  sort_order INT NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_sm_field_cat_key (service_category_id, field_key),
  KEY idx_sm_field_cat (service_category_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SET @has_mo_co = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'make_order' AND COLUMN_NAME = 'company_id');
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'make_order' AND COLUMN_NAME = 'service_category_id');
SET @sql = IF(@col_exists = 0,
  IF(@has_mo_co > 0,
    'ALTER TABLE make_order ADD COLUMN service_category_id INT NULL AFTER company_id',
    'ALTER TABLE make_order ADD COLUMN service_category_id INT NULL'),
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_services = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'services');
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'services' AND COLUMN_NAME = 'service_category_id');
SET @sql = IF(@has_services > 0 AND @col_exists = 0,
  'ALTER TABLE services ADD COLUMN service_category_id INT NULL AFTER id',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Default Cleaning category (company_id = 1; adjust if your primary company differs)
INSERT INTO sm_service_categories (company_id, code, name, is_active, icon, sort_order)
SELECT 1, 'cleaning', 'Cleaning', 1, '🧹', 1
FROM DUAL
WHERE NOT EXISTS (
  SELECT 1 FROM sm_service_categories WHERE company_id = 1 AND code = 'cleaning'
);

UPDATE make_order mo
SET service_category_id = (
  SELECT id FROM sm_service_categories WHERE company_id = 1 AND code = 'cleaning' LIMIT 1
)
WHERE mo.service_category_id IS NULL
  AND EXISTS (SELECT 1 FROM sm_service_categories WHERE company_id = 1 AND code = 'cleaning');

UPDATE services s
SET service_category_id = (
  SELECT id FROM sm_service_categories WHERE company_id = 1 AND code = 'cleaning' LIMIT 1
)
WHERE s.service_category_id IS NULL
  AND EXISTS (SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'services')
  AND EXISTS (SELECT 1 FROM sm_service_categories WHERE company_id = 1 AND code = 'cleaning');

-- -----------------------------------------------------------------------------
-- PHASE 5b — Category catalog + multi-service bookings
-- -----------------------------------------------------------------------------

SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'sm_service_categories' AND COLUMN_NAME = 'materials_rate_per_hour');
SET @sql = IF(@col_exists = 0,
  "ALTER TABLE sm_service_categories ADD COLUMN materials_rate_per_hour DECIMAL(10,2) NOT NULL DEFAULT 0.00 COMMENT 'Cleaning: AED per hour per worker for materials'",
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_sc = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'make_order' AND COLUMN_NAME = 'service_category_id');
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'make_order' AND COLUMN_NAME = 'booking_categories');
SET @sql = IF(@col_exists = 0,
  IF(@has_sc > 0,
    'ALTER TABLE make_order ADD COLUMN booking_categories JSON NULL AFTER service_category_id',
    'ALTER TABLE make_order ADD COLUMN booking_categories JSON NULL'),
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- -----------------------------------------------------------------------------
-- PHASE 7 — Expenses workflow, prepaid schedules, manual & recurring JVs
-- -----------------------------------------------------------------------------

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

SET @has_expenses = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'expenses');

SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'expenses' AND COLUMN_NAME = 'expense_type');
SET @sql = IF(@has_expenses > 0 AND @col_exists = 0,
  "ALTER TABLE expenses ADD COLUMN expense_type ENUM('operating','prepaid','payroll','other') NOT NULL DEFAULT 'operating' AFTER status",
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'expenses' AND COLUMN_NAME = 'prepaid_months');
SET @sql = IF(@has_expenses > 0 AND @col_exists = 0,
  'ALTER TABLE expenses ADD COLUMN prepaid_months INT NULL AFTER expense_type',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'expenses' AND COLUMN_NAME = 'prepaid_expense_account_no');
SET @sql = IF(@has_expenses > 0 AND @col_exists = 0,
  'ALTER TABLE expenses ADD COLUMN prepaid_expense_account_no VARCHAR(20) NULL AFTER prepaid_months',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'expenses' AND COLUMN_NAME = 'submitted_at');
SET @sql = IF(@has_expenses > 0 AND @col_exists = 0,
  'ALTER TABLE expenses ADD COLUMN submitted_at DATETIME NULL AFTER created_by',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'expenses' AND COLUMN_NAME = 'approved_by');
SET @sql = IF(@has_expenses > 0 AND @col_exists = 0,
  'ALTER TABLE expenses ADD COLUMN approved_by INT UNSIGNED NULL AFTER submitted_at',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'expenses' AND COLUMN_NAME = 'approved_at');
SET @sql = IF(@has_expenses > 0 AND @col_exists = 0,
  'ALTER TABLE expenses ADD COLUMN approved_at DATETIME NULL AFTER approved_by',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Extend expenses.status enum (ignore error if already extended)
SET @sql = IF(@has_expenses > 0,
  "ALTER TABLE expenses MODIFY COLUMN status ENUM('draft','pending_approval','posted','void') NOT NULL DEFAULT 'posted'",
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

INSERT INTO settings (`key`, `value`) SELECT 'sm_expense_requires_approval', '0' FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM settings WHERE `key` = 'sm_expense_requires_approval');
INSERT INTO settings (`key`, `value`) SELECT 'sm_prepaid_asset_account', '1240' FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM settings WHERE `key` = 'sm_prepaid_asset_account');
UPDATE settings SET `value` = '1240' WHERE `key` = 'sm_prepaid_asset_account' AND `value` = '1310';

SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'expenses' AND COLUMN_NAME = 'prepaid_asset_account_no');
SET @sql = IF(@col_exists = 0,
  'ALTER TABLE expenses ADD COLUMN prepaid_asset_account_no VARCHAR(20) NULL AFTER prepaid_expense_account_no',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
INSERT INTO settings (`key`, `value`) SELECT 'sm_phase7_enabled', '1' FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM settings WHERE `key` = 'sm_phase7_enabled');

-- -----------------------------------------------------------------------------
-- PHASE 8 — GM dashboard / reporting settings
-- -----------------------------------------------------------------------------

INSERT INTO settings (`key`, `value`) SELECT 'sm_phase8_enabled', '1' FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM settings WHERE `key` = 'sm_phase8_enabled');
INSERT INTO settings (`key`, `value`) SELECT 'sm_gm_dashboard_default_days', '30' FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM settings WHERE `key` = 'sm_gm_dashboard_default_days');

-- -----------------------------------------------------------------------------
-- Go-live — health check (accept legacy orphan invoices)
-- -----------------------------------------------------------------------------

INSERT INTO settings (`key`, `value`) SELECT 'sm_health_exclude_orphan_invoices', '1' FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM settings WHERE `key` = 'sm_health_exclude_orphan_invoices');

SET FOREIGN_KEY_CHECKS = 1;

SELECT 'Service Management phases 0–8 applied successfully.' AS status;
