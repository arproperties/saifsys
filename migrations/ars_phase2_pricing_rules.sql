-- ============================================================================
-- ARS Phase 2.1 — Dynamic Pricing Rules
-- Safe to re-run (IF NOT EXISTS guards)
-- ============================================================================

CREATE TABLE IF NOT EXISTS `ars_pricing_rules` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `company_id` INT(11) NOT NULL,
  `unit_id` INT(11) DEFAULT NULL COMMENT 'NULL = applies to all ARS units',
  `rule_type` ENUM('seasonal','weekend','length_discount','minimum_stay') NOT NULL,
  `name` VARCHAR(100) NOT NULL,
  `start_date` DATE DEFAULT NULL,
  `end_date` DATE DEFAULT NULL,
  `rate_amount` DECIMAL(10,2) DEFAULT NULL COMMENT 'Fixed nightly rate override',
  `rate_modifier` DECIMAL(5,2) DEFAULT NULL COMMENT 'Percentage adjustment e.g. +20 or -10',
  `min_nights` INT(11) DEFAULT NULL,
  `discount_percent` DECIMAL(5,2) DEFAULT NULL,
  `priority` INT(11) NOT NULL DEFAULT 0 COMMENT 'Higher priority wins when rules overlap',
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_by` INT(11) DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_pr_company` (`company_id`),
  KEY `idx_pr_unit` (`unit_id`),
  KEY `idx_pr_dates` (`start_date`, `end_date`),
  KEY `idx_pr_type` (`rule_type`),
  KEY `idx_pr_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Add length discount tracking to bookings
ALTER TABLE `ars_bookings`
  ADD COLUMN IF NOT EXISTS `length_discount_amount` DECIMAL(10,2) NOT NULL DEFAULT 0.00
    COMMENT 'Auto-applied length-of-stay discount amount'
    AFTER `vat_amount`,
  ADD COLUMN IF NOT EXISTS `length_discount_label` VARCHAR(100) DEFAULT NULL
    COMMENT 'Name of the length discount rule applied'
    AFTER `length_discount_amount`,
  ADD COLUMN IF NOT EXISTS `pricing_rules_applied` TEXT DEFAULT NULL
    COMMENT 'JSON array of rule names applied to this booking'
    AFTER `length_discount_label`;
