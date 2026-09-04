-- ============================================================================
-- ARS Home Rentals — Phase 3: Customer Portal
-- Run AFTER all previous ARS migrations.
-- All statements safe for re-run (IF NOT EXISTS guards).
-- ============================================================================

-- 1. Add password reset support to portal_users
ALTER TABLE `portal_users`
  ADD COLUMN IF NOT EXISTS `reset_token` VARCHAR(100) DEFAULT NULL
    AFTER `verification_token`,
  ADD COLUMN IF NOT EXISTS `reset_token_expires` DATETIME DEFAULT NULL
    AFTER `reset_token`;

-- 2. Add portal_user_id FK to ars_guests for quick lookup
ALTER TABLE `ars_guests`
  ADD COLUMN IF NOT EXISTS `portal_user_id` INT(11) DEFAULT NULL
    COMMENT 'FK to portal_users.id for portal login'
    AFTER `company_id`;
