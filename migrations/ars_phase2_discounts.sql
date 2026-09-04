-- ============================================================================
-- ARS Phase 2.2 — Discounts & Promo Codes
-- Safe to re-run (IF NOT EXISTS guards)
-- ============================================================================

CREATE TABLE IF NOT EXISTS `ars_promo_codes` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `company_id` INT(11) NOT NULL,
  `code` VARCHAR(50) NOT NULL,
  `description` VARCHAR(255) DEFAULT NULL,
  `discount_type` ENUM('percentage','fixed') NOT NULL,
  `discount_value` DECIMAL(10,2) NOT NULL,
  `max_uses` INT(11) DEFAULT NULL COMMENT 'NULL = unlimited',
  `times_used` INT(11) NOT NULL DEFAULT 0,
  `valid_from` DATE DEFAULT NULL,
  `valid_to` DATE DEFAULT NULL,
  `min_nights` INT(11) DEFAULT NULL COMMENT 'Minimum nights required to use',
  `min_amount` DECIMAL(10,2) DEFAULT NULL COMMENT 'Minimum subtotal required',
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_by` INT(11) DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_promo` (`company_id`, `code`),
  KEY `idx_pc_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Discount tracking columns on bookings
ALTER TABLE `ars_bookings`
  ADD COLUMN IF NOT EXISTS `discount_type` VARCHAR(20) NOT NULL DEFAULT 'none'
    COMMENT 'none, manual, promo, length'
    AFTER `pricing_rules_applied`,
  ADD COLUMN IF NOT EXISTS `discount_label` VARCHAR(100) DEFAULT NULL
    COMMENT 'Display label e.g. SUMMER20 or Manual 5%'
    AFTER `discount_type`,
  ADD COLUMN IF NOT EXISTS `discount_percent` DECIMAL(5,2) NOT NULL DEFAULT 0.00
    AFTER `discount_label`,
  ADD COLUMN IF NOT EXISTS `discount_amount` DECIMAL(10,2) NOT NULL DEFAULT 0.00
    AFTER `discount_percent`,
  ADD COLUMN IF NOT EXISTS `promo_code_id` INT(11) DEFAULT NULL
    AFTER `discount_amount`;
