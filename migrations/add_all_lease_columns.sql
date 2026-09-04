-- ============================================================================
-- Add All Lease Enhancement Columns
-- ============================================================================
-- This migration combines all lease enhancement migrations:
-- 1. Lease fees (chiller, ejari, admin, commission)
-- 2. Additional parking and store fields
-- 3. Annual rent and installments
-- 4. Grace period and renewal terms
-- 5. Template ID
-- 6. Ejari fields
-- 7. Signature and stamp fields
-- ============================================================================
-- Safe to run multiple times (checks if columns exist before adding)
-- ============================================================================

-- ============================================================================
-- PART 1: LEASE FEES
-- ============================================================================

-- Add chiller_fees column
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() 
    AND TABLE_NAME = 're_leases' 
    AND COLUMN_NAME = 'chiller_fees');
SET @sql = IF(@col_exists = 0, 
    'ALTER TABLE `re_leases` ADD COLUMN `chiller_fees` DECIMAL(12,2) DEFAULT 0.00 COMMENT ''Chiller fees amount in AED'' AFTER `security_deposit`', 
    'SELECT "Column chiller_fees already exists" AS message');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Add ejari_fees column
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() 
    AND TABLE_NAME = 're_leases' 
    AND COLUMN_NAME = 'ejari_fees');
SET @sql = IF(@col_exists = 0, 
    'ALTER TABLE `re_leases` ADD COLUMN `ejari_fees` DECIMAL(12,2) DEFAULT 0.00 COMMENT ''Ejari fees amount in AED'' AFTER `chiller_fees`', 
    'SELECT "Column ejari_fees already exists" AS message');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Add admin_fees column
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() 
    AND TABLE_NAME = 're_leases' 
    AND COLUMN_NAME = 'admin_fees');
SET @sql = IF(@col_exists = 0, 
    'ALTER TABLE `re_leases` ADD COLUMN `admin_fees` DECIMAL(12,2) DEFAULT 0.00 COMMENT ''Admin fees amount in AED'' AFTER `ejari_fees`', 
    'SELECT "Column admin_fees already exists" AS message');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Add commission_fees column
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() 
    AND TABLE_NAME = 're_leases' 
    AND COLUMN_NAME = 'commission_fees');
SET @sql = IF(@col_exists = 0, 
    'ALTER TABLE `re_leases` ADD COLUMN `commission_fees` DECIMAL(12,2) DEFAULT 0.00 COMMENT ''Commission fees amount in AED'' AFTER `admin_fees`', 
    'SELECT "Column commission_fees already exists" AS message');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Add add_fees_to_first_installment column
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() 
    AND TABLE_NAME = 're_leases' 
    AND COLUMN_NAME = 'add_fees_to_first_installment');
SET @sql = IF(@col_exists = 0, 
    'ALTER TABLE `re_leases` ADD COLUMN `add_fees_to_first_installment` TINYINT(1) DEFAULT 1 COMMENT ''If 1, add all fees to 1st installment; if 0, create separate installment'' AFTER `commission_fees`', 
    'SELECT "Column add_fees_to_first_installment already exists" AS message');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ============================================================================
-- PART 2: ANNUAL RENT AND INSTALLMENTS
-- ============================================================================

-- Add annual_rent column
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() 
    AND TABLE_NAME = 're_leases' 
    AND COLUMN_NAME = 'annual_rent');
SET @sql = IF(@col_exists = 0, 
    'ALTER TABLE `re_leases` ADD COLUMN `annual_rent` DECIMAL(12,2) DEFAULT NULL COMMENT ''Annual rent amount in AED'' AFTER `monthly_rent`', 
    'SELECT "Column annual_rent already exists" AS message');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Add number_of_installments column
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() 
    AND TABLE_NAME = 're_leases' 
    AND COLUMN_NAME = 'number_of_installments');
SET @sql = IF(@col_exists = 0, 
    'ALTER TABLE `re_leases` ADD COLUMN `number_of_installments` INT(3) DEFAULT 12 COMMENT ''Number of payment installments per year'' AFTER `payment_day`', 
    'SELECT "Column number_of_installments already exists" AS message');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ============================================================================
-- PART 3: ADDITIONAL PARKING AND STORE
-- ============================================================================

-- Add additional parking fields
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() 
    AND TABLE_NAME = 're_leases' 
    AND COLUMN_NAME = 'has_additional_parking');
