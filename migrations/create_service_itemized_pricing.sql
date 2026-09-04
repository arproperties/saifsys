-- Migration: Service itemized pricing tables
-- Description: Adds support for per-item pricing, item groups, and booking items
-- Date: 2025-11-08

SET FOREIGN_KEY_CHECKS = 0;

-- =====================================================
-- SERVICE ITEM GROUPS
-- =====================================================
CREATE TABLE IF NOT EXISTS `service_item_groups` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `service_id` INT(11) NOT NULL,
  `name` VARCHAR(255) NOT NULL,
  `description` TEXT DEFAULT NULL,
  `image_url` VARCHAR(500) DEFAULT NULL,
  `sort_order` INT(11) NOT NULL DEFAULT 0,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  CONSTRAINT `fk_item_group_service`
    FOREIGN KEY (`service_id`) REFERENCES `services`(`id`) ON DELETE CASCADE,
  INDEX `idx_group_service` (`service_id`),
  INDEX `idx_group_sort_active` (`is_active`, `sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- =====================================================
-- SERVICE ITEMS
-- =====================================================
CREATE TABLE IF NOT EXISTS `service_items` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `service_id` INT(11) NOT NULL,
  `group_id` INT(11) DEFAULT NULL,
  `name` VARCHAR(255) NOT NULL,
  `description` TEXT DEFAULT NULL,
  `price` DECIMAL(10,2) NOT NULL,
  `original_price` DECIMAL(10,2) DEFAULT NULL,
  `duration_minutes` INT(11) DEFAULT NULL,
  `badge_text` VARCHAR(100) DEFAULT NULL,
  `image_url` VARCHAR(500) DEFAULT NULL,
  `min_quantity` INT(11) NOT NULL DEFAULT 0,
  `max_quantity` INT(11) NOT NULL DEFAULT 10,
  `default_quantity` INT(11) NOT NULL DEFAULT 0,
  `sort_order` INT(11) NOT NULL DEFAULT 0,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `metadata` JSON DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  CONSTRAINT `fk_service_item_service`
    FOREIGN KEY (`service_id`) REFERENCES `services`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_service_item_group`
    FOREIGN KEY (`group_id`) REFERENCES `service_item_groups`(`id`) ON DELETE CASCADE,
  INDEX `idx_item_service` (`service_id`),
  INDEX `idx_item_group` (`group_id`),
  INDEX `idx_item_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- =====================================================
-- ONLINE BOOKING ITEMS
-- =====================================================
CREATE TABLE IF NOT EXISTS `online_booking_items` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `booking_id` INT(11) NOT NULL,
  `service_item_id` INT(11) DEFAULT NULL,
  `item_name` VARCHAR(255) NOT NULL,
  `group_name` VARCHAR(255) DEFAULT NULL,
  `quantity` INT(11) NOT NULL DEFAULT 1,
  `unit_price` DECIMAL(10,2) NOT NULL,
  `original_price` DECIMAL(10,2) DEFAULT NULL,
  `line_total` DECIMAL(10,2) NOT NULL,
  `duration_minutes` INT(11) DEFAULT NULL,
  `sort_order` INT(11) NOT NULL DEFAULT 0,
  `metadata` JSON DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  CONSTRAINT `fk_booking_item_booking`
    FOREIGN KEY (`booking_id`) REFERENCES `online_bookings`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_booking_item_service_item`
    FOREIGN KEY (`service_item_id`) REFERENCES `service_items`(`id`) ON DELETE SET NULL,
  INDEX `idx_booking_item_booking` (`booking_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- =====================================================
-- UPDATE PRICING RULES ENUM WITH PER-ITEM (if missing)
-- =====================================================
ALTER TABLE `pricing_rules`
  MODIFY `calculation_type`
    ENUM('fixed', 'hourly', 'per_sqm', 'per_room', 'per_item', 'custom')
    NOT NULL;

-- Ensure existing per-item rule exists
INSERT INTO `pricing_rules` (`name`, `calculation_type`, `description`)
SELECT 'Per Item Pricing', 'per_item', 'Pricing based on selected catalog items'
WHERE NOT EXISTS (
  SELECT 1 FROM `pricing_rules` WHERE `calculation_type` = 'per_item'
);

SET FOREIGN_KEY_CHECKS = 1;

SELECT 'Service itemized pricing tables created successfully' AS message;

