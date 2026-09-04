-- Extra services: period-based pricing, management rates, payment status
-- Run after tenant_portal_tables.sql

-- 1) Management-controlled monthly rates per extra service type (per company)
CREATE TABLE IF NOT EXISTS `re_extra_service_rates` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `company_id` int(11) NOT NULL,
  `service_type` varchar(50) NOT NULL COMMENT 'extra_parking, storage, cleaning_service, pest_control_service, other',
  `monthly_amount_aed` decimal(12,2) NOT NULL DEFAULT 0.00,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_company_service` (`company_id`, `service_type`),
  KEY `idx_company` (`company_id`),
  CONSTRAINT `fk_esr_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- 2) Add period and pricing columns to tenant extra service requests
-- Run each ADD once; if column already exists, skip that statement.
ALTER TABLE `tenant_extra_service_requests` ADD COLUMN `period_from` date DEFAULT NULL COMMENT 'Start date for monthly billing';
ALTER TABLE `tenant_extra_service_requests` ADD COLUMN `period_to` date DEFAULT NULL COMMENT 'End date for monthly billing';
ALTER TABLE `tenant_extra_service_requests` ADD COLUMN `monthly_rate_aed` decimal(12,2) DEFAULT NULL;
ALTER TABLE `tenant_extra_service_requests` ADD COLUMN `total_amount_aed` decimal(12,2) DEFAULT NULL;
ALTER TABLE `tenant_extra_service_requests` ADD COLUMN `payment_status` enum('n_a','pending_payment','paid') NOT NULL DEFAULT 'n_a' COMMENT 'n_a = not applicable';

-- 3) Insert default rates for existing companies (optional - management can change in UI)
-- INSERT INTO re_extra_service_rates (company_id, service_type, monthly_amount_aed) 
-- SELECT id, 'extra_parking', 300 FROM companies UNION ALL
-- SELECT id, 'storage', 500 FROM companies UNION ALL
-- SELECT id, 'cleaning_service', 200 FROM companies UNION ALL
-- SELECT id, 'pest_control_service', 150 FROM companies ON DUPLICATE KEY UPDATE monthly_amount_aed = VALUES(monthly_amount_aed);
