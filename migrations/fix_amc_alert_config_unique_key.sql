-- Fix AMC Alert Config Unique Constraint
-- This allows multiple alert thresholds per alert type (e.g., 30 days AND 7 days before expiry)

-- Drop the old unique constraint that prevents multiple thresholds
ALTER TABLE `re_amc_alert_config` 
DROP INDEX IF EXISTS `uq_company_alert_type`;

-- Add new unique constraint that includes days_before_expiry
-- This allows: company_id=1, alert_type='contract_expiry', days_before_expiry=30
-- AND:        company_id=1, alert_type='contract_expiry', days_before_expiry=7
ALTER TABLE `re_amc_alert_config` 
ADD UNIQUE KEY `uq_company_alert_type_days` (`company_id`, `alert_type`, `days_before_expiry`);
