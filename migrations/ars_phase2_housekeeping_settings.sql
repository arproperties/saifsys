-- ============================================================================
-- ARS Phase 2.4b — Housekeeping Settings Enhancement
-- Adds default cleaning fee, worker, and driver to ARS company settings.
-- Safe to re-run (IF NOT EXISTS guards)
-- ============================================================================

ALTER TABLE `ars_company_settings`
  ADD COLUMN IF NOT EXISTS `default_cleaning_fee` DECIMAL(10,2) NOT NULL DEFAULT 0.00
    COMMENT 'Default fee charged per cleaning order'
    AFTER `cleaning_company_id`,
  ADD COLUMN IF NOT EXISTS `default_worker_id` INT(11) DEFAULT NULL
    COMMENT 'Default cleaner (worker) assigned to ARS cleaning orders'
    AFTER `default_cleaning_fee`,
  ADD COLUMN IF NOT EXISTS `default_driver_id` INT(11) DEFAULT NULL
    COMMENT 'Default driver assigned to ARS cleaning orders'
    AFTER `default_worker_id`;
