-- ============================================================================
-- ARS Phase 4 — Pricing modes, VAT modes, historical bookings, monthly rate
-- Safe to re-run (IF NOT EXISTS guards). Run after prior ARS migrations.
-- ============================================================================

ALTER TABLE `re_units`
  ADD COLUMN IF NOT EXISTS `monthly_rate` DECIMAL(10,2) NOT NULL DEFAULT 0.00
    COMMENT 'Optional monthly package rate (ARS short-term)';

ALTER TABLE `ars_bookings`
  ADD COLUMN IF NOT EXISTS `pricing_mode` VARCHAR(20) NOT NULL DEFAULT 'nightly'
    COMMENT 'nightly, monthly_package, manual_total'
    AFTER `pricing_rules_applied`,
  ADD COLUMN IF NOT EXISTS `vat_mode` VARCHAR(20) NOT NULL DEFAULT 'exclusive'
    COMMENT 'exclusive (net+VAT) or inclusive (amount includes VAT)'
    AFTER `pricing_mode`,
  ADD COLUMN IF NOT EXISTS `entered_amount` DECIMAL(12,2) DEFAULT NULL
    COMMENT 'Manual total or raw entered amount where applicable'
    AFTER `vat_mode`,
  ADD COLUMN IF NOT EXISTS `net_amount` DECIMAL(12,2) DEFAULT NULL
    COMMENT 'Taxable net (room after discounts + extras) for accounting'
    AFTER `vat_amount`,
  ADD COLUMN IF NOT EXISTS `is_historical` TINYINT(1) NOT NULL DEFAULT 0
    COMMENT '1 = backdated admin booking; revenue journal date uses check_in'
    AFTER `net_amount`;
