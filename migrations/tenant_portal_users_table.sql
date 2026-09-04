-- ============================================================================
-- Tenant Portal — Separate identity table (tenant_portal_users)
-- Run after tenant_portal_tables.sql. New signups use this table only.
-- ============================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

CREATE TABLE IF NOT EXISTS `tenant_portal_users` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `tenant_id` int(11) NOT NULL,
  `lease_id` int(11) NOT NULL,
  `company_id` int(11) NOT NULL,
  `email` varchar(255) NOT NULL COMMENT 'Login identifier',
  `password_hash` varchar(255) NOT NULL,
  `display_name` varchar(200) DEFAULT NULL,
  `status` enum('pending_approval','approved','suspended','revoked') NOT NULL DEFAULT 'pending_approval',
  `approved_at` datetime DEFAULT NULL,
  `approved_by` int(11) DEFAULT NULL COMMENT 'user.id of admin',
  `last_login_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_lease` (`lease_id`),
  KEY `idx_tenant` (`tenant_id`),
  KEY `idx_company` (`company_id`),
  KEY `idx_email` (`email`),
  KEY `idx_status` (`status`),
  CONSTRAINT `fk_tpu_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `re_tenants` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_tpu_lease` FOREIGN KEY (`lease_id`) REFERENCES `re_leases` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_tpu_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_tpu_approved_by` FOREIGN KEY (`approved_by`) REFERENCES `user` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

SET FOREIGN_KEY_CHECKS = 1;

SELECT 'tenant_portal_users table created.' AS status;
