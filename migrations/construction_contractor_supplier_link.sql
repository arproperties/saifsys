-- Construction: Contractor ↔ Supplier Business Partner link
-- BR: docs/business-rules/CO_CONTRACTOR_SUPPLIER_LINK.md
-- Safe to re-run (IF NOT EXISTS / column checks via procedure-free pattern).

-- 1) Link column on contractors
SET @col_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'co_contractors'
      AND COLUMN_NAME = 'supplier_id'
);
SET @sql := IF(@col_exists = 0,
    'ALTER TABLE co_contractors
        ADD COLUMN supplier_id INT NULL DEFAULT NULL AFTER tax_number,
        ADD INDEX idx_co_contractors_supplier (company_id, supplier_id)',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- FK to co_suppliers (ignore if already present)
SET @fk_exists := (
    SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'co_contractors'
      AND CONSTRAINT_NAME = 'fk_co_contractors_supplier'
);
SET @sql := IF(@fk_exists = 0,
    'ALTER TABLE co_contractors
        ADD CONSTRAINT fk_co_contractors_supplier
        FOREIGN KEY (supplier_id) REFERENCES co_suppliers(id)
        ON DELETE SET NULL ON UPDATE CASCADE',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Unique: one contractor per supplier per company (MySQL allows multiple NULLs)
SET @uq_exists := (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'co_contractors'
      AND INDEX_NAME = 'uq_co_contractors_company_supplier'
);
SET @sql := IF(@uq_exists = 0,
    'ALTER TABLE co_contractors
        ADD UNIQUE INDEX uq_co_contractors_company_supplier (company_id, supplier_id)',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 2) Retirement audit log
CREATE TABLE IF NOT EXISTS co_contractor_payment_retirement_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    company_id INT NOT NULL,
    contractor_payment_id INT NOT NULL,
    project_contractor_id INT NULL,
    payment_date DATE NULL,
    amount DECIMAL(15,2) NULL,
    net_paid DECIMAL(15,2) NULL,
    retention_held DECIMAL(15,2) NULL,
    journal_id INT NULL,
    reverse_journal_id INT NULL,
    verified_against_note VARCHAR(500) NULL,
    retirement_mode VARCHAR(40) NOT NULL DEFAULT 'verified_match',
    retired_by INT NULL,
    retired_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_co_cp_retire_company (company_id),
    INDEX idx_co_cp_retire_payment (contractor_payment_id),
    INDEX idx_co_cp_retire_mode (company_id, retirement_mode)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
