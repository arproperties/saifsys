-- =====================================================
-- Fix Mobile User and Bookings to Link to Client Table
-- This integrates mobile app with the management system
-- =====================================================

USE `bestsys`;

-- Step 1: Drop existing mobile_user table and recreate with client reference
DROP TABLE IF EXISTS `mobile_user_addresses`;
DROP TABLE IF EXISTS `mobile_user`;

-- Step 2: Recreate mobile_user linked to client table
CREATE TABLE `mobile_user` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `client_id` INT(11) NOT NULL COMMENT 'Link to client table (management system)',
  `phone` VARCHAR(50) NOT NULL COMMENT 'Login phone number',
  `email` VARCHAR(255) DEFAULT NULL,
  `avatar_url` VARCHAR(500) DEFAULT NULL COMMENT 'Profile picture URL',
  `date_of_birth` DATE DEFAULT NULL,
  `gender` ENUM('male', 'female', 'other') DEFAULT NULL,
  `language_preference` VARCHAR(10) DEFAULT 'en' COMMENT 'en or ar',
  `notification_enabled` TINYINT(1) DEFAULT 1,
  `email_notification` TINYINT(1) DEFAULT 1,
  `sms_notification` TINYINT(1) DEFAULT 1,
  `push_notification` TINYINT(1) DEFAULT 1,
  `device_token` VARCHAR(255) DEFAULT NULL COMMENT 'For push notifications',
  `last_login_at` DATETIME DEFAULT NULL,
  `last_active_at` DATETIME DEFAULT NULL,
  `is_active` TINYINT(1) DEFAULT 1,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE INDEX `idx_client` (`client_id`),
  UNIQUE INDEX `idx_phone` (`phone`),
  INDEX `idx_email` (`email`),
  INDEX `idx_active` (`is_active`),
  CONSTRAINT `fk_mobile_user_client` 
    FOREIGN KEY (`client_id`) 
    REFERENCES `client`(`id`) 
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Step 3: Recreate addresses table
CREATE TABLE `mobile_user_addresses` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `mobile_user_id` INT(11) NOT NULL,
  `address_type` ENUM('home', 'office', 'other') DEFAULT 'home',
  `label` VARCHAR(100) DEFAULT NULL COMMENT 'Custom label like "My Home", "Office"',
  `full_address` TEXT NOT NULL,
  `building_name` VARCHAR(255) DEFAULT NULL,
  `floor_number` VARCHAR(50) DEFAULT NULL,
  `apartment_number` VARCHAR(50) DEFAULT NULL,
  `street` VARCHAR(255) DEFAULT NULL,
  `area` VARCHAR(255) DEFAULT NULL,
  `city` VARCHAR(100) DEFAULT 'Dubai',
  `landmark` VARCHAR(255) DEFAULT NULL,
  `latitude` DECIMAL(10, 8) DEFAULT NULL,
  `longitude` DECIMAL(11, 8) DEFAULT NULL,
  `is_default` TINYINT(1) DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `idx_user` (`mobile_user_id`),
  INDEX `idx_default` (`is_default`),
  CONSTRAINT `fk_address_user` 
    FOREIGN KEY (`mobile_user_id`) 
    REFERENCES `mobile_user`(`id`) 
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Step 4: Update online_bookings to reference client instead of customers
-- First, drop the old foreign key
ALTER TABLE `online_bookings`
DROP FOREIGN KEY IF EXISTS `fk_booking_customer`;

-- Rename the column to be clearer
ALTER TABLE `online_bookings`
CHANGE COLUMN `customer_id` `client_id` INT(11) DEFAULT NULL COMMENT 'Link to client table (management system)';

-- Step 5: Migrate existing customers to client table
INSERT INTO `client` (
  `client_name`, `email`, `mobile_num`, `cell_num`, `rate`, `payment`, 
  `terms`, `is_active`, `address`, `currency`, `client_status`
)
SELECT 
  c.name,
  c.email,
  c.phone,
  '',
  0.00,
  'D', -- Daily payment
  'cash',
  c.is_verified,
  c.address,
  'AED',
  'active'
FROM `customers` c
WHERE NOT EXISTS (
  SELECT 1 FROM `client` cl WHERE cl.mobile_num = c.phone
);

-- Step 6: Update online_bookings to link to client records
UPDATE `online_bookings` ob
INNER JOIN `customers` cust ON ob.client_id = cust.id
INNER JOIN `client` cl ON cl.mobile_num = cust.phone
SET ob.client_id = cl.id;

-- Step 7: Set any orphaned bookings to NULL
UPDATE `online_bookings` ob
SET ob.client_id = NULL
WHERE ob.client_id IS NOT NULL 
AND NOT EXISTS (SELECT 1 FROM `client` c WHERE c.id = ob.client_id);

-- Step 8: Now add the foreign key constraint
ALTER TABLE `online_bookings`
ADD CONSTRAINT `fk_booking_client` 
FOREIGN KEY (`client_id`) 
REFERENCES `client`(`id`) 
ON DELETE SET NULL;

SELECT 'Migration completed - mobile_user and online_bookings now linked to client table' AS message;

