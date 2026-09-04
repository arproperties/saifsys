-- Construction Shop Rental Phase 1.6 — Tenant commission (Madar Al Wadi).
-- Additive. Contract-level commission charged to tenant. No landlord/broker/payroll paths.
-- Rollback intent: DROP commission columns; cancel/void related shop_commission invoices separately.

ALTER TABLE `co_shop_rental_contracts`
  ADD COLUMN IF NOT EXISTS `commission_enabled` TINYINT(1) NOT NULL DEFAULT 1 AFTER `notes`,
  ADD COLUMN IF NOT EXISTS `commission_basis` ENUM('percent','fixed') NOT NULL DEFAULT 'percent' AFTER `commission_enabled`,
  ADD COLUMN IF NOT EXISTS `commission_percent` DECIMAL(8,4) NOT NULL DEFAULT 5.0000 AFTER `commission_basis`,
  ADD COLUMN IF NOT EXISTS `commission_fixed_amount` DECIMAL(15,2) NOT NULL DEFAULT 0.00 AFTER `commission_percent`,
  ADD COLUMN IF NOT EXISTS `commission_net_amount` DECIMAL(15,2) NOT NULL DEFAULT 0.00 AFTER `commission_fixed_amount`,
  ADD COLUMN IF NOT EXISTS `commission_manual_override` TINYINT(1) NOT NULL DEFAULT 0 AFTER `commission_net_amount`,
  ADD COLUMN IF NOT EXISTS `commission_vat_enabled` TINYINT(1) NOT NULL DEFAULT 1 AFTER `commission_manual_override`,
  ADD COLUMN IF NOT EXISTS `commission_vat_rate` DECIMAL(5,2) NOT NULL DEFAULT 5.00 AFTER `commission_vat_enabled`,
  ADD COLUMN IF NOT EXISTS `commission_invoice_id` INT(11) DEFAULT NULL AFTER `commission_vat_rate`;

-- Allow separate commission invoices on the shared client-invoice workflow.
ALTER TABLE `co_client_invoices`
  MODIFY COLUMN `source_type` ENUM(
    'construction_project',
    'shop_rental',
    'shop_commission',
    'camp_management',
    'maintenance_service',
    'manual'
  ) NOT NULL DEFAULT 'construction_project';

-- Seed default net = 5% of rent for existing contracts (no invoice yet).
UPDATE `co_shop_rental_contracts`
SET `commission_enabled` = 1,
    `commission_basis` = 'percent',
    `commission_percent` = 5.0000,
    `commission_net_amount` = ROUND(`rent_amount` * 5.0000 / 100, 2),
    `commission_vat_enabled` = 1,
    `commission_vat_rate` = 5.00
WHERE `commission_invoice_id` IS NULL
  AND COALESCE(`commission_net_amount`, 0) = 0
  AND `rent_amount` > 0;
