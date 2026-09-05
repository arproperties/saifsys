-- Remove the SLA tracking feature (realestate module)
--
-- Safe to run: verified on 2026-09-05 that the SLA feature was never used --
--   re_sla_rules            0 rows
--   re_sla_tracking         0 rows
--   re_maintenance_requests.sla_response_met    0 of 608 rows populated
--   re_maintenance_requests.sla_resolution_met  0 of 608 rows populated
--
-- Run this AFTER deploying the code change, so nothing still references them.
-- Re-verify the counts on the target database before running.

-- 1. Confirm there is nothing to lose (expect 0, 0, 0, 0)
SELECT
  (SELECT COUNT(*) FROM re_sla_rules)                                        AS sla_rules,
  (SELECT COUNT(*) FROM re_sla_tracking)                                     AS sla_tracking,
  (SELECT COUNT(*) FROM re_maintenance_requests WHERE sla_response_met   IS NOT NULL) AS response_met,
  (SELECT COUNT(*) FROM re_maintenance_requests WHERE sla_resolution_met IS NOT NULL) AS resolution_met;

-- 2. Drop the SLA tables (tracking first: it has an FK to rules)
DROP TABLE IF EXISTS `re_sla_tracking`;
DROP TABLE IF EXISTS `re_sla_rules`;

-- 3. Drop the two dead SLA flags on maintenance requests
ALTER TABLE `re_maintenance_requests`
  DROP COLUMN `sla_response_met`,
  DROP COLUMN `sla_resolution_met`;

-- NOT dropped on purpose -- these belong to other features, not SLA tracking,
-- and two of them hold live data. Remove only if you decide you want them gone:
--   re_amc_contracts.sla_response_time    (2 of 18 contracts populated)
--   re_amc_contracts.sla_resolution_time  (1 of 18 contracts populated)
--   re_service_agreements.sla_requirements (free-text field; table is empty)