SET @sql = IF(@col_exists = 0, 
    'ALTER TABLE `re_leases` ADD COLUMN `has_additional_parking` TINYINT(1) DEFAULT 0 COMMENT ''Tenant has additional parking'' AFTER `add_fees_to_first_installment`', 
    'SELECT "Column has_additional_parking already exists" AS message');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() 
    AND TABLE_NAME = 're_leases' 
    AND COLUMN_NAME = 'additional_parking_fee');
SET @sql = IF(@col_exists = 0, 
    'ALTER TABLE `re_leases` ADD COLUMN `additional_parking_fee` DECIMAL(12,2) DEFAULT 0.00 COMMENT ''Monthly fee for additional parking'' AFTER `has_additional_parking`', 
    'SELECT "Column additional_parking_fee already exists" AS message');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() 
    AND TABLE_NAME = 're_leases' 
    AND COLUMN_NAME = 'additional_parking_start_date');
SET @sql = IF(@col_exists = 0, 
    'ALTER TABLE `re_leases` ADD COLUMN `additional_parking_start_date` DATE DEFAULT NULL COMMENT ''Start date for additional parking'' AFTER `additional_parking_fee`', 
    'SELECT "Column additional_parking_start_date already exists" AS message');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() 
    AND TABLE_NAME = 're_leases' 
    AND COLUMN_NAME = 'additional_parking_end_date');
SET @sql = IF(@col_exists = 0, 
    'ALTER TABLE `re_leases` ADD COLUMN `additional_parking_end_date` DATE DEFAULT NULL COMMENT ''End date for additional parking'' AFTER `additional_parking_start_date`', 
    'SELECT "Column additional_parking_end_date already exists" AS message');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Add additional store fields
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() 
    AND TABLE_NAME = 're_leases' 
    AND COLUMN_NAME = 'has_additional_store');
SET @sql = IF(@col_exists = 0, 
    'ALTER TABLE `re_leases` ADD COLUMN `has_additional_store` TINYINT(1) DEFAULT 0 COMMENT ''Tenant has additional store'' AFTER `additional_parking_end_date`', 
    'SELECT "Column has_additional_store already exists" AS message');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() 
    AND TABLE_NAME = 're_leases' 
    AND COLUMN_NAME = 'additional_store_fee');
SET @sql = IF(@col_exists = 0, 
    'ALTER TABLE `re_leases` ADD COLUMN `additional_store_fee` DECIMAL(12,2) DEFAULT 0.00 COMMENT ''Monthly fee for additional store'' AFTER `has_additional_store`', 
    'SELECT "Column additional_store_fee already exists" AS message');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() 
    AND TABLE_NAME = 're_leases' 
    AND COLUMN_NAME = 'additional_store_start_date');
SET @sql = IF(@col_exists = 0, 
    'ALTER TABLE `re_leases` ADD COLUMN `additional_store_start_date` DATE DEFAULT NULL COMMENT ''Start date for additional store'' AFTER `additional_store_fee`', 
    'SELECT "Column additional_store_start_date already exists" AS message');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() 
    AND TABLE_NAME = 're_leases' 
    AND COLUMN_NAME = 'additional_store_end_date');
SET @sql = IF(@col_exists = 0, 
    'ALTER TABLE `re_leases` ADD COLUMN `additional_store_end_date` DATE DEFAULT NULL COMMENT ''End date for additional store'' AFTER `additional_store_start_date`', 
    'SELECT "Column additional_store_end_date already exists" AS message');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ============================================================================
-- PART 4: GRACE PERIOD AND RENEWAL TERMS
-- ============================================================================

-- Add grace_period_days column
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() 
    AND TABLE_NAME = 're_leases' 
    AND COLUMN_NAME = 'grace_period_days');
SET @sql = IF(@col_exists = 0, 
    'ALTER TABLE `re_leases` ADD COLUMN `grace_period_days` INT(3) DEFAULT 0 COMMENT ''Days after due date before penalty applies'' AFTER `payment_day`', 
    'SELECT "Column grace_period_days already exists" AS message');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Add renewal_terms column
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() 
    AND TABLE_NAME = 're_leases' 
    AND COLUMN_NAME = 'renewal_terms');
