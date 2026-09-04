-- ============================================================================
-- Enhance Contract Template System - HTML Templates, Ejari, Signatures
-- ============================================================================
-- This migration adds:
-- 1. HTML template storage (template_html field)
-- 2. Ejari fields to leases
-- 3. Signature and stamp storage
-- 4. Contract document storage
-- ============================================================================

-- Add HTML template field to contract_templates
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() 
    AND TABLE_NAME = 're_contract_templates' 
    AND COLUMN_NAME = 'template_html');
SET @sql = IF(@col_exists = 0, 
    'ALTER TABLE `re_contract_templates` ADD COLUMN `template_html` LONGTEXT DEFAULT NULL COMMENT \'Full HTML template with CSS for PDF generation\' AFTER `template_content`', 
    'SELECT "Column template_html already exists" AS message');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Add template_format field (text/html)
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() 
    AND TABLE_NAME = 're_contract_templates' 
    AND COLUMN_NAME = 'template_format');
SET @sql = IF(@col_exists = 0, 
    'ALTER TABLE `re_contract_templates` ADD COLUMN `template_format` ENUM(\'text\', \'html\') NOT NULL DEFAULT \'text\' AFTER `template_html`', 
    'SELECT "Column template_format already exists" AS message');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Add Ejari fields to leases
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() 
    AND TABLE_NAME = 're_leases' 
    AND COLUMN_NAME = 'ejari_registration_number');
SET @sql = IF(@col_exists = 0, 
    'ALTER TABLE `re_leases` ADD COLUMN `ejari_registration_number` VARCHAR(100) DEFAULT NULL COMMENT \'Ejari Registration Number\' AFTER `template_id`', 
    'SELECT "Column ejari_registration_number already exists" AS message');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() 
    AND TABLE_NAME = 're_leases' 
    AND COLUMN_NAME = 'ejari_issue_date');
SET @sql = IF(@col_exists = 0, 
    'ALTER TABLE `re_leases` ADD COLUMN `ejari_issue_date` DATE DEFAULT NULL COMMENT \'Ejari Issue Date\' AFTER `ejari_registration_number`', 
    'SELECT "Column ejari_issue_date already exists" AS message');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() 
    AND TABLE_NAME = 're_leases' 
    AND COLUMN_NAME = 'ejari_property_code');
SET @sql = IF(@col_exists = 0, 
    'ALTER TABLE `re_leases` ADD COLUMN `ejari_property_code` VARCHAR(100) DEFAULT NULL COMMENT \'Ejari Property Code\' AFTER `ejari_issue_date`', 
    'SELECT "Column ejari_property_code already exists" AS message');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() 
    AND TABLE_NAME = 're_leases' 
    AND COLUMN_NAME = 'ejari_document_path');
SET @sql = IF(@col_exists = 0, 
    'ALTER TABLE `re_leases` ADD COLUMN `ejari_document_path` VARCHAR(500) DEFAULT NULL COMMENT \'Path to uploaded Ejari PDF document\' AFTER `ejari_property_code`', 
    'SELECT "Column ejari_document_path already exists" AS message');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Add signature fields to leases
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() 
    AND TABLE_NAME = 're_leases' 
    AND COLUMN_NAME = 'landlord_signature_path');
SET @sql = IF(@col_exists = 0, 
    'ALTER TABLE `re_leases` ADD COLUMN `landlord_signature_path` VARCHAR(500) DEFAULT NULL COMMENT \'Path to landlord signature image\' AFTER `ejari_document_path`', 
    'SELECT "Column landlord_signature_path already exists" AS message');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() 
    AND TABLE_NAME = 're_leases' 
    AND COLUMN_NAME = 'tenant_signature_path');
SET @sql = IF(@col_exists = 0, 
    'ALTER TABLE `re_leases` ADD COLUMN `tenant_signature_path` VARCHAR(500) DEFAULT NULL COMMENT \'Path to tenant signature image\' AFTER `landlord_signature_path`', 
    'SELECT "Column tenant_signature_path already exists" AS message');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() 
    AND TABLE_NAME = 're_leases' 
    AND COLUMN_NAME = 'company_stamp_path');
SET @sql = IF(@col_exists = 0, 
    'ALTER TABLE `re_leases` ADD COLUMN `company_stamp_path` VARCHAR(500) DEFAULT NULL COMMENT \'Path to company stamp/seal image\' AFTER `tenant_signature_path`', 
    'SELECT "Column company_stamp_path already exists" AS message');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Add generated contract PDF path
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() 
    AND TABLE_NAME = 're_leases' 
    AND COLUMN_NAME = 'generated_contract_path');
SET @sql = IF(@col_exists = 0, 
    'ALTER TABLE `re_leases` ADD COLUMN `generated_contract_path` VARCHAR(500) DEFAULT NULL COMMENT \'Path to generated contract PDF\' AFTER `company_stamp_path`', 
    'SELECT "Column generated_contract_path already exists" AS message');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() 
    AND TABLE_NAME = 're_leases' 
    AND COLUMN_NAME = 'contract_generated_at');
SET @sql = IF(@col_exists = 0, 
    'ALTER TABLE `re_leases` ADD COLUMN `contract_generated_at` DATETIME DEFAULT NULL COMMENT \'Timestamp when contract PDF was generated\' AFTER `generated_contract_path`', 
    'SELECT "Column contract_generated_at already exists" AS message');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SELECT 'Contract Template System enhancement completed successfully!' AS message;

