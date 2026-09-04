-- ============================================================================
-- Tenant cleaning & pest control — add service date/time
-- Run after tenant_cleaning_pest_control.sql
-- ============================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

ALTER TABLE `tenant_cleaning_requests`
    ADD COLUMN `service_date` date DEFAULT NULL AFTER `company_id`,
    ADD COLUMN `service_time` varchar(20) DEFAULT NULL AFTER `service_date`;

ALTER TABLE `tenant_pest_control_requests`
    ADD COLUMN `service_date` date DEFAULT NULL AFTER `company_id`,
    ADD COLUMN `service_time` varchar(20) DEFAULT NULL AFTER `service_date`;

SET FOREIGN_KEY_CHECKS = 1;

SELECT 'tenant_cleaning_requests / tenant_pest_control_requests enhanced with service_date/service_time.' AS status;

