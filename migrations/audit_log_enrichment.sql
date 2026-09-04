-- ============================================================================
-- Audit log enrichment for multi-company, owner-friendly History
-- HUMAN APPROVAL REQUIRED before running on production.
-- ============================================================================
-- Adds: company_id, module, source, object_ref, action_label
-- Creates: audit_log_archive (same shape) for retention jobs
-- ============================================================================

-- Enrichment columns (idempotent-ish: ignore errors if already present)
ALTER TABLE `audit_log`
  ADD COLUMN IF NOT EXISTS `company_id` INT NULL DEFAULT NULL COMMENT 'Company context when known' AFTER `user_role`,
  ADD COLUMN IF NOT EXISTS `module` VARCHAR(40) NULL DEFAULT NULL COMMENT 'cleaning|realestate|construction|hr|inventory|legal|ars|admin|auth|accounts' AFTER `company_id`,
  ADD COLUMN IF NOT EXISTS `source` VARCHAR(20) NOT NULL DEFAULT 'user' COMMENT 'user|api|system|job' AFTER `module`,
  ADD COLUMN IF NOT EXISTS `object_ref` VARCHAR(120) NULL DEFAULT NULL COMMENT 'Human reference e.g. LE-2026-0045' AFTER `object_id`,
  ADD COLUMN IF NOT EXISTS `action_label` VARCHAR(120) NULL DEFAULT NULL COMMENT 'Owner-facing action label' AFTER `action`;

ALTER TABLE `audit_log`
  ADD INDEX IF NOT EXISTS `idx_audit_company_created` (`company_id`, `created_at`),
  ADD INDEX IF NOT EXISTS `idx_audit_module_created` (`module`, `created_at`),
  ADD INDEX IF NOT EXISTS `idx_audit_source` (`source`),
  ADD INDEX IF NOT EXISTS `idx_audit_object_ref` (`object_ref`);

CREATE TABLE IF NOT EXISTS `audit_log_archive` (
  `id` BIGINT UNSIGNED NOT NULL,
  `user_id` INT NULL DEFAULT NULL,
  `user_name` VARCHAR(255) NULL DEFAULT NULL,
  `user_role` VARCHAR(255) NULL DEFAULT NULL,
  `company_id` INT NULL DEFAULT NULL,
  `module` VARCHAR(40) NULL DEFAULT NULL,
  `source` VARCHAR(20) NOT NULL DEFAULT 'user',
  `action` VARCHAR(50) NOT NULL,
  `action_label` VARCHAR(120) NULL DEFAULT NULL,
  `object_type` VARCHAR(100) NOT NULL,
  `object_id` VARCHAR(100) NULL DEFAULT NULL,
  `object_ref` VARCHAR(120) NULL DEFAULT NULL,
  `summary` TEXT NOT NULL,
  `old_data` LONGTEXT NULL DEFAULT NULL,
  `new_data` LONGTEXT NULL DEFAULT NULL,
  `ip_address` VARCHAR(45) NULL DEFAULT NULL,
  `user_agent` VARCHAR(500) NULL DEFAULT NULL,
  `success` TINYINT(1) NOT NULL DEFAULT 1,
  `error_message` TEXT NULL DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `archived_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_arch_created` (`created_at`),
  KEY `idx_arch_company` (`company_id`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
  COMMENT='Cold archive of audit_log rows moved by retention job';

-- Retention months setting (default 24)
INSERT INTO `settings` (`key`, `value`)
SELECT 'audit_retention_months', '24'
WHERE NOT EXISTS (SELECT 1 FROM `settings` WHERE `key` = 'audit_retention_months' LIMIT 1);
