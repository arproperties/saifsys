-- Create coupons table for coupon code discounts
CREATE TABLE IF NOT EXISTS `coupons` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `coupon_code` VARCHAR(50) NOT NULL UNIQUE,
  `coupon_type` VARCHAR(50) DEFAULT 'discount',
  `title` VARCHAR(255) NOT NULL,
  `title_en` VARCHAR(255) DEFAULT NULL,
  `title_ar` VARCHAR(255) DEFAULT NULL,
  `discount_type` ENUM('category', 'service', 'mixed') NOT NULL DEFAULT 'service',
  `category_id` INT(11) DEFAULT NULL,
  `service_id` INT(11) DEFAULT NULL,
  `zone_id` INT(11) DEFAULT NULL,
  `amount_type` ENUM('percentage', 'fixed') NOT NULL DEFAULT 'percentage',
  `amount` DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
  `min_purchase_amount` DECIMAL(10, 2) DEFAULT 0.00,
  `max_discount_amount` DECIMAL(10, 2) DEFAULT NULL,
  `start_date` DATETIME NOT NULL,
  `end_date` DATETIME NOT NULL,
  `limit_per_user` INT(11) DEFAULT NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_coupon_code` (`coupon_code`),
  INDEX `idx_category` (`category_id`),
  INDEX `idx_service` (`service_id`),
  INDEX `idx_active_dates` (`is_active`, `start_date`, `end_date`),
  INDEX `idx_discount_type` (`discount_type`),
  CONSTRAINT `fk_coupon_category` FOREIGN KEY (`category_id`) REFERENCES `service_categories` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_coupon_service` FOREIGN KEY (`service_id`) REFERENCES `services` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Create coupon usage tracking table
CREATE TABLE IF NOT EXISTS `coupon_usages` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `coupon_id` INT(11) NOT NULL,
  `customer_id` INT(11) NOT NULL,
  `booking_id` INT(11) DEFAULT NULL,
  `discount_amount` DECIMAL(10, 2) NOT NULL,
  `used_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `idx_coupon` (`coupon_id`),
  INDEX `idx_customer` (`customer_id`),
  INDEX `idx_booking` (`booking_id`),
  CONSTRAINT `fk_coupon_usage_coupon` FOREIGN KEY (`coupon_id`) REFERENCES `coupons` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_coupon_usage_customer` FOREIGN KEY (`customer_id`) REFERENCES `client` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_coupon_usage_booking` FOREIGN KEY (`booking_id`) REFERENCES `online_bookings` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
