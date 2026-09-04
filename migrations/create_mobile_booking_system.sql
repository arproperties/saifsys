-- Mobile Booking System Migration
-- Created: 2025-11-01
-- Description: Creates tables for online booking system with mobile app support

-- =====================================================
-- 1. SERVICES TABLE
-- =====================================================
CREATE TABLE IF NOT EXISTS `services` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(255) NOT NULL,
  `description` TEXT,
  `category` VARCHAR(100) NOT NULL DEFAULT 'General',
  `price` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `duration_minutes` INT(11) NOT NULL DEFAULT 60,
  `image_url` VARCHAR(500) DEFAULT NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `idx_category` (`category`),
  INDEX `idx_is_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- =====================================================
-- 2. EMPLOYEE BOOKABLE FLAG (ALTER EXISTING TABLE)
-- =====================================================
ALTER TABLE `employees` 
ADD COLUMN IF NOT EXISTS `is_bookable` TINYINT(1) NOT NULL DEFAULT 0 AFTER `status`;

ALTER TABLE `employees`
ADD COLUMN IF NOT EXISTS `booking_skills` TEXT DEFAULT NULL COMMENT 'JSON array of service IDs' AFTER `is_bookable`;

-- =====================================================
-- 3. EMPLOYEE SHIFTS TABLE
-- =====================================================
CREATE TABLE IF NOT EXISTS `employee_shifts` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `employee_id` INT(11) NOT NULL,
  `day_of_week` TINYINT(1) NOT NULL COMMENT '0=Sunday, 1=Monday, ..., 6=Saturday',
  `start_time` TIME NOT NULL,
  `end_time` TIME NOT NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  FOREIGN KEY (`employee_id`) REFERENCES `employees`(`id`) ON DELETE CASCADE,
  INDEX `idx_employee` (`employee_id`),
  INDEX `idx_day` (`day_of_week`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- =====================================================
-- 4. EMPLOYEE TIME OFF TABLE
-- =====================================================
CREATE TABLE IF NOT EXISTS `employee_time_off` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `employee_id` INT(11) NOT NULL,
  `date_from` DATE NOT NULL,
  `date_to` DATE NOT NULL,
  `reason` VARCHAR(255) DEFAULT NULL,
  `created_by` INT(11) DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  FOREIGN KEY (`employee_id`) REFERENCES `employees`(`id`) ON DELETE CASCADE,
  INDEX `idx_employee` (`employee_id`),
  INDEX `idx_dates` (`date_from`, `date_to`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- =====================================================
-- 5. CUSTOMERS TABLE (for guest/registered mobile users)
-- =====================================================
CREATE TABLE IF NOT EXISTS `customers` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(255) NOT NULL,
  `phone` VARCHAR(50) NOT NULL,
  `email` VARCHAR(255) DEFAULT NULL,
  `address` TEXT DEFAULT NULL,
  `otp_code` VARCHAR(10) DEFAULT NULL,
  `otp_expires_at` DATETIME DEFAULT NULL,
  `is_verified` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE INDEX `idx_phone` (`phone`),
  INDEX `idx_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- =====================================================
