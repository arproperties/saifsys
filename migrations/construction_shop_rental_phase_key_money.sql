-- Construction Shop Rental — Activate Key Money (Confirmed BR-CO-SHOP-KEY-MONEY-001)
-- Enables reserved key_money charge type for AR invoicing via existing shop_charge path.
-- Default income COA 4170 (NOT 4160 deposit recovery).
-- invoice_timing controls generation only (immediate | on_start); accounting unchanged.
-- Human-applied. Does NOT modify Real Estate tables or accounting_engine.

-- Catalogue: enable invoicing + default COA
UPDATE `co_shop_charge_types`
SET
  `invoicing_enabled` = 1,
  `default_coa_code` = COALESCE(NULLIF(TRIM(`default_coa_code`), ''), '4170'),
  `name` = 'Key Money',
  `nature` = 'income',
  `vat_eligible` = 1,
  `is_active` = 1,
  `allocation_priority` = 30,
  `sort_order` = 30
WHERE `code` = 'key_money';

-- Generation timing (immediate = default; on_start = generate when contract start_date reached)
ALTER TABLE `co_shop_contract_charges`
  ADD COLUMN IF NOT EXISTS `invoice_timing` VARCHAR(20) NOT NULL DEFAULT 'immediate'
    COMMENT 'immediate|on_start — Key Money invoice generation timing only'
    AFTER `notes`;

-- Clear obsolete reserved notes on existing zero key_money rows (optional cleanup)
UPDATE `co_shop_contract_charges` cc
JOIN `co_shop_charge_types` ct
  ON ct.id = cc.charge_type_id AND ct.company_id = cc.company_id
SET cc.notes = NULL
WHERE ct.code = 'key_money'
  AND cc.status = 'disabled'
  AND ROUND(cc.amount, 2) = 0
  AND cc.notes LIKE 'Reserved%';
