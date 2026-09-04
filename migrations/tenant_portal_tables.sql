-- ============================================================================
-- Tenant Portal — Database Tables (run after TENANT_PORTAL_DESIGN.md)
-- ============================================================================
-- Adds: user_type on user, Tenant role, tenant_portal_* tables.
-- Safe to run multiple times (IF NOT EXISTS / conditional ALTER).
-- ============================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ----------------------------------------------------------------------------
-- 1. user table: add user_type (tenant vs internal)
-- ----------------------------------------------------------------------------
SET @col_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'user' AND COLUMN_NAME = 'user_type');
SET @sql = IF(@col_exists = 0,
    "ALTER TABLE `user` ADD COLUMN `user_type` ENUM('internal','tenant') NOT NULL DEFAULT 'internal' AFTER `is_active`",
    'SELECT "user_type already exists" AS msg');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ----------------------------------------------------------------------------
-- 2. Tenant role (if not exists)
-- ----------------------------------------------------------------------------
INSERT IGNORE INTO `roles` (`name`, `module`, `description`) VALUES
('Tenant', 'realestate', 'Tenant portal user; read-only access to own lease data');

-- ----------------------------------------------------------------------------
-- 3. tenant_portal_accounts — links user to tenant/lease; approval status
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `tenant_portal_accounts` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `tenant_id` int(11) NOT NULL,
  `lease_id` int(11) NOT NULL,
  `company_id` int(11) NOT NULL,
  `status` enum('pending_approval','approved','suspended','revoked') NOT NULL DEFAULT 'pending_approval',
  `approved_at` datetime DEFAULT NULL,
  `approved_by` int(11) DEFAULT NULL COMMENT 'user.id',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_user_lease` (`user_id`,`lease_id`),
  KEY `idx_tenant` (`tenant_id`),
  KEY `idx_lease` (`lease_id`),
  KEY `idx_company` (`company_id`),
  KEY `idx_status` (`status`),
  CONSTRAINT `fk_tpa_user` FOREIGN KEY (`user_id`) REFERENCES `user` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_tpa_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `re_tenants` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_tpa_lease` FOREIGN KEY (`lease_id`) REFERENCES `re_leases` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_tpa_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ----------------------------------------------------------------------------
-- 4. tenant_portal_invites — admin invitation tokens
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `tenant_portal_invites` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `lease_id` int(11) NOT NULL,
  `tenant_id` int(11) NOT NULL,
  `token_hash` varchar(64) NOT NULL,
  `expires_at` datetime NOT NULL,
  `used_at` datetime DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_lease` (`lease_id`),
  KEY `idx_tenant` (`tenant_id`),
  KEY `idx_token` (`token_hash`(32)),
  KEY `idx_expires` (`expires_at`),
  CONSTRAINT `fk_tpi_lease` FOREIGN KEY (`lease_id`) REFERENCES `re_leases` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_tpi_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `re_tenants` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_tpi_created_by` FOREIGN KEY (`created_by`) REFERENCES `user` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ----------------------------------------------------------------------------
-- 5. tenant_portal_verification_codes — self-registration (lease + email/phone)
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `tenant_portal_verification_codes` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `lease_id` int(11) NOT NULL,
  `email_or_phone` varchar(150) NOT NULL COMMENT 'Email or phone used for verification',
  `code_hash` varchar(64) NOT NULL,
  `expires_at` datetime NOT NULL,
  `used_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_lease` (`lease_id`),
  KEY `idx_expires` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ----------------------------------------------------------------------------
-- 6. tenant_extra_service_requests — parking, storage, other
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `tenant_extra_service_requests` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `tenant_id` int(11) NOT NULL,
  `lease_id` int(11) NOT NULL,
  `company_id` int(11) NOT NULL,
  `service_type` varchar(50) NOT NULL COMMENT 'e.g. extra_parking, storage, other',
  `description` text DEFAULT NULL,
  `status` enum('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  `admin_notes` text DEFAULT NULL,
  `approved_by` int(11) DEFAULT NULL,
  `approved_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_tenant` (`tenant_id`),
  KEY `idx_lease` (`lease_id`),
  KEY `idx_company` (`company_id`),
  KEY `idx_status` (`status`),
  CONSTRAINT `fk_tesr_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `re_tenants` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_tesr_lease` FOREIGN KEY (`lease_id`) REFERENCES `re_leases` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_tesr_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_tesr_approved_by` FOREIGN KEY (`approved_by`) REFERENCES `user` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ----------------------------------------------------------------------------
-- 7. tenant_portal_audit_log
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `tenant_portal_audit_log` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `lease_id` int(11) NOT NULL,
  `action` varchar(80) NOT NULL,
  `entity_type` varchar(50) DEFAULT NULL,
  `entity_id` int(11) DEFAULT NULL,
  `ip` varchar(45) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_user` (`user_id`),
  KEY `idx_lease` (`lease_id`),
  KEY `idx_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

SET FOREIGN_KEY_CHECKS = 1;

SELECT 'Tenant portal tables migration completed.' AS status;
