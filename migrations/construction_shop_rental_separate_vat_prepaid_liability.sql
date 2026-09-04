-- Separate VAT Payment — prepaid Output VAT liability model (BR-CO-SHOP-002 revision)
-- Run on Construction companies using Shop Rental Separate VAT.
-- Safe / additive. Does not reverse historical VAT-only invoices.

-- Contract: unconsumed VAT collected in advance (GL liability 2330)
ALTER TABLE `co_shop_rental_contracts`
  ADD COLUMN IF NOT EXISTS `prepaid_vat_balance` DECIMAL(15,2) NOT NULL DEFAULT 0.00
    COMMENT 'Unconsumed separate VAT cash held as prepaid Output VAT liability';

-- Invoice: prepaid VAT applied to reduce AR (official tax invoice still shows full VAT)
ALTER TABLE `co_client_invoices`
  ADD COLUMN IF NOT EXISTS `prepaid_vat_applied` DECIMAL(15,2) NOT NULL DEFAULT 0.00
    COMMENT 'Prepaid Output VAT consumed against this tax invoice (reduces collectible AR)';

-- Payment: distinguish invoice allocation receipts from prepaid VAT cash receipts
ALTER TABLE `co_client_payments`
  ADD COLUMN IF NOT EXISTS `receipt_purpose` VARCHAR(40) NOT NULL DEFAULT 'invoice_allocation'
    COMMENT 'invoice_allocation | prepaid_output_vat';

ALTER TABLE `co_client_payments`
  ADD COLUMN IF NOT EXISTS `prepaid_vat_amount` DECIMAL(15,2) NOT NULL DEFAULT 0.00
    COMMENT 'Portion of receipt credited to VAT Collected in Advance (2330)';

-- Allow receipt-only payments with no invoice (prepaid VAT collection)
ALTER TABLE `co_client_payments`
  MODIFY COLUMN `invoice_id` INT(11) NULL DEFAULT NULL;

-- Legacy rent invoices on Separate VAT contracts: mark VAT share as already applied for
-- collectible AR (old model posted AR=net only). Does not create GL journals.
UPDATE `co_client_invoices` i
JOIN `co_shop_rent_schedules` s
  ON s.id = i.source_id AND s.company_id = i.company_id AND i.source_type = 'shop_rental'
JOIN `co_shop_rental_contracts` c
  ON c.id = s.contract_id AND c.company_id = s.company_id
SET i.prepaid_vat_applied = i.vat_amount
WHERE COALESCE(c.vat_collection_method, '') = 'separate'
  AND COALESCE(s.schedule_type, 'rent') = 'rent'
  AND COALESCE(i.vat_amount, 0) > 0
  AND COALESCE(i.prepaid_vat_applied, 0) = 0
  AND i.status <> 'cancelled';
