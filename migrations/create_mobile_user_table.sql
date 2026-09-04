-- =====================================================
-- Create mobile_user Table
-- For mobile app authentication and profile management
-- Links to customers table
-- =====================================================

USE `bestsys`;

CREATE TABLE IF NOT EXISTS `mobile_user` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `customer_id` INT(11) NOT NULL COMMENT 'Link to customers table',
  `phone` VARCHAR(50) NOT NULL COMMENT 'Login phone number',
  `email` VARCHAR(255) DEFAULT NULL,
  `name` VARCHAR(255) NOT NULL,
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
  UNIQUE INDEX `idx_customer` (`customer_id`),
  UNIQUE INDEX `idx_phone` (`phone`),
  INDEX `idx_email` (`email`),
  INDEX `idx_active` (`is_active`),
  CONSTRAINT `fk_mobile_user_customer` 
    FOREIGN KEY (`customer_id`) 
    REFERENCES `customers`(`id`) 
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Add address fields to mobile_user for convenience
ALTER TABLE `mobile_user`
ADD COLUMN IF NOT EXISTS `default_address` TEXT DEFAULT NULL COMMENT 'Default service address' AFTER `gender`;

-- Create mobile_user_addresses table for multiple addresses
CREATE TABLE IF NOT EXISTS `mobile_user_addresses` (
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

SELECT 'Migration completed - mobile_user and mobile_user_addresses tables created' AS message;

