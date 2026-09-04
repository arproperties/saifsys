-- Phase 1 MVP Enhancement: Compliance Tracking System
-- Document expiry alerts, missing documents checklist, legal status per unit, Ejari tracking

-- ============================================================================
-- 1. Document Types (Configuration)
-- ============================================================================
CREATE TABLE IF NOT EXISTS `re_document_types` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `company_id` INT(11) NOT NULL,
  `document_type_name` VARCHAR(255) NOT NULL,
  `document_type_code` VARCHAR(50) NOT NULL,
  `description` TEXT DEFAULT NULL,
  `is_required` TINYINT(1) NOT NULL DEFAULT 0,
  `has_expiry` TINYINT(1) NOT NULL DEFAULT 0,
  `default_expiry_days` INT(11) DEFAULT NULL COMMENT 'Default validity period in days',
  `alert_days_before_expiry` INT(11) DEFAULT 30 COMMENT 'Days before expiry to send alert',
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `display_order` INT(11) NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_company_code` (`company_id`, `document_type_code`),
  KEY `idx_company` (`company_id`),
  KEY `idx_active` (`is_active`),
  FOREIGN KEY (`company_id`) REFERENCES `companies`(`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ============================================================================
-- 2. Extend existing re_documents table (if exists) or create new
-- ============================================================================
-- Add missing columns to existing re_documents table
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
                   WHERE TABLE_SCHEMA = DATABASE() 
                   AND TABLE_NAME = 're_documents' 
                   AND COLUMN_NAME = 'document_type_id');
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE `re_documents` ADD COLUMN `document_type_id` INT(11) DEFAULT NULL AFTER `company_id`',
    'SELECT "Column document_type_id already exists" AS message');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
                   WHERE TABLE_SCHEMA = DATABASE() 
                   AND TABLE_NAME = 're_documents' 
                   AND COLUMN_NAME = 'document_name');
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE `re_documents` ADD COLUMN `document_name` VARCHAR(255) NOT NULL DEFAULT "" AFTER `document_type_id`',
    'SELECT "Column document_name already exists" AS message');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
                   WHERE TABLE_SCHEMA = DATABASE() 
                   AND TABLE_NAME = 're_documents' 
                   AND COLUMN_NAME = 'document_number');
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE `re_documents` ADD COLUMN `document_number` VARCHAR(100) DEFAULT NULL COMMENT "Document reference number (e.g., Ejari number)" AFTER `document_name`',
    'SELECT "Column document_number already exists" AS message');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
                   WHERE TABLE_SCHEMA = DATABASE() 
                   AND TABLE_NAME = 're_documents' 
                   AND COLUMN_NAME = 'issue_date');
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE `re_documents` ADD COLUMN `issue_date` DATE DEFAULT NULL AFTER `mime_type`',
    'SELECT "Column issue_date already exists" AS message');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Update expiry_date column name if it's called expires_at
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
                   WHERE TABLE_SCHEMA = DATABASE() 
                   AND TABLE_NAME = 're_documents' 
                   AND COLUMN_NAME = 'expires_at');
SET @col_exists2 = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
                    WHERE TABLE_SCHEMA = DATABASE() 
                    AND TABLE_NAME = 're_documents' 
                    AND COLUMN_NAME = 'expiry_date');
SET @sql = IF(@col_exists > 0 AND @col_exists2 = 0,
    'ALTER TABLE `re_documents` CHANGE COLUMN `expires_at` `expiry_date` DATE DEFAULT NULL',
    'SELECT "Column expiry_date already exists or expires_at does not exist" AS message');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
                   WHERE TABLE_SCHEMA = DATABASE() 
                   AND TABLE_NAME = 're_documents' 
                   AND COLUMN_NAME = 'expiry_alert_sent');
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE `re_documents` ADD COLUMN `expiry_alert_sent` TINYINT(1) NOT NULL DEFAULT 0 AFTER `is_expired`',
    'SELECT "Column expiry_alert_sent already exists" AS message');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
                   WHERE TABLE_SCHEMA = DATABASE() 
                   AND TABLE_NAME = 're_documents' 
                   AND COLUMN_NAME = 'status');
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE `re_documents` ADD COLUMN `status` ENUM("active", "expired", "renewed", "cancelled") NOT NULL DEFAULT "active" AFTER `expiry_alert_sent`',
    'SELECT "Column status already exists" AS message');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add foreign key for document_type_id if not exists
