-- Enhanced Mobile Booking System with Nested Categories & Pricing Rules
-- Run this migration to add advanced features matching Justlife app structure

SET FOREIGN_KEY_CHECKS = 0;

-- =====================================================
-- CATEGORIES TABLE (with parent/child support)
-- =====================================================
CREATE TABLE IF NOT EXISTS `service_categories` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `parent_id` INT(11) DEFAULT NULL,
  `name` VARCHAR(255) NOT NULL,
  `name_ar` VARCHAR(255) DEFAULT NULL,
  `description` TEXT DEFAULT NULL,
  `icon_url` VARCHAR(500) DEFAULT NULL,
  `image_url` VARCHAR(500) DEFAULT NULL,
  `sort_order` INT(11) NOT NULL DEFAULT 0,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `show_on_home` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  FOREIGN KEY (`parent_id`) REFERENCES `service_categories`(`id`) ON DELETE SET NULL,
  INDEX `idx_parent` (`parent_id`),
  INDEX `idx_active_home` (`is_active`, `show_on_home`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- =====================================================
-- PRICING RULES (different calculation methods)
-- =====================================================
CREATE TABLE IF NOT EXISTS `pricing_rules` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(255) NOT NULL,
  `calculation_type` ENUM('fixed', 'hourly', 'per_sqm', 'per_room', 'per_item', 'custom') NOT NULL,
  `description` TEXT DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- =====================================================
-- SERVICE OPTIONS (hours, professionals, materials, etc.)
-- =====================================================
CREATE TABLE IF NOT EXISTS `service_options` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `service_id` INT(11) NOT NULL,
  `option_type` ENUM('hours', 'professionals', 'materials', 'frequency', 'area', 'rooms', 'custom') NOT NULL,
  `option_name` VARCHAR(255) NOT NULL,
  `option_values` JSON NOT NULL COMMENT 'Array of possible values',
  `is_required` TINYINT(1) NOT NULL DEFAULT 1,
  `affects_price` TINYINT(1) NOT NULL DEFAULT 1,
  `sort_order` INT(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  FOREIGN KEY (`service_id`) REFERENCES `services`(`id`) ON DELETE CASCADE,
  INDEX `idx_service` (`service_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- =====================================================
-- UPDATE SERVICES TABLE
-- =====================================================
ALTER TABLE `services`
ADD COLUMN IF NOT EXISTS `category_id` INT(11) DEFAULT NULL AFTER `id`,
ADD COLUMN IF NOT EXISTS `pricing_rule_id` INT(11) DEFAULT NULL AFTER `category_id`,
ADD COLUMN IF NOT EXISTS `base_price` DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER `price`,
ADD COLUMN IF NOT EXISTS `price_per_unit` DECIMAL(10,2) DEFAULT NULL AFTER `base_price`,
ADD COLUMN IF NOT EXISTS `min_price` DECIMAL(10,2) DEFAULT NULL AFTER `price_per_unit`,
ADD COLUMN IF NOT EXISTS `max_price` DECIMAL(10,2) DEFAULT NULL AFTER `min_price`,
ADD COLUMN IF NOT EXISTS `min_hours` DECIMAL(4,1) DEFAULT NULL AFTER `duration_minutes`,
ADD COLUMN IF NOT EXISTS `max_hours` DECIMAL(4,1) DEFAULT NULL AFTER `min_hours`,
ADD COLUMN IF NOT EXISTS `min_professionals` INT(11) DEFAULT 1 AFTER `max_hours`,
ADD COLUMN IF NOT EXISTS `max_professionals` INT(11) DEFAULT 4 AFTER `min_professionals`,
ADD COLUMN IF NOT EXISTS `requires_materials` TINYINT(1) NOT NULL DEFAULT 0 AFTER `max_professionals`,
ADD COLUMN IF NOT EXISTS `allows_frequency` TINYINT(1) NOT NULL DEFAULT 1 AFTER `requires_materials`,
ADD COLUMN IF NOT EXISTS `sort_order` INT(11) NOT NULL DEFAULT 0 AFTER `allows_frequency`;

-- Add foreign keys if they don't exist
ALTER TABLE `services`
ADD CONSTRAINT `fk_service_category` FOREIGN KEY (`category_id`) REFERENCES `service_categories`(`id`) ON DELETE SET NULL,
ADD CONSTRAINT `fk_service_pricing_rule` FOREIGN KEY (`pricing_rule_id`) REFERENCES `pricing_rules`(`id`) ON DELETE SET NULL;

-- =====================================================
-- FREQUENCY DISCOUNTS
-- =====================================================
CREATE TABLE IF NOT EXISTS `frequency_discounts` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `frequency_type` ENUM('one_time', 'weekly', 'biweekly', 'multiple') NOT NULL,
  `frequency_name` VARCHAR(255) NOT NULL,
  `discount_percentage` DECIMAL(5,2) NOT NULL DEFAULT 0.00,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `sort_order` INT(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- =====================================================
-- PROMOTIONAL BANNERS
-- =====================================================
CREATE TABLE IF NOT EXISTS `promotional_banners` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `title` VARCHAR(255) NOT NULL,
  `description` TEXT DEFAULT NULL,
  `image_url` VARCHAR(500) NOT NULL,
  `link_url` VARCHAR(500) DEFAULT NULL,
  `sort_order` INT(11) NOT NULL DEFAULT 0,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `start_date` DATETIME DEFAULT NULL,
  `end_date` DATETIME DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `idx_active_dates` (`is_active`, `start_date`, `end_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- =====================================================
-- INSERT DEFAULT DATA
-- =====================================================

-- Pricing Rules
INSERT INTO `pricing_rules` (`id`, `name`, `calculation_type`, `description`) VALUES
(1, 'Hourly Rate', 'hourly', 'Price based on hours × professionals'),
(2, 'Fixed Price', 'fixed', 'Single fixed price regardless of duration'),
(3, 'Per Square Meter', 'per_sqm', 'Price per square meter of area'),
(4, 'Per Room', 'per_room', 'Price per number of rooms')
ON DUPLICATE KEY UPDATE `name` = VALUES(`name`);

-- Main Categories
INSERT INTO `service_categories` (`id`, `parent_id`, `name`, `name_ar`, `description`, `icon_url`, `sort_order`, `is_active`, `show_on_home`) VALUES
(1, NULL, 'Cleaning', 'التنظيف', 'Professional cleaning services for your home and office', '🧹', 1, 1, 1),
(2, NULL, 'Pest Control', 'مكافحة الآفات', 'Expert pest control and disinfection services', '🐛', 2, 1, 1)
ON DUPLICATE KEY UPDATE `name` = VALUES(`name`);

-- Cleaning Sub-Categories
INSERT INTO `service_categories` (`id`, `parent_id`, `name`, `name_ar`, `description`, `icon_url`, `sort_order`, `is_active`, `show_on_home`) VALUES
(3, 1, 'Home Cleaning', 'تنظيف المنزل', 'Regular home cleaning service', '🏠', 1, 1, 0),
(4, 1, 'Furniture Cleaning', 'تنظيف الأثاث', 'Deep cleaning for sofas, carpets and upholstery', '🛋️', 2, 1, 0),
(5, 1, 'Home Deep Cleaning', 'تنظيف عميق', 'Comprehensive deep cleaning service', '✨', 3, 1, 0)
ON DUPLICATE KEY UPDATE `name` = VALUES(`name`);

-- Pest Control Sub-Categories
INSERT INTO `service_categories` (`id`, `parent_id`, `name`, `name_ar`, `description`, `icon_url`, `sort_order`, `is_active`, `show_on_home`) VALUES
(6, 2, 'Pest Control', 'مكافحة الحشرات', 'Complete pest control treatment', '🦟', 1, 1, 0),
(7, 2, 'Disinfection', 'التعقيم', 'Professional disinfection and sanitization', '💧', 2, 1, 0)
ON DUPLICATE KEY UPDATE `name` = VALUES(`name`);

-- Update existing services to use new category structure
UPDATE `services` SET 
  `category_id` = 3,
  `pricing_rule_id` = 1,
  `base_price` = 80.00,
  `price_per_unit` = 40.00,
  `min_hours` = 2,
  `max_hours` = 8,
  `min_professionals` = 1,
  `max_professionals` = 4,
  `requires_materials` = 1,
  `allows_frequency` = 1,
  `sort_order` = 1
WHERE `name` = 'Home Cleaning' AND `category` = 'General'
LIMIT 1;

UPDATE `services` SET 
  `category_id` = 4,
  `pricing_rule_id` = 2,
  `base_price` = 100.00,
  `min_hours` = 1,
  `max_hours` = 3,
  `min_professionals` = 1,
  `max_professionals` = 2,
  `requires_materials` = 1,
  `allows_frequency` = 0,
  `sort_order` = 1
WHERE `name` = 'Carpet Cleaning' AND `category` = 'Cleaning'
LIMIT 1;

UPDATE `services` SET 
  `category_id` = 5,
  `pricing_rule_id` = 1,
  `base_price` = 150.00,
  `price_per_unit` = 50.00,
  `min_hours` = 3,
  `max_hours` = 10,
  `min_professionals` = 2,
  `max_professionals` = 4,
  `requires_materials` = 1,
  `allows_frequency` = 1,
  `sort_order` = 1
WHERE `name` = 'Home Cleaning' AND `category` = 'Cleaning'
LIMIT 1;

UPDATE `services` SET 
  `category_id` = 6,
  `pricing_rule_id` = 2,
  `base_price` = 250.00,
  `min_hours` = 2,
  `max_hours` = 4,
  `min_professionals` = 1,
  `max_professionals` = 2,
  `requires_materials` = 0,
  `allows_frequency` = 0,
  `sort_order` = 1
WHERE `name` = 'Pest Control' AND (`category` = 'Cleaning' OR `category` = 'General')
LIMIT 1;

-- Frequency Discounts
INSERT INTO `frequency_discounts` (`frequency_type`, `frequency_name`, `discount_percentage`, `is_active`, `sort_order`) VALUES
('one_time', 'One Time', 0.00, 1, 1),
('biweekly', 'Every Two Weeks', 5.00, 1, 2),
('weekly', 'Once a Week', 10.00, 1, 3),
('multiple', 'Multiple Times a Week', 25.00, 1, 4)
ON DUPLICATE KEY UPDATE `frequency_name` = VALUES(`frequency_name`);

-- Sample Promotional Banner
INSERT INTO `promotional_banners` (`title`, `description`, `image_url`, `sort_order`, `is_active`) VALUES
('Welcome Offer', 'Get 20% off on your first booking!', 'https://via.placeholder.com/800x300/4FC3F7/FFFFFF?text=Welcome+Offer', 1, 1)
ON DUPLICATE KEY UPDATE `title` = VALUES(`title`);

-- =====================================================
-- CREATE VIEWS
-- =====================================================

-- View for categories with parent info
CREATE OR REPLACE VIEW `v_categories_tree` AS
SELECT 
  c.id,
  c.parent_id,
  c.name,
  c.name_ar,
  c.description,
  c.icon_url,
  c.image_url,
  c.sort_order,
  c.is_active,
  c.show_on_home,
  p.name AS parent_name,
  (SELECT COUNT(*) FROM service_categories WHERE parent_id = c.id) AS children_count,
  (SELECT COUNT(*) FROM services WHERE category_id = c.id AND is_active = 1) AS services_count
FROM service_categories c
LEFT JOIN service_categories p ON c.parent_id = p.id;

-- View for services with category and pricing info
CREATE OR REPLACE VIEW `v_services_enhanced` AS
SELECT 
  s.id,
  s.category_id,
  c.name AS category_name,
  c.parent_id AS parent_category_id,
  pc.name AS parent_category_name,
  s.name AS service_name,
  s.description,
  s.price,
  s.base_price,
  s.price_per_unit,
  s.min_price,
  s.max_price,
  s.duration_minutes,
  s.min_hours,
  s.max_hours,
  s.min_professionals,
  s.max_professionals,
  s.requires_materials,
  s.allows_frequency,
  s.image_url,
  s.is_active,
  s.sort_order,
  pr.name AS pricing_rule_name,
  pr.calculation_type,
  s.created_at,
  s.updated_at
FROM services s
LEFT JOIN service_categories c ON s.category_id = c.id
LEFT JOIN service_categories pc ON c.parent_id = pc.id
LEFT JOIN pricing_rules pr ON s.pricing_rule_id = pr.id;

SET FOREIGN_KEY_CHECKS = 1;

-- =====================================================
-- INDEXES FOR PERFORMANCE
-- =====================================================
ALTER TABLE `services` ADD INDEX IF NOT EXISTS `idx_category` (`category_id`);
ALTER TABLE `services` ADD INDEX IF NOT EXISTS `idx_active_sort` (`is_active`, `sort_order`);
ALTER TABLE `service_categories` ADD INDEX IF NOT EXISTS `idx_parent_active` (`parent_id`, `is_active`);

-- Migration complete!
SELECT 'Enhanced booking system migration completed successfully!' AS status;

