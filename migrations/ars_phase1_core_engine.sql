-- ============================================================================
-- ARS Home Rentals — Phase 1: Core Engine
-- Run AFTER ars_phase0_foundation.sql + ars_seed_chart_of_accounts.sql
-- All statements safe for re-run (IF NOT EXISTS guards).
-- ============================================================================

-- ---------------------------------------------------------------------------
-- 1. Bookings
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `ars_bookings` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `company_id` INT(11) NOT NULL,
  `unit_id` INT(11) NOT NULL,
  `guest_id` INT(11) NOT NULL,
  `booking_number` VARCHAR(30) NOT NULL,
  `check_in` DATE NOT NULL,
  `check_out` DATE NOT NULL,
  `nights` INT NOT NULL DEFAULT 1,
  `num_guests` INT NOT NULL DEFAULT 1,
  `status` ENUM('pending','confirmed','checked_in','checked_out','completed','cancelled','expired') NOT NULL DEFAULT 'pending',
  `nightly_rate` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `rate_override` DECIMAL(10,2) DEFAULT NULL COMMENT 'Manual override; NULL = use unit nightly_rate',
  `subtotal` DECIMAL(12,2) NOT NULL DEFAULT 0.00 COMMENT 'nights * effective rate',
  `extras_total` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `vat_rate` DECIMAL(5,2) NOT NULL DEFAULT 5.00,
  `vat_amount` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `total_amount` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `paid_amount` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `balance_due` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `payment_status` ENUM('unpaid','partial','paid','refunded') NOT NULL DEFAULT 'unpaid',
  `expires_at` DATETIME DEFAULT NULL COMMENT 'Auto-expire pending bookings after this time',
  `journal_id` INT(11) DEFAULT NULL COMMENT 'FK to re_journal_headers for revenue posting',
  `special_requests` TEXT DEFAULT NULL,
  `internal_notes` TEXT DEFAULT NULL,
  `cancelled_at` DATETIME DEFAULT NULL,
  `cancelled_by` INT(11) DEFAULT NULL,
  `cancellation_reason` TEXT DEFAULT NULL,
  `created_by` INT(11) DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_booking_number` (`booking_number`),
  KEY `idx_bookings_company` (`company_id`),
  KEY `idx_bookings_unit` (`unit_id`),
  KEY `idx_bookings_guest` (`guest_id`),
  KEY `idx_bookings_status` (`status`),
  KEY `idx_bookings_dates` (`check_in`, `check_out`),
  KEY `idx_bookings_expires` (`expires_at`),
  KEY `idx_bookings_payment` (`payment_status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- 2. Booking Charges (line items)
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `ars_booking_charges` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `booking_id` INT(11) NOT NULL,
  `charge_type` ENUM('room_night','cleaning_fee','late_checkout','extra_guest','damage','other') NOT NULL DEFAULT 'other',
  `description` VARCHAR(255) NOT NULL,
  `quantity` DECIMAL(8,2) NOT NULL DEFAULT 1.00,
  `unit_price` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `total` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `charge_date` DATE DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_charges_booking` (`booking_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- 3. Booking Payments
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `ars_booking_payments` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `booking_id` INT(11) NOT NULL,
  `company_id` INT(11) NOT NULL,
  `amount` DECIMAL(12,2) NOT NULL,
  `payment_method` ENUM('cash','bank_transfer','card','online','other') NOT NULL DEFAULT 'cash',
  `payment_date` DATE NOT NULL,
  `reference_number` VARCHAR(100) DEFAULT NULL,
  `payment_link_url` VARCHAR(500) DEFAULT NULL COMMENT 'External Stripe payment link',
  `payment_link_status` ENUM('pending','paid') DEFAULT NULL,
  `notes` TEXT DEFAULT NULL,
  `journal_id` INT(11) DEFAULT NULL COMMENT 'FK to re_journal_headers for payment posting',
  `recorded_by` INT(11) DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_payments_booking` (`booking_id`),
  KEY `idx_payments_company` (`company_id`),
  KEY `idx_payments_date` (`payment_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- 4. Unit Photos
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `ars_unit_photos` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `unit_id` INT(11) NOT NULL,
  `company_id` INT(11) NOT NULL,
  `file_path` VARCHAR(500) NOT NULL,
  `file_name` VARCHAR(255) NOT NULL,
  `sort_order` INT NOT NULL DEFAULT 0,
  `is_primary` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_photos_unit` (`unit_id`),
  KEY `idx_photos_company` (`company_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- 5. Blocked Dates (admin-blocked periods)
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `ars_blocked_dates` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `unit_id` INT(11) NOT NULL,
  `company_id` INT(11) NOT NULL,
  `start_date` DATE NOT NULL,
  `end_date` DATE NOT NULL,
  `reason` VARCHAR(255) DEFAULT NULL,
  `created_by` INT(11) DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_blocked_unit` (`unit_id`),
  KEY `idx_blocked_dates` (`start_date`, `end_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