SET @fk_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE 
                  WHERE TABLE_SCHEMA = DATABASE() 
                  AND TABLE_NAME = 're_documents' 
                  AND CONSTRAINT_NAME = 'fk_document_type');
SET @sql = IF(@fk_exists = 0,
    'ALTER TABLE `re_documents` ADD CONSTRAINT `fk_document_type` FOREIGN KEY (`document_type_id`) REFERENCES `re_document_types`(`id`) ON DELETE SET NULL',
    'SELECT "Foreign key fk_document_type already exists" AS message');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ============================================================================
-- 3. Ejari Tracking (Dubai-specific lease registration)
-- ============================================================================
CREATE TABLE IF NOT EXISTS `re_ejari_tracking` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `company_id` INT(11) NOT NULL,
  `lease_id` INT(11) NOT NULL,
  `ejari_number` VARCHAR(100) NOT NULL,
  `registration_date` DATE DEFAULT NULL,
  `expiry_date` DATE DEFAULT NULL,
  `registration_status` ENUM('pending', 'registered', 'expired', 'renewed', 'cancelled') NOT NULL DEFAULT 'pending',
  `registration_fee` DECIMAL(10,2) DEFAULT NULL,
  `renewal_reminder_sent` TINYINT(1) NOT NULL DEFAULT 0,
  `document_id` INT(11) DEFAULT NULL COMMENT 'Link to re_documents table',
  `notes` TEXT DEFAULT NULL,
  `created_by` INT(11) DEFAULT NULL COMMENT 'user_id',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_ejari_number` (`company_id`, `ejari_number`),
  KEY `idx_company` (`company_id`),
  KEY `idx_lease` (`lease_id`),
  KEY `idx_registration_status` (`registration_status`),
  KEY `idx_expiry_date` (`expiry_date`),
  KEY `idx_document` (`document_id`),
  FOREIGN KEY (`company_id`) REFERENCES `companies`(`id`) ON DELETE RESTRICT,
  FOREIGN KEY (`lease_id`) REFERENCES `re_leases`(`id`) ON DELETE RESTRICT,
  FOREIGN KEY (`document_id`) REFERENCES `re_documents`(`id`) ON DELETE SET NULL,
  FOREIGN KEY (`created_by`) REFERENCES `user`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ============================================================================
-- 4. Unit Legal Status
-- ============================================================================
CREATE TABLE IF NOT EXISTS `re_unit_legal_status` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `company_id` INT(11) NOT NULL,
  `unit_id` INT(11) NOT NULL,
  `legal_status` ENUM('compliant', 'non_compliant', 'pending_review', 'at_risk') NOT NULL DEFAULT 'pending_review',
  `compliance_score` INT(11) DEFAULT NULL COMMENT '0-100 compliance score',
  `last_review_date` DATE DEFAULT NULL,
  `next_review_date` DATE DEFAULT NULL,
  `review_notes` TEXT DEFAULT NULL,
  `reviewed_by` INT(11) DEFAULT NULL COMMENT 'user_id',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_unit` (`company_id`, `unit_id`),
  KEY `idx_company` (`company_id`),
  KEY `idx_unit` (`unit_id`),
  KEY `idx_legal_status` (`legal_status`),
  FOREIGN KEY (`company_id`) REFERENCES `companies`(`id`) ON DELETE RESTRICT,
  FOREIGN KEY (`unit_id`) REFERENCES `re_units`(`id`) ON DELETE RESTRICT,
  FOREIGN KEY (`reviewed_by`) REFERENCES `user`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ============================================================================
