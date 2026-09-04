-- ============================================================================
-- DATA MIGRATION: Set company_id=1 for all existing records
-- ============================================================================
-- This script sets company_id=1 for all existing records in tables that have
-- been extended with company_id column. Run this AFTER all schema migrations.
-- Database: bestsys
-- ============================================================================

SET FOREIGN_KEY_CHECKS = 0;
SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";

-- Ensure company_id=1 exists
INSERT IGNORE INTO companies (id, name, code, business_type, is_active) 
VALUES (1, 'Best Maids Buildings Cleaning', 'BMC', 'cleaning', 1);

-- Helper function to update table if column exists
-- Core tables
SET @has_col = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'user' AND COLUMN_NAME = 'company_id');
SET @sql = IF(@has_col > 0, 'UPDATE `user` SET `company_id` = 1 WHERE `company_id` IS NULL OR `company_id` = 0', 'SELECT "user: no company_id" AS msg');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_col = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'employees' AND COLUMN_NAME = 'company_id');
SET @sql = IF(@has_col > 0, 'UPDATE `employees` SET `company_id` = 1 WHERE `company_id` IS NULL OR `company_id` = 0', 'SELECT "employees: no company_id" AS msg');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_col = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'client' AND COLUMN_NAME = 'company_id');
SET @sql = IF(@has_col > 0, 'UPDATE `client` SET `company_id` = 1 WHERE `company_id` IS NULL OR `company_id` = 0', 'SELECT "client: no company_id" AS msg');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_col = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'make_order' AND COLUMN_NAME = 'company_id');
SET @sql = IF(@has_col > 0, 'UPDATE `make_order` SET `company_id` = 1 WHERE `company_id` IS NULL OR `company_id` = 0', 'SELECT "make_order: no company_id" AS msg');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_col = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'services' AND COLUMN_NAME = 'company_id');
SET @sql = IF(@has_col > 0, 'UPDATE `services` SET `company_id` = 1 WHERE `company_id` IS NULL OR `company_id` = 0', 'SELECT "services: no company_id" AS msg');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_col = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'service_categories' AND COLUMN_NAME = 'company_id');
SET @sql = IF(@has_col > 0, 'UPDATE `service_categories` SET `company_id` = 1 WHERE `company_id` IS NULL OR `company_id` = 0', 'SELECT "service_categories: no company_id" AS msg');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- HR tables
SET @has_col = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'attendance' AND COLUMN_NAME = 'company_id');
SET @sql = IF(@has_col > 0, 'UPDATE `attendance` SET `company_id` = 1 WHERE `company_id` IS NULL OR `company_id` = 0', 'SELECT "attendance: no company_id" AS msg');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_col = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'employee_documents' AND COLUMN_NAME = 'company_id');
SET @sql = IF(@has_col > 0, 'UPDATE `employee_documents` SET `company_id` = 1 WHERE `company_id` IS NULL OR `company_id` = 0', 'SELECT "employee_documents: no company_id" AS msg');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_col = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'leave_requests' AND COLUMN_NAME = 'company_id');
SET @sql = IF(@has_col > 0, 'UPDATE `leave_requests` SET `company_id` = 1 WHERE `company_id` IS NULL OR `company_id` = 0', 'SELECT "leave_requests: no company_id" AS msg');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_col = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'payroll_runs' AND COLUMN_NAME = 'company_id');
SET @sql = IF(@has_col > 0, 'UPDATE `payroll_runs` SET `company_id` = 1 WHERE `company_id` IS NULL OR `company_id` = 0', 'SELECT "payroll_runs: no company_id" AS msg');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Finance tables
SET @has_col = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'invoices' AND COLUMN_NAME = 'company_id');
SET @sql = IF(@has_col > 0, 'UPDATE `invoices` SET `company_id` = 1 WHERE `company_id` IS NULL OR `company_id` = 0', 'SELECT "invoices: no company_id" AS msg');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_col = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'receipts' AND COLUMN_NAME = 'company_id');
SET @sql = IF(@has_col > 0, 'UPDATE `receipts` SET `company_id` = 1 WHERE `company_id` IS NULL OR `company_id` = 0', 'SELECT "receipts: no company_id" AS msg');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_col = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'expenses' AND COLUMN_NAME = 'company_id');
SET @sql = IF(@has_col > 0, 'UPDATE `expenses` SET `company_id` = 1 WHERE `company_id` IS NULL OR `company_id` = 0', 'SELECT "expenses: no company_id" AS msg');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_col = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'vendors' AND COLUMN_NAME = 'company_id');
SET @sql = IF(@has_col > 0, 'UPDATE `vendors` SET `company_id` = 1 WHERE `company_id` IS NULL OR `company_id` = 0', 'SELECT "vendors: no company_id" AS msg');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_col = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'chart_of_accounts' AND COLUMN_NAME = 'company_id');
SET @sql = IF(@has_col > 0, 'UPDATE `chart_of_accounts` SET `company_id` = 1 WHERE `company_id` IS NULL OR `company_id` = 0', 'SELECT "chart_of_accounts: no company_id" AS msg');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_col = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gl_journals' AND COLUMN_NAME = 'company_id');
SET @sql = IF(@has_col > 0, 'UPDATE `gl_journals` SET `company_id` = 1 WHERE `company_id` IS NULL OR `company_id` = 0', 'SELECT "gl_journals: no company_id" AS msg');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Document tables
SET @has_col = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'client_documents' AND COLUMN_NAME = 'company_id');
SET @sql = IF(@has_col > 0, 'UPDATE `client_documents` SET `company_id` = 1 WHERE `company_id` IS NULL OR `company_id` = 0', 'SELECT "client_documents: no company_id" AS msg');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Update company_settings to link to company_id=1
SET @has_col = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'company_settings' AND COLUMN_NAME = 'company_id');
SET @sql = IF(@has_col > 0, 'UPDATE `company_settings` SET `company_id` = 1 WHERE `company_id` IS NULL OR `company_id` = 0', 'SELECT "company_settings: no company_id" AS msg');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET FOREIGN_KEY_CHECKS = 1;

-- ============================================================================
-- MIGRATION COMPLETE
-- ============================================================================
-- All existing data is now associated with company_id=1
-- ============================================================================
