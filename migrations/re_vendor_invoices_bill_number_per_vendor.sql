-- Vendor bills: Bill # must be unique per vendor, not across the whole company.
-- Before: two different vendors could not both have bill "29"
--         (error: Duplicate entry '2-29' for key 'uq_company_invoice_number').
-- After:  the same vendor still cannot have the same Bill # twice.
-- Run once in phpMyAdmin. Running it a second time fails with "check that it exists" and changes nothing.

ALTER TABLE `re_vendor_invoices`
  DROP INDEX `uq_company_invoice_number`,
  ADD UNIQUE KEY `uq_company_vendor_invoice_number` (`company_id`, `vendor_id`, `invoice_number`);
