-- ============================================================================
-- MULTI-COMPANY ERP INTEGRATION - Add company_id to Finance Tables
-- ============================================================================
-- This migration adds company_id to finance/accounting tables
-- Database: bestsys
-- Safe to run multiple times
-- ============================================================================

SET FOREIGN_KEY_CHECKS = 0;
SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";

-- ============================================================================
-- PART 1: ADD COMPANY_ID TO INVOICES TABLE
-- ============================================================================

SET @col_exists = (
    SELECT COUNT(*) 
    FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() 
    AND TABLE_NAME = 'invoices' 
    AND COLUMN_NAME = 'company_id'
);

SET @sql = IF(@col_exists = 0,
    'ALTER TABLE `invoices` 
     ADD COLUMN `company_id` INT(11) NOT NULL DEFAULT 1 AFTER `id`,
     ADD FOREIGN KEY (`company_id`) REFERENCES `companies`(`id`) ON DELETE RESTRICT,
     ADD KEY `idx_company_id` (`company_id`)',
    'SELECT "company_id column already exists in invoices table" AS message'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Set company_id from client for existing invoices
UPDATE `invoices` i
SET `company_id` = COALESCE(
    (SELECT `company_id` FROM `client` WHERE `id` = i.`client_id` LIMIT 1),
    1
)
WHERE `company_id` IS NULL OR `company_id` = 0;

-- ============================================================================
-- PART 2: ADD COMPANY_ID TO RECEIPTS TABLE
-- ============================================================================

SET @col_exists = (
    SELECT COUNT(*) 
    FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() 
    AND TABLE_NAME = 'receipts' 
    AND COLUMN_NAME = 'company_id'
);

SET @sql = IF(@col_exists = 0,
    'ALTER TABLE `receipts` 
     ADD COLUMN `company_id` INT(11) NOT NULL DEFAULT 1 AFTER `id`,
     ADD FOREIGN KEY (`company_id`) REFERENCES `companies`(`id`) ON DELETE RESTRICT,
     ADD KEY `idx_company_id` (`company_id`)',
    'SELECT "company_id column already exists in receipts table" AS message'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Set company_id from client for existing receipts (receipts link to invoices via receipt_allocations)
UPDATE `receipts` r
SET `company_id` = COALESCE(
    (SELECT `company_id` FROM `client` WHERE `id` = r.`client_id` LIMIT 1),
    1
)
WHERE `company_id` IS NULL OR `company_id` = 0;

-- ============================================================================
-- PART 3: ADD COMPANY_ID TO EXPENSES TABLE
-- ============================================================================

SET @col_exists = (
    SELECT COUNT(*) 
    FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() 
    AND TABLE_NAME = 'expenses' 
    AND COLUMN_NAME = 'company_id'
);

SET @sql = IF(@col_exists = 0,
    'ALTER TABLE `expenses` 
     ADD COLUMN `company_id` INT(11) NOT NULL DEFAULT 1 AFTER `id`,
     ADD FOREIGN KEY (`company_id`) REFERENCES `companies`(`id`) ON DELETE RESTRICT,
     ADD KEY `idx_company_id` (`company_id`)',
    'SELECT "company_id column already exists in expenses table" AS message'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Set all existing expenses to company_id=1
UPDATE `expenses` SET `company_id` = 1 WHERE `company_id` IS NULL OR `company_id` = 0;

-- ============================================================================
-- PART 4: ADD COMPANY_ID TO VENDORS TABLE
-- ============================================================================

SET @col_exists = (
    SELECT COUNT(*) 
    FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() 
    AND TABLE_NAME = 'vendors' 
    AND COLUMN_NAME = 'company_id'
);

SET @sql = IF(@col_exists = 0,
    'ALTER TABLE `vendors` 
     ADD COLUMN `company_id` INT(11) NOT NULL DEFAULT 1 AFTER `id`,
     ADD FOREIGN KEY (`company_id`) REFERENCES `companies`(`id`) ON DELETE RESTRICT,
     ADD KEY `idx_company_id` (`company_id`)',
    'SELECT "company_id column already exists in vendors table" AS message'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Set all existing vendors to company_id=1
UPDATE `vendors` SET `company_id` = 1 WHERE `company_id` IS NULL OR `company_id` = 0;

-- ============================================================================
-- PART 5: ADD COMPANY_ID TO CHART_OF_ACCOUNTS TABLE
-- ============================================================================

SET @col_exists = (
    SELECT COUNT(*) 
    FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() 
    AND TABLE_NAME = 'chart_of_accounts' 
    AND COLUMN_NAME = 'company_id'
);

SET @sql = IF(@col_exists = 0,
    'ALTER TABLE `chart_of_accounts` 
     ADD COLUMN `company_id` INT(11) NOT NULL DEFAULT 1 AFTER `id`,
     ADD FOREIGN KEY (`company_id`) REFERENCES `companies`(`id`) ON DELETE RESTRICT,
     ADD KEY `idx_company_id` (`company_id`)',
    'SELECT "company_id column already exists in chart_of_accounts table" AS message'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Set all existing chart of accounts to company_id=1
UPDATE `chart_of_accounts` SET `company_id` = 1 WHERE `company_id` IS NULL OR `company_id` = 0;

-- ============================================================================
-- PART 6: ADD COMPANY_ID TO GL_JOURNALS TABLE
-- ============================================================================

SET @col_exists = (
    SELECT COUNT(*) 
    FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() 
    AND TABLE_NAME = 'gl_journals' 
    AND COLUMN_NAME = 'company_id'
);

SET @sql = IF(@col_exists = 0,
    'ALTER TABLE `gl_journals` 
     ADD COLUMN `company_id` INT(11) NOT NULL DEFAULT 1 AFTER `id`,
     ADD FOREIGN KEY (`company_id`) REFERENCES `companies`(`id`) ON DELETE RESTRICT,
     ADD KEY `idx_company_id` (`company_id`)',
    'SELECT "company_id column already exists in gl_journals table" AS message'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Set all existing GL journals to company_id=1
UPDATE `gl_journals` SET `company_id` = 1 WHERE `company_id` IS NULL OR `company_id` = 0;

-- ============================================================================
-- PART 7: ADD COMPANY_ID TO CREDIT_NOTES TABLE (if exists)
-- ============================================================================

SET @table_exists = (
    SELECT COUNT(*) 
    FROM INFORMATION_SCHEMA.TABLES 
    WHERE TABLE_SCHEMA = DATABASE() 
    AND TABLE_NAME = 'credit_notes'
);

SET @col_exists = (
    SELECT COUNT(*) 
    FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() 
    AND TABLE_NAME = 'credit_notes' 
    AND COLUMN_NAME = 'company_id'
);

SET @sql = IF(@table_exists > 0 AND @col_exists = 0,
    'ALTER TABLE `credit_notes` 
     ADD COLUMN `company_id` INT(11) NOT NULL DEFAULT 1 AFTER `id`,
     ADD FOREIGN KEY (`company_id`) REFERENCES `companies`(`id`) ON DELETE RESTRICT,
     ADD KEY `idx_company_id` (`company_id`)',
    'SELECT "credit_notes table does not exist or company_id already exists" AS message'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Set company_id from invoice for existing credit notes
UPDATE `credit_notes` cn
SET `company_id` = COALESCE(
    (SELECT `company_id` FROM `invoices` WHERE `id` = cn.`invoice_id` LIMIT 1),
    1
)
WHERE EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_NAME = 'credit_notes')
AND (`company_id` IS NULL OR `company_id` = 0);

-- ============================================================================
-- PART 8: ADD COMPANY_ID TO REFUNDS TABLE (if exists)
-- ============================================================================

SET @table_exists = (
    SELECT COUNT(*) 
    FROM INFORMATION_SCHEMA.TABLES 
    WHERE TABLE_SCHEMA = DATABASE() 
    AND TABLE_NAME = 'refunds'
);

SET @col_exists = (
    SELECT COUNT(*) 
    FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() 
    AND TABLE_NAME = 'refunds' 
    AND COLUMN_NAME = 'company_id'
);

SET @sql = IF(@table_exists > 0 AND @col_exists = 0,
    'ALTER TABLE `refunds` 
     ADD COLUMN `company_id` INT(11) NOT NULL DEFAULT 1 AFTER `id`,
     ADD FOREIGN KEY (`company_id`) REFERENCES `companies`(`id`) ON DELETE RESTRICT,
     ADD KEY `idx_company_id` (`company_id`)',
    'SELECT "refunds table does not exist or company_id already exists" AS message'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Set company_id for existing refunds (refunds link to credit_notes, which link to invoices)
SET @refund_table_exists = (
    SELECT COUNT(*) 
    FROM INFORMATION_SCHEMA.TABLES 
    WHERE TABLE_SCHEMA = DATABASE() 
    AND TABLE_NAME = 'refunds'
);

SET @refund_company_col = (
    SELECT COUNT(*) 
    FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() 
    AND TABLE_NAME = 'refunds' 
    AND COLUMN_NAME = 'company_id'
);

SET @sql = IF(@refund_table_exists > 0 AND @refund_company_col > 0,
    'UPDATE `refunds` rf
     SET `company_id` = COALESCE(
         (SELECT i.company_id FROM credit_notes cn 
          JOIN invoices i ON i.id = cn.invoice_id 
          WHERE cn.id = rf.credit_note_id LIMIT 1),
         1
     )
     WHERE `company_id` IS NULL OR `company_id` = 0',
    'SELECT "Refunds table/column check" AS message'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ============================================================================
-- PART 9: ADD COMPANY_ID TO CLIENT_DOCUMENTS TABLE
-- ============================================================================

SET @col_exists = (
    SELECT COUNT(*) 
    FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() 
    AND TABLE_NAME = 'client_documents' 
    AND COLUMN_NAME = 'company_id'
);

SET @sql = IF(@col_exists = 0,
    'ALTER TABLE `client_documents` 
     ADD COLUMN `company_id` INT(11) NOT NULL DEFAULT 1 AFTER `id`,
     ADD FOREIGN KEY (`company_id`) REFERENCES `companies`(`id`) ON DELETE RESTRICT,
     ADD KEY `idx_company_id` (`company_id`)',
    'SELECT "company_id column already exists in client_documents table" AS message'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Set company_id from client for existing documents
UPDATE `client_documents` cd
SET `company_id` = COALESCE(
    (SELECT `company_id` FROM `client` WHERE `id` = cd.`client_id` LIMIT 1),
    1
)
WHERE `company_id` IS NULL OR `company_id` = 0;

SET FOREIGN_KEY_CHECKS = 1;

-- ============================================================================
-- MIGRATION COMPLETE - Finance Tables
-- ============================================================================

