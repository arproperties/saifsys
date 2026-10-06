-- Lease Add/Edit, "Rent & Payment Details": two typed fields.
--   contract_amount       rent for the whole lease period
--   annual_rent_per_year  rent for one year (12 months)
-- Display fields only: the cheque schedule, invoices and accounting keep reading
-- re_leases.annual_rent exactly as before.
-- Safe to re-run.
ALTER TABLE `re_leases`
  ADD COLUMN IF NOT EXISTS `contract_amount` decimal(15,2) DEFAULT NULL AFTER `annual_rent`;

ALTER TABLE `re_leases`
  ADD COLUMN IF NOT EXISTS `annual_rent_per_year` decimal(15,2) DEFAULT NULL AFTER `contract_amount`;

-- Existing leases: the stored rent already is the whole-contract figure.
-- annual_rent_per_year stays empty until a lease is saved from the form; the
-- pages show the 12-month share of the contract amount until then.
UPDATE `re_leases` SET `contract_amount` = `annual_rent` WHERE `contract_amount` IS NULL;
