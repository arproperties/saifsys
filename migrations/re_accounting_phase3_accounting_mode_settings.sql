-- ============================================================================
-- Real Estate Accounting Transformation - Phase 3 Accounting Mode Settings
-- ----------------------------------------------------------------------------
-- Purpose:
--   Make Invoice Mode the configurable default for new leases and renewals while
--   preserving existing Legacy leases. Adds an audit trail for manual
--   accounting_mode overrides.
--
-- Safety:
--   - No historical lease conversion.
--   - No recalculation or reposting.
--   - No destructive changes.
-- ============================================================================

INSERT INTO `settings` (`key`, `value`)
VALUES
    ('re_default_accounting_mode_new_leases', 'invoice'),
    ('re_default_accounting_mode_renewals', 'invoice'),
    ('re_allow_legacy_mode_new_leases', '0'),
    ('re_allow_accounting_mode_override', 'accountant_only'),
    ('re_invoice_mode_activation_date', '2026-06-21'),
    ('re_show_accounting_mode_badge', '1')
ON DUPLICATE KEY UPDATE `value` = `value`;

CREATE TABLE IF NOT EXISTS `re_lease_accounting_mode_audit` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `company_id` INT(11) NOT NULL,
    `lease_id` INT(11) NOT NULL,
    `old_mode` ENUM('legacy','invoice') DEFAULT NULL,
    `new_mode` ENUM('legacy','invoice') NOT NULL,
    `changed_by` INT(11) DEFAULT NULL,
    `changed_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `reason` VARCHAR(255) DEFAULT NULL,
    `source` VARCHAR(50) NOT NULL DEFAULT 'lease_edit',
    PRIMARY KEY (`id`),
    KEY `idx_re_lease_mode_audit_lease` (`company_id`, `lease_id`),
    KEY `idx_re_lease_mode_audit_changed` (`company_id`, `changed_at`),
    CONSTRAINT `fk_re_lease_mode_audit_lease`
        FOREIGN KEY (`lease_id`) REFERENCES `re_leases` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Audit log for manual Real Estate lease accounting_mode overrides.';

