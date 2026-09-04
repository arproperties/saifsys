-- ============================================================================
-- ARS Home Rentals — Phase 1 Completion: Security Deposit Columns
-- Run AFTER ars_phase1_core_engine.sql
-- All statements safe for re-run (IF NOT EXISTS guards).
-- ============================================================================

ALTER TABLE `ars_bookings`
  ADD COLUMN IF NOT EXISTS `deposit_amount` DECIMAL(10,2) NOT NULL DEFAULT 0.00
    COMMENT 'Refundable security deposit amount (not included in revenue/VAT)',
  ADD COLUMN IF NOT EXISTS `deposit_status` ENUM('none','pending','received','partially_refunded','refunded') NOT NULL DEFAULT 'none',
  ADD COLUMN IF NOT EXISTS `deposit_received_date` DATE DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `deposit_refunded_amount` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  ADD COLUMN IF NOT EXISTS `deposit_refunded_date` DATE DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `deposit_journal_id` INT(11) DEFAULT NULL
    COMMENT 'FK to re_journal_headers for deposit receive posting',
  ADD COLUMN IF NOT EXISTS `deposit_refund_journal_id` INT(11) DEFAULT NULL
    COMMENT 'FK to re_journal_headers for deposit refund posting';
