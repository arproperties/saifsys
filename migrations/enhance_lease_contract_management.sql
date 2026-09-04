-- ============================================================================
-- Enhance Lease & Contract Management
-- ============================================================================
-- This migration adds:
-- 1. Expiry reminders tracking table
-- 2. Contract templates table
-- 3. Renewal workflow tracking
-- ============================================================================

-- Create lease expiry reminders table
CREATE TABLE IF NOT EXISTS `re_lease_expiry_reminders` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `lease_id` INT(11) NOT NULL,
  `reminder_date` DATE NOT NULL COMMENT 'Date when reminder should be sent',
  `reminder_type` ENUM('30_days', '15_days', '7_days', '1_day', 'expired') NOT NULL DEFAULT '30_days',
  `sent_to_management` TINYINT(1) NOT NULL DEFAULT 0,
  `sent_to_tenant` TINYINT(1) NOT NULL DEFAULT 0,
  `management_sent_at` DATETIME DEFAULT NULL,
  `tenant_sent_at` DATETIME DEFAULT NULL,
  `notes` TEXT DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_lease` (`lease_id`),
  KEY `idx_reminder_date` (`reminder_date`),
  KEY `idx_sent` (`sent_to_management`, `sent_to_tenant`),
  FOREIGN KEY (`lease_id`) REFERENCES `re_leases`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Create contract templates table
CREATE TABLE IF NOT EXISTS `re_contract_templates` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `company_id` INT(11) NOT NULL,
  `template_name` VARCHAR(200) NOT NULL,
  `template_type` ENUM('standard', 'commercial', 'short_term', 'long_term', 'renewal') NOT NULL DEFAULT 'standard',
  `description` TEXT DEFAULT NULL,
  `template_content` LONGTEXT NOT NULL COMMENT 'HTML/Template content with placeholders',
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `is_default` TINYINT(1) NOT NULL DEFAULT 0,
  `created_by` INT(11) DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_company` (`company_id`),
  KEY `idx_template_type` (`template_type`),
  KEY `idx_active` (`is_active`),
  KEY `idx_created_by` (`created_by`),
  FOREIGN KEY (`company_id`) REFERENCES `companies`(`id`) ON DELETE RESTRICT,
  FOREIGN KEY (`created_by`) REFERENCES `user`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Create renewal workflow tracking table
CREATE TABLE IF NOT EXISTS `re_lease_renewal_workflows` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `lease_id` INT(11) NOT NULL,
  `workflow_step` ENUM('initiated', 'terms_reviewed', 'tenant_notified', 'tenant_response', 'terms_negotiated', 'renewal_approved', 'new_lease_created', 'completed', 'cancelled') NOT NULL DEFAULT 'initiated',
  `initiated_date` DATE NOT NULL,
  `target_renewal_date` DATE DEFAULT NULL,
  `proposed_rent` DECIMAL(12,2) DEFAULT NULL,
  `proposed_start_date` DATE DEFAULT NULL,
  `proposed_end_date` DATE DEFAULT NULL,
  `tenant_response` TEXT DEFAULT NULL,
  `tenant_response_date` DATE DEFAULT NULL,
  `negotiation_notes` TEXT DEFAULT NULL,
  `new_lease_id` INT(11) DEFAULT NULL COMMENT 'ID of the new lease created from renewal',
  `assigned_to` INT(11) DEFAULT NULL COMMENT 'User assigned to handle renewal',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_lease` (`lease_id`),
  KEY `idx_workflow_step` (`workflow_step`),
  KEY `idx_assigned_to` (`assigned_to`),
  KEY `idx_new_lease` (`new_lease_id`),
  FOREIGN KEY (`lease_id`) REFERENCES `re_leases`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`new_lease_id`) REFERENCES `re_leases`(`id`) ON DELETE SET NULL,
  FOREIGN KEY (`assigned_to`) REFERENCES `user`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Add template_id to leases (optional - to track which template was used)
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() 
    AND TABLE_NAME = 're_leases' 
    AND COLUMN_NAME = 'template_id');
SET @sql = IF(@col_exists = 0, 
    'ALTER TABLE `re_leases` ADD COLUMN `template_id` INT(11) DEFAULT NULL AFTER `renewal_terms`', 
    'SELECT "Column template_id already exists" AS message');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @idx_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS 
    WHERE TABLE_SCHEMA = DATABASE() 
    AND TABLE_NAME = 're_leases' 
    AND INDEX_NAME = 'idx_template');
SET @sql = IF(@idx_exists = 0, 
    'ALTER TABLE `re_leases` ADD KEY `idx_template` (`template_id`)', 
    'SELECT "Index idx_template already exists" AS message');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @fk_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE 
    WHERE TABLE_SCHEMA = DATABASE() 
    AND TABLE_NAME = 're_leases' 
    AND COLUMN_NAME = 'template_id'
    AND REFERENCED_TABLE_NAME = 're_contract_templates');
SET @sql = IF(@fk_exists = 0, 
    'ALTER TABLE `re_leases` ADD CONSTRAINT `fk_lease_template` FOREIGN KEY (`template_id`) REFERENCES `re_contract_templates`(`id`) ON DELETE SET NULL', 
    'SELECT "FK fk_lease_template already exists" AS message');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SELECT 'Lease & Contract Management enhancement completed successfully!' AS message;

