-- Mobile Booking System Migration - Custom for Existing Database
-- Created: 2025-11-02
-- Description: Creates tables for online booking system, works with existing services table

-- Disable foreign key checks temporarily
SET FOREIGN_KEY_CHECKS = 0;

-- =====================================================
-- 1. ENHANCE EXISTING SERVICES TABLE
-- =====================================================
-- Add missing columns to existing services table
ALTER TABLE `services` 
ADD COLUMN IF NOT EXISTS `description` TEXT AFTER `name`,
ADD COLUMN IF NOT EXISTS `category` VARCHAR(100) NOT NULL DEFAULT 'General' AFTER `description`,
ADD COLUMN IF NOT EXISTS `price` DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER `category`,
ADD COLUMN IF NOT EXISTS `duration_minutes` INT(11) NOT NULL DEFAULT 60 AFTER `price`,
ADD COLUMN IF NOT EXISTS `image_url` VARCHAR(500) DEFAULT NULL AFTER `duration_minutes`,
ADD COLUMN IF NOT EXISTS `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER `created_at`;

-- Add indexes
ALTER TABLE `services`
ADD INDEX IF NOT EXISTS `idx_category` (`category`),
ADD INDEX IF NOT EXISTS `idx_is_active` (`is_active`);

-- Update existing services with default data
UPDATE `services` SET 
  `description` = CONCAT('Professional ', LOWER(name), ' service'),
  `category` = 'General',
  `price` = 150.00,
  `duration_minutes` = 60
WHERE `description` IS NULL OR `description` = '';

-- =====================================================
-- 2. ADD SAMPLE SERVICES (if needed)
-- =====================================================
-- Add more sample services for mobile booking
INSERT IGNORE INTO `services` (`name`, `description`, `category`, `price`, `duration_minutes`, `is_active`) VALUES
('Home Cleaning', 'Professional deep cleaning service for your home', 'Cleaning', 150.00, 120, 1),
('AC Maintenance', 'Complete AC servicing and maintenance', 'Maintenance', 200.00, 90, 1),
('Plumbing Repair', 'Expert plumbing services for all your needs', 'Maintenance', 180.00, 60, 1),
('Electrical Work', 'Licensed electrician for home repairs', 'Maintenance', 220.00, 60, 1),
('Painting Service', 'Professional painting for interiors and exteriors', 'Renovation', 300.00, 240, 1),
('Pest Control', 'Complete pest control and prevention', 'Cleaning', 250.00, 120, 1),
('Carpet Cleaning', 'Deep carpet and upholstery cleaning', 'Cleaning', 100.00, 60, 1),
('Handyman Service', 'General handyman services for small repairs', 'Maintenance', 150.00, 90, 1);

-- =====================================================
-- 3. ENHANCE EMPLOYEES TABLE
-- =====================================================
-- Add bookable flag to employees
ALTER TABLE `employees` 
ADD COLUMN IF NOT EXISTS `is_bookable` TINYINT(1) NOT NULL DEFAULT 0 AFTER `status`,
ADD COLUMN IF NOT EXISTS `booking_skills` TEXT DEFAULT NULL COMMENT 'JSON array of service IDs' AFTER `is_bookable`;

-- =====================================================
-- 4. EMPLOYEE SHIFTS TABLE
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
  INDEX `idx_employee` (`employee_id`),
  INDEX `idx_day` (`day_of_week`),
  CONSTRAINT `fk_shifts_employee` FOREIGN KEY (`employee_id`) REFERENCES `employees`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- =====================================================
-- 5. EMPLOYEE TIME OFF TABLE
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
  INDEX `idx_employee` (`employee_id`),
  INDEX `idx_dates` (`date_from`, `date_to`),
  CONSTRAINT `fk_timeoff_employee` FOREIGN KEY (`employee_id`) REFERENCES `employees`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- =====================================================
-- 6. CUSTOMERS TABLE (for mobile app users)
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
-- 7. ONLINE BOOKINGS TABLE
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
  INDEX `idx_customer` (`customer_id`),
  INDEX `idx_service` (`service_id`),
  INDEX `idx_employee` (`employee_id`),
  INDEX `idx_status` (`status`),
  INDEX `idx_scheduled` (`scheduled_date`, `scheduled_time`),
  INDEX `idx_customer_phone` (`customer_phone`),
  INDEX `idx_created_at` (`created_at`),
  INDEX `idx_email` (`customer_email`),
  CONSTRAINT `fk_booking_customer` FOREIGN KEY (`customer_id`) REFERENCES `customers`(`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_booking_service` FOREIGN KEY (`service_id`) REFERENCES `services`(`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_booking_employee` FOREIGN KEY (`employee_id`) REFERENCES `employees`(`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_booking_confirmed_by` FOREIGN KEY (`confirmed_by`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- =====================================================
-- 8. BOOKING EVENTS TABLE (Audit Trail)
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
  INDEX `idx_booking` (`booking_id`),
  INDEX `idx_event_type` (`event_type`),
  INDEX `idx_created` (`created_at`),
  CONSTRAINT `fk_event_booking` FOREIGN KEY (`booking_id`) REFERENCES `online_bookings`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_event_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_event_customer` FOREIGN KEY (`customer_id`) REFERENCES `customers`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- =====================================================
