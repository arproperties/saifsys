-- ============================================================================
-- Add follow-up remarks to bounced cheques
-- ----------------------------------------------------------------------------
-- Purpose:
--   Free-text remarks staff keep on a bounced cheque (tenant contact, promised
--   dates, legal follow-up). Shown and edited on billing_bounced_cheques.php.
--   Display only - not used in any calculation. Additive only.
-- ============================================================================

ALTER TABLE `re_post_dated_cheques`
  ADD COLUMN `bounce_remarks` TEXT NULL DEFAULT NULL
    COMMENT 'Follow-up remarks on a bounced cheque'
    AFTER `bounced_reason`,
  ADD COLUMN `bounce_remarks_updated_by` INT(11) NULL DEFAULT NULL
    COMMENT 'user.id who last edited bounce_remarks'
    AFTER `bounce_remarks`,
  ADD COLUMN `bounce_remarks_updated_at` DATETIME NULL DEFAULT NULL
    AFTER `bounce_remarks_updated_by`;
