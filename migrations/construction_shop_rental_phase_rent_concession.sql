-- Construction Shop Rental — Rent Concession / Free Rent (BR-CO-SHOP-RENT-CONCESSION-001)
-- Occupancy remains start_date/end_date. Concession skips rent schedule months only.
-- No RE / accounting_engine changes. Human-applied.

ALTER TABLE `co_shop_rental_contracts`
  ADD COLUMN IF NOT EXISTS `concession_enabled` TINYINT(1) NOT NULL DEFAULT 0
    COMMENT '1 = rent concession / free rent window active'
    AFTER `notes`,
  ADD COLUMN IF NOT EXISTS `concession_reason` VARCHAR(40) DEFAULT NULL
    COMMENT 'fit_out|promotion|commercial_negotiation|other'
    AFTER `concession_enabled`,
  ADD COLUMN IF NOT EXISTS `concession_position` VARCHAR(20) DEFAULT NULL
    COMMENT 'beginning|end|custom'
    AFTER `concession_reason`,
  ADD COLUMN IF NOT EXISTS `concession_duration_value` DECIMAL(10,2) DEFAULT NULL
    COMMENT 'Duration magnitude (e.g. 1, 2, 45)'
    AFTER `concession_position`,
  ADD COLUMN IF NOT EXISTS `concession_duration_unit` VARCHAR(10) DEFAULT NULL
    COMMENT 'months|days'
    AFTER `concession_duration_value`,
  ADD COLUMN IF NOT EXISTS `concession_from` DATE DEFAULT NULL
    AFTER `concession_duration_unit`,
  ADD COLUMN IF NOT EXISTS `concession_to` DATE DEFAULT NULL
    AFTER `concession_from`,
  ADD COLUMN IF NOT EXISTS `concession_notes` VARCHAR(500) DEFAULT NULL
    AFTER `concession_to`,
  ADD COLUMN IF NOT EXISTS `concession_value_total` DECIMAL(15,2) DEFAULT NULL
    COMMENT 'Stored total concession value after schedule generation'
    AFTER `concession_notes`;

CREATE INDEX IF NOT EXISTS `idx_co_shop_rental_concession`
  ON `co_shop_rental_contracts` (`company_id`, `concession_enabled`, `concession_from`, `concession_to`);
