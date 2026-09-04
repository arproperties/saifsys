-- Phase 1 MVP Enhancement: Enhanced Document Management
-- Comprehensive document storage and tracking system enhancements

-- ============================================================================
-- 1. Document Versions (Version Control)
-- ============================================================================
CREATE TABLE IF NOT EXISTS `re_document_versions` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `company_id` INT(11) NOT NULL,
  `document_id` INT(11) NOT NULL,
  `version_number` INT(11) NOT NULL DEFAULT 1,
  `file_name` VARCHAR(255) NOT NULL,
  `file_path` VARCHAR(500) NOT NULL,
  `file_size` INT(11) DEFAULT NULL,
  `mime_type` VARCHAR(100) DEFAULT NULL,
  `change_summary` TEXT DEFAULT NULL COMMENT 'What changed in this version',
  `uploaded_by` INT(11) DEFAULT NULL COMMENT 'user_id',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_company` (`company_id`),
  KEY `idx_document` (`document_id`),
  KEY `idx_version` (`document_id`, `version_number`),
  FOREIGN KEY (`company_id`) REFERENCES `companies`(`id`) ON DELETE RESTRICT,
  FOREIGN KEY (`document_id`) REFERENCES `re_documents`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`uploaded_by`) REFERENCES `user`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ============================================================================
-- 2. Document Access Log (Audit Trail)
-- ============================================================================
CREATE TABLE IF NOT EXISTS `re_document_access_log` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `company_id` INT(11) NOT NULL,
  `document_id` INT(11) NOT NULL,
  `user_id` INT(11) NOT NULL,
  `action` ENUM('viewed', 'downloaded', 'uploaded', 'updated', 'deleted', 'shared') NOT NULL,
  `ip_address` VARCHAR(45) DEFAULT NULL,
  `user_agent` TEXT DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_company` (`company_id`),
  KEY `idx_document` (`document_id`),
  KEY `idx_user` (`user_id`),
  KEY `idx_action` (`action`),
  KEY `idx_created` (`created_at`),
  FOREIGN KEY (`company_id`) REFERENCES `companies`(`id`) ON DELETE RESTRICT,
  FOREIGN KEY (`document_id`) REFERENCES `re_documents`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`user_id`) REFERENCES `user`(`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ============================================================================
-- 3. Document Tags (Flexible Categorization)
-- ============================================================================
CREATE TABLE IF NOT EXISTS `re_document_tags` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `company_id` INT(11) NOT NULL,
  `tag_name` VARCHAR(100) NOT NULL,
  `tag_color` VARCHAR(7) DEFAULT '#007bff' COMMENT 'Hex color code',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_company_tag` (`company_id`, `tag_name`),
  KEY `idx_company` (`company_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ============================================================================
-- 4. Document-Tag Relationships
-- ============================================================================
CREATE TABLE IF NOT EXISTS `re_document_tag_relations` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `document_id` INT(11) NOT NULL,
  `tag_id` INT(11) NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_document_tag` (`document_id`, `tag_id`),
  KEY `idx_document` (`document_id`),
  KEY `idx_tag` (`tag_id`),
  FOREIGN KEY (`document_id`) REFERENCES `re_documents`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`tag_id`) REFERENCES `re_document_tags`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ============================================================================
-- 5. Document Sharing (Access Control)
-- ============================================================================
CREATE TABLE IF NOT EXISTS `re_document_sharing` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `company_id` INT(11) NOT NULL,
  `document_id` INT(11) NOT NULL,
  `shared_with_user_id` INT(11) DEFAULT NULL COMMENT 'Shared with specific user',
  `shared_with_role` VARCHAR(100) DEFAULT NULL COMMENT 'Shared with role',
  `permission` ENUM('view', 'download', 'edit', 'delete') NOT NULL DEFAULT 'view',
  `expires_at` DATETIME DEFAULT NULL COMMENT 'Sharing expiration',
  `shared_by` INT(11) DEFAULT NULL COMMENT 'user_id who shared',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_company` (`company_id`),
  KEY `idx_document` (`document_id`),
  KEY `idx_user` (`shared_with_user_id`),
  KEY `idx_role` (`shared_with_role`),
  KEY `idx_expires` (`expires_at`),
  FOREIGN KEY (`company_id`) REFERENCES `companies`(`id`) ON DELETE RESTRICT,
  FOREIGN KEY (`document_id`) REFERENCES `re_documents`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`shared_with_user_id`) REFERENCES `user`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`shared_by`) REFERENCES `user`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ============================================================================
-- 6. Document Favorites (Quick Access)
-- ============================================================================
CREATE TABLE IF NOT EXISTS `re_document_favorites` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `company_id` INT(11) NOT NULL,
  `document_id` INT(11) NOT NULL,
  `user_id` INT(11) NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_user_document` (`user_id`, `document_id`),
  KEY `idx_company` (`company_id`),
  KEY `idx_document` (`document_id`),
  KEY `idx_user` (`user_id`),
  FOREIGN KEY (`company_id`) REFERENCES `companies`(`id`) ON DELETE RESTRICT,
  FOREIGN KEY (`document_id`) REFERENCES `re_documents`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`user_id`) REFERENCES `user`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ============================================================================
-- 7. Enhance re_documents table (if needed)
-- ============================================================================
-- Add version tracking column
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
                   WHERE TABLE_SCHEMA = DATABASE() 
                   AND TABLE_NAME = 're_documents' 
                   AND COLUMN_NAME = 'current_version');
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE `re_documents` ADD COLUMN `current_version` INT(11) NOT NULL DEFAULT 1 AFTER `status`',
    'SELECT "Column current_version already exists" AS message');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add tags column (for quick search)
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
                   WHERE TABLE_SCHEMA = DATABASE() 
                   AND TABLE_NAME = 're_documents' 
                   AND COLUMN_NAME = 'tags');
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE `re_documents` ADD COLUMN `tags` VARCHAR(500) DEFAULT NULL COMMENT "Comma-separated tag names for quick search" AFTER `notes`',
    'SELECT "Column tags already exists" AS message');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add is_favorite flag (denormalized for performance)
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
                   WHERE TABLE_SCHEMA = DATABASE() 
                   AND TABLE_NAME = 're_documents' 
                   AND COLUMN_NAME = 'is_favorite');
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE `re_documents` ADD COLUMN `is_favorite` TINYINT(1) NOT NULL DEFAULT 0 AFTER `tags`',
    'SELECT "Column is_favorite already exists" AS message');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add download_count
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
                   WHERE TABLE_SCHEMA = DATABASE() 
                   AND TABLE_NAME = 're_documents' 
                   AND COLUMN_NAME = 'download_count');
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE `re_documents` ADD COLUMN `download_count` INT(11) NOT NULL DEFAULT 0 AFTER `is_favorite`',
    'SELECT "Column download_count already exists" AS message');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add view_count
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
                   WHERE TABLE_SCHEMA = DATABASE() 
                   AND TABLE_NAME = 're_documents' 
                   AND COLUMN_NAME = 'view_count');
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE `re_documents` ADD COLUMN `view_count` INT(11) NOT NULL DEFAULT 0 AFTER `download_count`',
    'SELECT "Column view_count already exists" AS message');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add indexes for performance
SET @idx_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS 
                   WHERE TABLE_SCHEMA = DATABASE() 
                   AND TABLE_NAME = 're_documents' 
                   AND INDEX_NAME = 'idx_status_created');
SET @sql = IF(@idx_exists = 0,
    'ALTER TABLE `re_documents` ADD INDEX `idx_status_created` (`status`, `created_at`)',
    'SELECT "Index idx_status_created already exists" AS message');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

