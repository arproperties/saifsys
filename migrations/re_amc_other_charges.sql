-- AMC contracts: separate "Other Charges" field (e.g. DCD charges), no VAT applied.
--
-- total_amount = contract_value + vat_amount + other_charges
-- VAT is still calculated on contract_value only.
--
-- Existing rows get 0.00, so no current total changes.
-- Run BEFORE uploading amc_add.php and amc_view.php.

ALTER TABLE `re_amc_contracts`
  ADD COLUMN `other_charges` DECIMAL(15,2) NOT NULL DEFAULT 0.00
    COMMENT 'Extra charges excluded from VAT (e.g. DCD charges)'
  AFTER `vat_amount`;
