-- ============================================================================
-- ARS Home Rentals — Phase 0 Foundation
-- Run on each environment after backup.
-- All statements are safe for re-run (IF NOT EXISTS / IF EXISTS guards).
-- ============================================================================

-- ---------------------------------------------------------------------------
-- 1. Extend companies.business_type enum to include short_term_rental
-- ---------------------------------------------------------------------------
ALTER TABLE `companies`
  MODIFY COLUMN `business_type`
    ENUM('cleaning','realestate','supermarket','restaurant','construction','short_term_rental')
    NOT NULL;

-- Insert ARS Home Rentals company (skip if already present)
INSERT INTO `companies` (`name`, `code`, `business_type`, `is_active`)
SELECT 'ARS Home Rentals', 'ARS', 'short_term_rental', 1
FROM DUAL
WHERE NOT EXISTS (
    SELECT 1 FROM `companies` WHERE `code` = 'ARS'
);

-- ---------------------------------------------------------------------------
-- 2. Extend re_units for short-term rental classification
-- ---------------------------------------------------------------------------
ALTER TABLE `re_units`
  ADD COLUMN IF NOT EXISTS `rental_mode`
    ENUM('long_term','short_term','both') NOT NULL DEFAULT 'long_term'
    COMMENT 'How this unit is used — long-term lease, short-term booking, or both',
  ADD COLUMN IF NOT EXISTS `nightly_rate`
    DECIMAL(10,2) DEFAULT NULL
    COMMENT 'Base nightly rate for short-term rentals (AED)',
  ADD COLUMN IF NOT EXISTS `listing_title`
    VARCHAR(200) DEFAULT NULL
    COMMENT 'Display title on customer portal listing',
  ADD COLUMN IF NOT EXISTS `short_description`
    TEXT DEFAULT NULL
    COMMENT 'Short description for portal listing card',
  ADD COLUMN IF NOT EXISTS `amenities_json`
    TEXT DEFAULT NULL
    COMMENT 'JSON array of amenity tags e.g. ["WiFi","Pool","Gym"]',
  ADD COLUMN IF NOT EXISTS `is_listed`
    TINYINT(1) NOT NULL DEFAULT 0
    COMMENT '1 = visible on customer portal for browsing/booking',
  ADD COLUMN IF NOT EXISTS `max_guests`
    INT NOT NULL DEFAULT 2
    COMMENT 'Maximum occupancy for short-term bookings';

ALTER TABLE `re_units`
  ADD INDEX IF NOT EXISTS `idx_rental_mode` (`rental_mode`),
  ADD INDEX IF NOT EXISTS `idx_is_listed` (`is_listed`);

-- ---------------------------------------------------------------------------
-- 3. ARS Guests — guest profiles (separate from re_tenants)
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `ars_guests` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `company_id` INT(11) NOT NULL,
  `first_name` VARCHAR(100) NOT NULL,
  `last_name` VARCHAR(100) NOT NULL,
  `email` VARCHAR(200) DEFAULT NULL,
  `phone` VARCHAR(50) DEFAULT NULL,
  `phone_alt` VARCHAR(50) DEFAULT NULL,
  `nationality` VARCHAR(100) DEFAULT NULL,
  `id_type` ENUM('emirates_id','passport','visa','driving_license','other') DEFAULT NULL,
  `id_number` VARCHAR(100) DEFAULT NULL,
  `id_expiry` DATE DEFAULT NULL,
  `address` TEXT DEFAULT NULL,
  `notes` TEXT DEFAULT NULL,
  `total_bookings` INT NOT NULL DEFAULT 0,
  `total_spent` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_ars_guests_company` (`company_id`),
  KEY `idx_ars_guests_email` (`email`),
  KEY `idx_ars_guests_phone` (`phone`),
  KEY `idx_ars_guests_name` (`last_name`, `first_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- 4. ARS Company Settings — per-company configuration
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `ars_company_settings` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `company_id` INT(11) NOT NULL,
  `pending_expiry_hours` INT NOT NULL DEFAULT 24
    COMMENT 'Hours before a pending (unpaid) booking auto-expires',
  `default_vat_rate` DECIMAL(5,2) NOT NULL DEFAULT 5.00,
  `default_check_in_time` TIME NOT NULL DEFAULT '15:00:00',
  `default_check_out_time` TIME NOT NULL DEFAULT '12:00:00',
  `cleaning_company_id` INT(11) DEFAULT NULL
    COMMENT 'Company ID of the cleaning company for checkout work orders',
  `currency` VARCHAR(10) NOT NULL DEFAULT 'AED',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_ars_settings_company` (`company_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed default settings for ARS company (uses subquery to find company id)
INSERT INTO `ars_company_settings` (`company_id`, `pending_expiry_hours`, `default_vat_rate`)
SELECT `id`, 24, 5.00
FROM `companies`
WHERE `code` = 'ARS'
  AND NOT EXISTS (
    SELECT 1 FROM `ars_company_settings` acs
    WHERE acs.company_id = companies.id
  )
LIMIT 1;

-- ---------------------------------------------------------------------------
-- 5. Portal Users — shared identity for Customer Portal (tenant + guest)
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `portal_users` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `company_id` INT(11) NOT NULL,
  `user_type` ENUM('tenant','guest') NOT NULL DEFAULT 'guest',
  `tenant_portal_user_id` INT(11) DEFAULT NULL
    COMMENT 'FK to tenant_portal_users.id when user_type = tenant',
  `guest_id` INT(11) DEFAULT NULL
    COMMENT 'FK to ars_guests.id when user_type = guest',
  `email` VARCHAR(255) NOT NULL,
  `password_hash` VARCHAR(255) DEFAULT NULL,
  `display_name` VARCHAR(200) DEFAULT NULL,
  `phone` VARCHAR(50) DEFAULT NULL,
  `status` ENUM('active','suspended','unverified') NOT NULL DEFAULT 'unverified',
  `email_verified_at` DATETIME DEFAULT NULL,
  `verification_token` VARCHAR(100) DEFAULT NULL,
  `last_login_at` DATETIME DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_portal_users_email` (`email`),
  KEY `idx_portal_users_company` (`company_id`),
  KEY `idx_portal_users_type` (`user_type`),
  KEY `idx_portal_users_guest` (`guest_id`),
  KEY `idx_portal_users_tenant` (`tenant_portal_user_id`),
  KEY `idx_portal_users_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
