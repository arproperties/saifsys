-- Construction Shop Rental Phase 3 — Cheque lifecycle + Allocate Payment entry
-- Adds allocated status (settlement only after Payment Workspace confirm).
-- Does NOT modify Real Estate tables or accounting_engine.
-- Bank ops (deposited/cleared) remain non-settling; allocated follows confirmed payment.

ALTER TABLE `co_shop_rent_cheques`
  MODIFY COLUMN `status` ENUM(
    'received',
    'deposited',
    'cleared',
    'allocated',
    'bounced',
    'returned',
    'replaced',
    'cancelled'
  ) NOT NULL DEFAULT 'received';

-- Optional audit: when Workspace allocated the instrument
ALTER TABLE `co_shop_rent_cheques`
  ADD COLUMN IF NOT EXISTS `allocated_at` DATETIME DEFAULT NULL AFTER `bounced_at`,
  ADD COLUMN IF NOT EXISTS `allocated_payment_id` INT(11) DEFAULT NULL AFTER `payment_id`;

CREATE INDEX IF NOT EXISTS `idx_co_shop_cheques_alloc_pay`
  ON `co_shop_rent_cheques` (`company_id`, `allocated_payment_id`);