-- 5. Missing Documents Checklist
-- ============================================================================
CREATE TABLE IF NOT EXISTS `re_missing_documents` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `company_id` INT(11) NOT NULL,
  `related_type` ENUM('lease', 'tenant', 'unit', 'building') NOT NULL,
  `related_id` INT(11) NOT NULL,
  `document_type_id` INT(11) NOT NULL,
  `is_required` TINYINT(1) NOT NULL DEFAULT 1,
  `status` ENUM('missing', 'pending_upload', 'uploaded', 'resolved') NOT NULL DEFAULT 'missing',
  `alert_sent_date` DATE DEFAULT NULL,
  `resolved_date` DATE DEFAULT NULL,
  `resolved_by` INT(11) DEFAULT NULL COMMENT 'user_id',
  `notes` TEXT DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_company` (`company_id`),
  KEY `idx_related` (`related_type`, `related_id`),
  KEY `idx_document_type` (`document_type_id`),
  KEY `idx_status` (`status`),
  FOREIGN KEY (`company_id`) REFERENCES `companies`(`id`) ON DELETE RESTRICT,
  FOREIGN KEY (`document_type_id`) REFERENCES `re_document_types`(`id`) ON DELETE RESTRICT,
  FOREIGN KEY (`resolved_by`) REFERENCES `user`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ============================================================================
-- 6. Document Expiry Alerts Log
-- ============================================================================
CREATE TABLE IF NOT EXISTS `re_document_expiry_alerts` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `company_id` INT(11) NOT NULL,
  `document_id` INT(11) NOT NULL,
  `alert_sent_date` DATE NOT NULL,
  `alert_sent_to` TEXT DEFAULT NULL COMMENT 'Comma-separated emails',
  `days_before_expiry` INT(11) NOT NULL COMMENT 'Days before expiry when alert was sent',
  `expiry_date` DATE NOT NULL,
  `status` ENUM('pending', 'sent', 'resolved', 'cancelled') NOT NULL DEFAULT 'pending',
  `resolved_date` DATE DEFAULT NULL,
  `resolved_by` INT(11) DEFAULT NULL COMMENT 'user_id',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_company` (`company_id`),
  KEY `idx_document` (`document_id`),
  KEY `idx_status` (`status`),
  KEY `idx_expiry_date` (`expiry_date`),
  FOREIGN KEY (`company_id`) REFERENCES `companies`(`id`) ON DELETE RESTRICT,
  FOREIGN KEY (`document_id`) REFERENCES `re_documents`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`resolved_by`) REFERENCES `user`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ============================================================================
-- 7. Default Document Types (Insert per company via application)
-- ============================================================================
-- Examples:
-- - Ejari Certificate (required, has_expiry, 365 days, alert 30 days before)
-- - Tenant ID Copy (required, has_expiry, varies)
-- - Passport Copy (required, has_expiry, varies)
-- - Visa Copy (required, has_expiry, varies)
-- - NOC (No Objection Certificate) (optional, has_expiry, 90 days)
-- - Municipality Approval (required, has_expiry, varies)
-- - Insurance Certificate (optional, has_expiry, 365 days)
-- - Building Permit (required, no_expiry)
-- - Fire Safety Certificate (required, has_expiry, 365 days)

-- ============================================================================
-- 8. Indexes for Performance
-- ============================================================================
SET @idx_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS 
                   WHERE TABLE_SCHEMA = DATABASE() 
                   AND TABLE_NAME = 're_documents' 
                   AND INDEX_NAME = 'idx_expiry_alert');
SET @sql = IF(@idx_exists = 0,
    'ALTER TABLE `re_documents` ADD INDEX `idx_expiry_alert` (`expiry_date`, `expiry_alert_sent`, `is_expired`)',
    'SELECT "Index idx_expiry_alert already exists" AS message');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

