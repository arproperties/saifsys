-- ============================================================================
-- ARS Phase 2.2 — Promo Code Max Discount Cap
-- Safe to re-run (IF NOT EXISTS guard)
-- ============================================================================

ALTER TABLE `ars_promo_codes`
  ADD COLUMN IF NOT EXISTS `max_discount_amount` DECIMAL(10,2) DEFAULT NULL
    COMMENT 'Maximum discount cap in AED (NULL = no cap)'
    AFTER `discount_value`;