SET @sql = IF(@col_exists = 0, 
    'ALTER TABLE `re_leases` ADD COLUMN `renewal_terms` TEXT DEFAULT NULL COMMENT ''Renewal conditions and terms'' AFTER `notes`', 
    'SELECT "Column renewal_terms already exists" AS message');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Add template_id column
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() 
    AND TABLE_NAME = 're_leases' 
    AND COLUMN_NAME = 'template_id');
SET @sql = IF(@col_exists = 0, 
    'ALTER TABLE `re_leases` ADD COLUMN `template_id` INT(11) DEFAULT NULL AFTER `renewal_terms`', 
    'SELECT "Column template_id already exists" AS message');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ============================================================================
-- PART 5: EJARI FIELDS
-- ============================================================================

-- Add ejari_registration_number column
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() 
    AND TABLE_NAME = 're_leases' 
    AND COLUMN_NAME = 'ejari_registration_number');
SET @sql = IF(@col_exists = 0, 
    'ALTER TABLE `re_leases` ADD COLUMN `ejari_registration_number` VARCHAR(100) DEFAULT NULL COMMENT ''Ejari Registration Number'' AFTER `template_id`', 
    'SELECT "Column ejari_registration_number already exists" AS message');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Add ejari_issue_date column
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() 
    AND TABLE_NAME = 're_leases' 
    AND COLUMN_NAME = 'ejari_issue_date');
SET @sql = IF(@col_exists = 0, 
    'ALTER TABLE `re_leases` ADD COLUMN `ejari_issue_date` DATE DEFAULT NULL COMMENT ''Ejari Issue Date'' AFTER `ejari_registration_number`', 
    'SELECT "Column ejari_issue_date already exists" AS message');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Add ejari_property_code column
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() 
    AND TABLE_NAME = 're_leases' 
    AND COLUMN_NAME = 'ejari_property_code');
SET @sql = IF(@col_exists = 0, 
    'ALTER TABLE `re_leases` ADD COLUMN `ejari_property_code` VARCHAR(100) DEFAULT NULL COMMENT ''Ejari Property Code'' AFTER `ejari_issue_date`', 
    'SELECT "Column ejari_property_code already exists" AS message');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Add ejari_document_path column
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() 
    AND TABLE_NAME = 're_leases' 
    AND COLUMN_NAME = 'ejari_document_path');
SET @sql = IF(@col_exists = 0, 
    'ALTER TABLE `re_leases` ADD COLUMN `ejari_document_path` VARCHAR(500) DEFAULT NULL COMMENT ''Path to uploaded Ejari PDF document'' AFTER `ejari_property_code`', 
    'SELECT "Column ejari_document_path already exists" AS message');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ============================================================================
-- PART 6: SIGNATURE AND STAMP FIELDS
-- ============================================================================

-- Add landlord_signature_path column
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() 
    AND TABLE_NAME = 're_leases' 
    AND COLUMN_NAME = 'landlord_signature_path');
SET @sql = IF(@col_exists = 0, 
    'ALTER TABLE `re_leases` ADD COLUMN `landlord_signature_path` VARCHAR(500) DEFAULT NULL COMMENT ''Path to landlord signature image'' AFTER `ejari_document_path`', 
    'SELECT "Column landlord_signature_path already exists" AS message');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Add tenant_signature_path column
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() 
    AND TABLE_NAME = 're_leases' 
    AND COLUMN_NAME = 'tenant_signature_path');
SET @sql = IF(@col_exists = 0, 
    'ALTER TABLE `re_leases` ADD COLUMN `tenant_signature_path` VARCHAR(500) DEFAULT NULL COMMENT ''Path to tenant signature image'' AFTER `landlord_signature_path`', 
    'SELECT "Column tenant_signature_path already exists" AS message');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Add company_stamp_path column
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() 
    AND TABLE_NAME = 're_leases' 
    AND COLUMN_NAME = 'company_stamp_path');
SET @sql = IF(@col_exists = 0, 
    'ALTER TABLE `re_leases` ADD COLUMN `company_stamp_path` VARCHAR(500) DEFAULT NULL COMMENT ''Path to company stamp/seal image'' AFTER `tenant_signature_path`', 
    'SELECT "Column company_stamp_path already exists" AS message');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ============================================================================
-- COMPLETION MESSAGE
-- ============================================================================

SELECT 'All lease enhancement columns added successfully!' AS message;
