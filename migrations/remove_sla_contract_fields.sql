-- Remove the remaining SLA-named fields from AMC contracts and vendor agreements.
--
-- Follow-up to remove_sla_feature.sql. These three columns belonged to other
-- features rather than the SLA tracking system, so they were left in place then.
--
-- WARNING: unlike the previous migration, this one DOES destroy data.
-- As of 2026-09-05:
--   re_amc_contracts.sla_response_time      2 of 18 contracts populated
--   re_amc_contracts.sla_resolution_time    1 of 18 contracts populated
--   re_service_agreements.sla_requirements  table empty
--
-- Run AFTER uploading amc_add.php, amc_view.php and vendor_agreements.php.

-- 1. Look at what is about to be lost (keep a copy if you want a record)
SELECT id, contract_number, contract_title, sla_response_time, sla_resolution_time
FROM re_amc_contracts
WHERE sla_response_time IS NOT NULL OR sla_resolution_time IS NOT NULL;

-- 2. Drop the columns
ALTER TABLE `re_amc_contracts`
  DROP COLUMN `sla_response_time`,
  DROP COLUMN `sla_resolution_time`;

ALTER TABLE `re_service_agreements`
  DROP COLUMN `sla_requirements`;
