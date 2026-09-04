-- ============================================================================
-- Real Estate Accounting Transformation - Phase 1 (Additive Only)
-- ----------------------------------------------------------------------------
-- Purpose:
--   1) Add passive accounting_mode support to re_leases.
--   2) Add a first-class re_obligations table for future Invoice Mode.
--   3) Add a disabled-by-default feature flag in settings.
--
-- Safety:
--   - No data deletion.
--   - No historical reposting.
--   - No historical invoice generation.
--   - Existing leases default to Legacy Mode.
--   - re_obligations is created empty; nothing is backfilled by this migration.
-- ============================================================================

ALTER TABLE `re_leases`
    ADD COLUMN IF NOT EXISTS `accounting_mode`
        ENUM('legacy','invoice') NOT NULL DEFAULT 'legacy'
        COMMENT 'legacy = existing accounting behavior; invoice = future obligation/invoice mode';

CREATE INDEX IF NOT EXISTS `idx_re_leases_accounting_mode`
    ON `re_leases` (`company_id`, `accounting_mode`);

CREATE TABLE IF NOT EXISTS `re_obligations` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `company_id` INT(11) NOT NULL,
    `lease_id` INT(11) DEFAULT NULL,
    `tenant_id` INT(11) DEFAULT NULL,
    `source_type` ENUM('lease','installment','billing_item','invoice','manual','system') NOT NULL DEFAULT 'lease',
    `source_id` BIGINT UNSIGNED DEFAULT NULL,
    `obligation_type` ENUM(
        'rent',
        'commission',
        'admin_fee',
        'security_deposit',
        'service',
        'parking',
        'store',
        'utility',
        'access_card',
        'penalty',
        'vat',
        'other'
    ) NOT NULL,
    `accounting_class` ENUM('revenue','liability','pass_through','penalty','service') NOT NULL DEFAULT 'revenue',
    `description` VARCHAR(255) DEFAULT NULL,
    `period_start` DATE DEFAULT NULL,
    `period_end` DATE DEFAULT NULL,
    `due_date` DATE NOT NULL,
    `tax_treatment` ENUM('exempt','standard','zero_rated','out_of_scope') NOT NULL DEFAULT 'exempt',
    `tax_rate` DECIMAL(5,2) NOT NULL DEFAULT 0.00,
    `subtotal_amount` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    `vat_amount` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    `total_amount` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    `allocated_amount` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    `status` ENUM('draft','open','partially_allocated','settled','cancelled','waived') NOT NULL DEFAULT 'draft',
    `invoice_id` INT(11) DEFAULT NULL,
    `recognition_status` ENUM('not_applicable','pending','recognised','skipped') NOT NULL DEFAULT 'pending',
    `recognition_journal_id` INT(11) DEFAULT NULL,
    `notes` TEXT DEFAULT NULL,
    `created_by` INT(11) DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_re_obligations_company_due` (`company_id`, `due_date`, `status`),
    KEY `idx_re_obligations_lease` (`company_id`, `lease_id`),
    KEY `idx_re_obligations_tenant` (`company_id`, `tenant_id`),
    KEY `idx_re_obligations_source` (`source_type`, `source_id`),
    KEY `idx_re_obligations_invoice` (`invoice_id`),
    KEY `idx_re_obligations_recognition` (`company_id`, `recognition_status`, `period_start`),
    CONSTRAINT `fk_re_obligations_company`
        FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE RESTRICT,
    CONSTRAINT `fk_re_obligations_lease`
        FOREIGN KEY (`lease_id`) REFERENCES `re_leases` (`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_re_obligations_tenant`
        FOREIGN KEY (`tenant_id`) REFERENCES `re_tenants` (`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_re_obligations_invoice`
        FOREIGN KEY (`invoice_id`) REFERENCES `re_invoices` (`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_re_obligations_recognition_journal`
        FOREIGN KEY (`recognition_journal_id`) REFERENCES `re_journal_headers` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Phase 1 additive obligation layer. Empty by default; no historical backfill in migration.';

INSERT INTO `settings` (`key`, `value`)
VALUES ('re_accounting_invoice_mode_enabled', '0')
ON DUPLICATE KEY UPDATE `value` = `value`;