-- 6. ONLINE BOOKINGS TABLE
-- =====================================================
CREATE TABLE IF NOT EXISTS `online_bookings` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `customer_id` INT(11) DEFAULT NULL COMMENT 'NULL for guest bookings',
  `service_id` INT(11) NOT NULL,
  `employee_id` INT(11) DEFAULT NULL COMMENT 'Assigned worker',
  `scheduled_date` DATE NOT NULL,
  `scheduled_time` TIME NOT NULL,
  `customer_name` VARCHAR(255) NOT NULL,
  `customer_phone` VARCHAR(50) NOT NULL,
  `customer_email` VARCHAR(255) NOT NULL,
  `address` TEXT,
  `notes` TEXT,
  `total_price` DECIMAL(10,2) NOT NULL,
  `status` ENUM('pending', 'confirmed', 'assigned', 'in_progress', 'completed', 'cancelled', 'no_show') NOT NULL DEFAULT 'pending',
  `work_order_id` INT(11) DEFAULT NULL COMMENT 'Link to orders table when converted',
  `confirmed_at` DATETIME DEFAULT NULL,
  `confirmed_by` INT(11) DEFAULT NULL,
  `cancelled_at` DATETIME DEFAULT NULL,
  `cancellation_reason` VARCHAR(500) DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  FOREIGN KEY (`customer_id`) REFERENCES `customers`(`id`) ON DELETE SET NULL,
  FOREIGN KEY (`service_id`) REFERENCES `services`(`id`) ON DELETE RESTRICT,
  FOREIGN KEY (`employee_id`) REFERENCES `employees`(`id`) ON DELETE SET NULL,
  FOREIGN KEY (`confirmed_by`) REFERENCES `users`(`id`) ON DELETE SET NULL,
  INDEX `idx_customer` (`customer_id`),
  INDEX `idx_service` (`service_id`),
  INDEX `idx_employee` (`employee_id`),
  INDEX `idx_status` (`status`),
  INDEX `idx_scheduled` (`scheduled_date`, `scheduled_time`),
  INDEX `idx_customer_phone` (`customer_phone`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- =====================================================
-- 7. BOOKING EVENTS TABLE (Audit Trail)
-- =====================================================
CREATE TABLE IF NOT EXISTS `booking_events` (
  `id` BIGINT(20) NOT NULL AUTO_INCREMENT,
  `booking_id` INT(11) NOT NULL,
  `event_type` VARCHAR(50) NOT NULL COMMENT 'created, confirmed, assigned, cancelled, etc.',
  `event_data` JSON DEFAULT NULL COMMENT 'Additional event metadata',
  `user_id` INT(11) DEFAULT NULL COMMENT 'Staff user who triggered event',
  `customer_id` INT(11) DEFAULT NULL COMMENT 'Customer who triggered event',
  `ip_address` VARCHAR(45) DEFAULT NULL,
  `user_agent` VARCHAR(500) DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  FOREIGN KEY (`booking_id`) REFERENCES `online_bookings`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE SET NULL,
  FOREIGN KEY (`customer_id`) REFERENCES `customers`(`id`) ON DELETE SET NULL,
  INDEX `idx_booking` (`booking_id`),
  INDEX `idx_event_type` (`event_type`),
  INDEX `idx_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- =====================================================
-- 8. SAMPLE DATA - Services
-- =====================================================
INSERT INTO `services` (`name`, `description`, `category`, `price`, `duration_minutes`, `is_active`) VALUES
('Home Cleaning', 'Professional deep cleaning service for your home', 'Cleaning', 150.00, 120, 1),
('AC Maintenance', 'Complete AC servicing and maintenance', 'Maintenance', 200.00, 90, 1),
('Plumbing Repair', 'Expert plumbing services for all your needs', 'Maintenance', 180.00, 60, 1),
('Electrical Work', 'Licensed electrician for home repairs', 'Maintenance', 220.00, 60, 1),
('Painting Service', 'Professional painting for interiors and exteriors', 'Renovation', 300.00, 240, 1),
('Pest Control', 'Complete pest control and prevention', 'Cleaning', 250.00, 120, 1),
('Carpet Cleaning', 'Deep carpet and upholstery cleaning', 'Cleaning', 100.00, 60, 1),
('Handyman Service', 'General handyman services for small repairs', 'Maintenance', 150.00, 90, 1)
ON DUPLICATE KEY UPDATE `name` = VALUES(`name`);

-- =====================================================
-- 9. SAMPLE DATA - Employee Shifts (Monday to Friday, 9 AM to 6 PM)
-- =====================================================
-- Note: This creates default shifts for bookable employees
-- Adjust employee IDs based on your actual data

-- =====================================================
-- 10. VIEW: Available Workers for Booking
-- =====================================================
CREATE OR REPLACE VIEW `v_bookable_workers` AS
SELECT 
  e.id,
  e.employee_code,
  e.full_name,
  e.email,
  e.phone,
  e.position_title,
  e.booking_skills,
  e.status,
  e.is_bookable
FROM employees e
WHERE e.is_bookable = 1 
  AND e.status = 'active';

-- =====================================================
-- 11. VIEW: Online Bookings Summary
-- =====================================================
CREATE OR REPLACE VIEW `v_online_bookings_summary` AS
SELECT 
  ob.id,
  ob.customer_name,
  ob.customer_phone,
  ob.customer_email,
  s.name AS service_name,
  s.category AS service_category,
  e.full_name AS employee_name,
  ob.scheduled_date,
  ob.scheduled_time,
  ob.total_price,
  ob.status,
  ob.address,
  ob.notes,
  ob.created_at,
  ob.confirmed_at,
  ob.work_order_id
FROM online_bookings ob
LEFT JOIN services s ON ob.service_id = s.id
LEFT JOIN employees e ON ob.employee_id = e.id
ORDER BY ob.created_at DESC;

-- =====================================================
-- INDEXES FOR PERFORMANCE
-- =====================================================
-- Additional indexes for common queries
ALTER TABLE `online_bookings` 
ADD INDEX IF NOT EXISTS `idx_created_at` (`created_at`),
ADD INDEX IF NOT EXISTS `idx_email` (`customer_email`);

-- =====================================================
-- GRANT PERMISSIONS (if needed for separate API user)
-- =====================================================
-- GRANT SELECT, INSERT, UPDATE ON bestsys.services TO 'api_user'@'localhost';
-- GRANT SELECT, INSERT, UPDATE ON bestsys.online_bookings TO 'api_user'@'localhost';
-- GRANT SELECT ON bestsys.employees TO 'api_user'@'localhost';

-- =====================================================
-- MIGRATION COMPLETE
-- =====================================================
SELECT 'Mobile booking system tables created successfully!' AS message;

