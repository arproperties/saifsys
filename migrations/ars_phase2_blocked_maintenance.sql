-- ============================================================================
-- ARS Home Rentals — Phase 2.5: Blocked Dates + Maintenance Integration
-- Run AFTER all previous ARS migrations.
-- All statements safe for re-run (IF NOT EXISTS / IF EXISTS guards).
-- ============================================================================

-- 1. Add source tracking to maintenance requests
ALTER TABLE `re_maintenance_requests`
  ADD COLUMN IF NOT EXISTS `source` VARCHAR(30) DEFAULT 'realestate'
    COMMENT 'Origin: realestate, ars, tenant_portal'
    AFTER `created_by`;

-- 2. Link blocked dates to maintenance requests
ALTER TABLE `ars_blocked_dates`
  ADD COLUMN IF NOT EXISTS `maintenance_request_id` INT(11) DEFAULT NULL
    COMMENT 'FK to re_maintenance_requests if blocked for maintenance'
    AFTER `reason`,
  ADD COLUMN IF NOT EXISTS `block_type` ENUM('manual','maintenance') NOT NULL DEFAULT 'manual'
    COMMENT 'Whether blocked manually or due to maintenance'
    AFTER `maintenance_request_id`;
