-- ============================================================================
-- ARS Phase 1 — Financial Lock Foundations + Activity Event Store
-- Additive only. Safe for re-run (IF NOT EXISTS / information_schema guards).
-- Does NOT create invoice/CN/allocation document tables (Phase 2 / Option B).
-- Does NOT modify re_invoices, re_leases, re_tenants, or accounting_engine.
-- ============================================================================

-- ---------------------------------------------------------------------------
-- 1. Financial lifecycle + lock columns on ars_bookings
-- ---------------------------------------------------------------------------
SET @db := DATABASE();

SET @sql := (
  SELECT IF(
    EXISTS(
      SELECT 1 FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'ars_bookings' AND COLUMN_NAME = 'financial_status'
    ),
    'SELECT 1',
    "ALTER TABLE `ars_bookings`
       ADD COLUMN `financial_status` ENUM('draft','invoice_created','partially_paid','paid','refunded','closed')
         NOT NULL DEFAULT 'draft'
         COMMENT 'Financial lifecycle independent of operational status'
         AFTER `payment_status`"
  )
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := (
  SELECT IF(
    EXISTS(
      SELECT 1 FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'ars_bookings' AND COLUMN_NAME = 'is_financially_locked'
    ),
    'SELECT 1',
    "ALTER TABLE `ars_bookings`
       ADD COLUMN `is_financially_locked` TINYINT(1) NOT NULL DEFAULT 0
         COMMENT '1 = financial lock; money fields require amendment workflow'
         AFTER `financial_status`"
  )
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := (
  SELECT IF(
    EXISTS(
      SELECT 1 FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'ars_bookings' AND COLUMN_NAME = 'financial_locked_at'
    ),
    'SELECT 1',
    "ALTER TABLE `ars_bookings`
       ADD COLUMN `financial_locked_at` DATETIME DEFAULT NULL AFTER `is_financially_locked`,
       ADD COLUMN `financial_locked_by` INT(11) DEFAULT NULL AFTER `financial_locked_at`,
       ADD COLUMN `financial_lock_reason` VARCHAR(255) DEFAULT NULL AFTER `financial_locked_by`"
  )
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := (
  SELECT IF(
    EXISTS(
      SELECT 1 FROM information_schema.STATISTICS
      WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'ars_bookings' AND INDEX_NAME = 'idx_ars_bookings_financial'
    ),
    'SELECT 1',
    'ALTER TABLE `ars_bookings` ADD INDEX `idx_ars_bookings_financial` (`company_id`, `is_financially_locked`, `financial_status`)'
  )
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------------
-- 2. Backfill lock from existing money evidence (preserve history; no journal rewrite)
-- ---------------------------------------------------------------------------
UPDATE `ars_bookings` b
SET
  b.is_financially_locked = 1,
  b.financial_locked_at = COALESCE(b.financial_locked_at, b.updated_at, b.created_at, NOW()),
  b.financial_lock_reason = COALESCE(b.financial_lock_reason, 'backfill: existing journal/payment/deposit'),
  b.financial_status = CASE
    WHEN b.payment_status = 'refunded' THEN 'refunded'
    WHEN b.payment_status = 'paid' THEN 'paid'
    WHEN b.payment_status = 'partial' THEN 'partially_paid'
    WHEN b.journal_id IS NOT NULL OR b.deposit_journal_id IS NOT NULL THEN 'invoice_created'
    ELSE b.financial_status
  END
WHERE b.is_financially_locked = 0
  AND (
    b.journal_id IS NOT NULL
    OR b.deposit_journal_id IS NOT NULL
    OR b.deposit_refund_journal_id IS NOT NULL
    OR EXISTS (
      SELECT 1 FROM `ars_booking_payments` p
      WHERE p.booking_id = b.id AND p.company_id = b.company_id
    )
  );

-- ---------------------------------------------------------------------------
-- 3. Booking Activity Center event store (writers only in Phase 1; UI in 1B)
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `ars_booking_activities` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `company_id` INT(11) NOT NULL,
  `booking_id` INT(11) NOT NULL,
  `booking_number` VARCHAR(30) DEFAULT NULL,
  `event_category` ENUM(
    'operational','financial','payment','accounting','housekeeping',
    'maintenance','notes','documents','system'
  ) NOT NULL DEFAULT 'system',
  `event_type` VARCHAR(80) NOT NULL,
  `title` VARCHAR(255) NOT NULL,
  `description` TEXT DEFAULT NULL,
  `previous_value` TEXT DEFAULT NULL,
  `new_value` TEXT DEFAULT NULL,
  `related_entity_type` VARCHAR(64) DEFAULT NULL,
  `related_entity_id` INT(11) DEFAULT NULL,
  `related_document_number` VARCHAR(100) DEFAULT NULL,
  `related_journal_id` INT(11) DEFAULT NULL,
  `status` VARCHAR(40) DEFAULT NULL,
  `meta_json` JSON DEFAULT NULL,
  `created_by` INT(11) DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_ars_act_booking` (`company_id`, `booking_id`, `created_at`),
  KEY `idx_ars_act_category` (`company_id`, `booking_id`, `event_category`),
  KEY `idx_ars_act_type` (`booking_id`, `event_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SELECT 'ARS Phase 1 financial lock foundations migration complete.' AS notice;
