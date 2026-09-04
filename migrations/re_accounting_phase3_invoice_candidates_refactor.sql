-- ============================================================================
-- Real Estate Accounting Transformation - Phase 3 Refactor
-- Invoice Candidates / Issuance Model
-- ----------------------------------------------------------------------------
-- Purpose:
--   Add invoice candidates so official invoice numbers are assigned only when an
--   eligible approved candidate is issued.
--
-- Safety:
--   - Additive only.
--   - No existing obligations are deleted, regenerated, or backfilled.
--   - No existing invoices or invoice numbers are modified.
--   - No receipts, payments, cheques, allocations, reports, or GL postings.
-- ============================================================================

CREATE TABLE IF NOT EXISTS `re_invoice_candidates` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `company_id` INT(11) NOT NULL,
    `lease_id` INT(11) NOT NULL,
    `obligation_id` BIGINT UNSIGNED NOT NULL,
    `candidate_key` VARCHAR(190) NOT NULL,
    `eligible_on` DATE NOT NULL,
    `status` ENUM('prepared','approved','issued','cancelled') NOT NULL DEFAULT 'approved',
    `invoice_id` INT(11) DEFAULT NULL,
    `notes` TEXT DEFAULT NULL,
    `engine_version` VARCHAR(30) DEFAULT NULL,
    `prepared_by` INT(11) DEFAULT NULL,
    `prepared_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `approved_by` INT(11) DEFAULT NULL,
    `approved_at` DATETIME DEFAULT NULL,
    `issued_at` DATETIME DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_re_invoice_candidates_company_key` (`company_id`, `candidate_key`),
    UNIQUE KEY `uq_re_invoice_candidates_obligation` (`company_id`, `obligation_id`),
    KEY `idx_re_invoice_candidates_lease` (`company_id`, `lease_id`, `status`, `eligible_on`),
    KEY `idx_re_invoice_candidates_invoice` (`invoice_id`),
    KEY `idx_re_invoice_candidates_eligible` (`company_id`, `status`, `eligible_on`, `invoice_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Phase 3 invoice candidates. Official invoice numbers are assigned only when issued.';