-- 9. VIEWS
-- =====================================================

-- View: Available Workers for Booking
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

-- View: Online Bookings Summary
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
-- 10. ADD SAMPLE SHIFTS FOR FIRST ACTIVE EMPLOYEE
-- =====================================================
-- This creates Monday-Friday 9 AM - 6 PM shifts for the first active employee
-- You can adjust this or add more employees later

INSERT INTO employee_shifts (employee_id, day_of_week, start_time, end_time, is_active)
SELECT 
  id as employee_id,
  1 as day_of_week,
  '09:00:00' as start_time,
  '18:00:00' as end_time,
  1 as is_active
FROM employees 
WHERE status = 'active' 
LIMIT 1
ON DUPLICATE KEY UPDATE start_time = VALUES(start_time);

INSERT INTO employee_shifts (employee_id, day_of_week, start_time, end_time, is_active)
SELECT 
  id as employee_id,
  2 as day_of_week,
  '09:00:00' as start_time,
  '18:00:00' as end_time,
  1 as is_active
FROM employees 
WHERE status = 'active' 
LIMIT 1
ON DUPLICATE KEY UPDATE start_time = VALUES(start_time);

INSERT INTO employee_shifts (employee_id, day_of_week, start_time, end_time, is_active)
SELECT 
  id as employee_id,
  3 as day_of_week,
  '09:00:00' as start_time,
  '18:00:00' as end_time,
  1 as is_active
FROM employees 
WHERE status = 'active' 
LIMIT 1
ON DUPLICATE KEY UPDATE start_time = VALUES(start_time);

INSERT INTO employee_shifts (employee_id, day_of_week, start_time, end_time, is_active)
SELECT 
  id as employee_id,
  4 as day_of_week,
  '09:00:00' as start_time,
  '18:00:00' as end_time,
  1 as is_active
FROM employees 
WHERE status = 'active' 
LIMIT 1
ON DUPLICATE KEY UPDATE start_time = VALUES(start_time);

INSERT INTO employee_shifts (employee_id, day_of_week, start_time, end_time, is_active)
SELECT 
  id as employee_id,
  5 as day_of_week,
  '09:00:00' as start_time,
  '18:00:00' as end_time,
  1 as is_active
FROM employees 
WHERE status = 'active' 
LIMIT 1
ON DUPLICATE KEY UPDATE start_time = VALUES(start_time);

-- Mark the first active employee as bookable
UPDATE employees 
SET is_bookable = 1 
WHERE status = 'active' 
LIMIT 1;

-- =====================================================
-- MIGRATION COMPLETE
-- =====================================================

-- Re-enable foreign key checks
SET FOREIGN_KEY_CHECKS = 1;

SELECT 
  'Mobile booking system migration completed!' AS message,
  (SELECT COUNT(*) FROM services) AS total_services,
  (SELECT COUNT(*) FROM employees WHERE is_bookable = 1) AS bookable_employees,
  (SELECT COUNT(*) FROM employee_shifts) AS configured_shifts;

