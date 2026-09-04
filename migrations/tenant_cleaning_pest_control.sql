-- Cleaning and Pest Control as separate services (management-controlled rates)
-- Run after tenant_portal_tables.sql

-- ----------------------------------------------------------------------------
-- 1. Management config: cleaning rate per hour + materials fee (per company)
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `re_cleaning_rates` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `company_id` int(11) NOT NULL,
  `rate_per_hour_aed` decimal(10,2) NOT NULL DEFAULT 0.00,
  `materials_fee_aed` decimal(10,2) NOT NULL DEFAULT 0.00,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_company` (`company_id`),
  CONSTRAINT `fk_cleaning_rates_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ----------------------------------------------------------------------------
-- 2. Tenant cleaning requests (cleaners, hours, materials; amount from config)
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `tenant_cleaning_requests` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `tenant_id` int(11) NOT NULL,
  `lease_id` int(11) NOT NULL,
  `company_id` int(11) NOT NULL,
  `num_cleaners` tinyint(3) unsigned NOT NULL DEFAULT 1,
  `num_hours` decimal(4,2) NOT NULL DEFAULT 1.00,
  `has_materials` tinyint(1) NOT NULL DEFAULT 0,
  `rate_per_hour_snapshot` decimal(10,2) DEFAULT NULL,
  `materials_fee_snapshot` decimal(10,2) DEFAULT NULL,
  `total_amount_aed` decimal(12,2) DEFAULT NULL,
  `status` enum('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  `admin_notes` text DEFAULT NULL,
  `approved_by` int(11) DEFAULT NULL,
  `approved_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_lease` (`lease_id`),
  KEY `idx_company` (`company_id`),
  KEY `idx_status` (`status`),
  CONSTRAINT `fk_tcr_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `re_tenants` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_tcr_lease` FOREIGN KEY (`lease_id`) REFERENCES `re_leases` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_tcr_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ----------------------------------------------------------------------------
-- 3. Management config: pest control price by unit type (per company)
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `re_pest_control_rates` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `company_id` int(11) NOT NULL,
  `unit_type` varchar(30) NOT NULL COMMENT 'studio, 1_bhk, 2_bhk, 3_bhk',
  `price_aed` decimal(10,2) NOT NULL DEFAULT 0.00,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_company_unit` (`company_id`, `unit_type`),
  CONSTRAINT `fk_pcr_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ----------------------------------------------------------------------------
-- 4. Tenant pest control requests (unit type from lease; price from config)
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `tenant_pest_control_requests` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `tenant_id` int(11) NOT NULL,
  `lease_id` int(11) NOT NULL,
  `company_id` int(11) NOT NULL,
  `unit_type` varchar(30) NOT NULL COMMENT 'studio, 1_bhk, 2_bhk, 3_bhk',
  `price_snapshot` decimal(10,2) DEFAULT NULL,
  `total_amount_aed` decimal(12,2) DEFAULT NULL,
  `status` enum('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  `admin_notes` text DEFAULT NULL,
  `approved_by` int(11) DEFAULT NULL,
  `approved_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_lease` (`lease_id`),
  KEY `idx_company` (`company_id`),
  KEY `idx_status` (`status`),
  CONSTRAINT `fk_tpcr_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `re_tenants` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_tpcr_lease` FOREIGN KEY (`lease_id`) REFERENCES `re_leases` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_tpcr_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
