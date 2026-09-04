-- ============================================================================
-- Lease status: has_legal_case
-- Operational status: frees the unit for a new booking while keeping the
-- prior lease record for legal/AR follow-up. Does not terminate accounting.
-- ============================================================================

ALTER TABLE `re_leases`
  MODIFY COLUMN `status` ENUM(
    'draft',
    'active',
    'expired',
    'terminated',
    'renewed',
    'has_legal_case'
  ) NOT NULL DEFAULT 'draft';
