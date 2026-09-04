-- ============================================================================
-- ARS Phase 2.4 — Housekeeping Integration
-- Adds ars_booking_id to make_order for proper booking linkage
-- Safe to re-run (IF NOT EXISTS guard)
-- ============================================================================

ALTER TABLE `make_order`
  ADD COLUMN IF NOT EXISTS `ars_booking_id` INT(11) DEFAULT NULL
    COMMENT 'FK to ars_bookings — set when order is auto-created by ARS checkout'
    AFTER `bill_batch_id`;

ALTER TABLE `make_order`
  ADD INDEX IF NOT EXISTS `idx_mo_ars_booking` (`ars_booking_id`);
