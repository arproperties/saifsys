-- ============================================================================
-- ARS — Display-only rate type and rate on bookings
-- Shown on the booking page. Never used in pricing, totals, VAT or accounting.
-- Safe to re-run (IF NOT EXISTS guards).
-- ============================================================================

ALTER TABLE `ars_bookings`
  ADD COLUMN IF NOT EXISTS `display_rate_type` VARCHAR(10) DEFAULT NULL
    COMMENT 'Display only: nightly, weekly, monthly'
    AFTER `entered_amount`,
  ADD COLUMN IF NOT EXISTS `display_rate` DECIMAL(12,2) DEFAULT NULL
    COMMENT 'Display only: quoted rate for display_rate_type, not used in totals'
    AFTER `display_rate_type`;
