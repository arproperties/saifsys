-- ============================================================================
-- Enhance Buildings Profile - Facilities, Common Areas, Floor Plans
-- ============================================================================
-- This migration adds:
-- 1. Facilities fields to re_buildings (parking, gym, pool)
-- 2. Common areas tracking table
-- 3. Floor plans storage table
-- ============================================================================

-- Add facilities fields to re_buildings
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() 
    AND TABLE_NAME = 're_buildings' 
    AND COLUMN_NAME = 'has_parking');
SET @sql = IF(@col_exists = 0, 
    'ALTER TABLE `re_buildings` ADD COLUMN `has_parking` TINYINT(1) NOT NULL DEFAULT 0 AFTER `total_units`', 
    'SELECT "Column has_parking already exists" AS message');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() 
    AND TABLE_NAME = 're_buildings' 
    AND COLUMN_NAME = 'parking_spaces');
SET @sql = IF(@col_exists = 0, 
    'ALTER TABLE `re_buildings` ADD COLUMN `parking_spaces` INT(11) DEFAULT NULL AFTER `has_parking`', 
    'SELECT "Column parking_spaces already exists" AS message');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() 
    AND TABLE_NAME = 're_buildings' 
    AND COLUMN_NAME = 'has_gym');
SET @sql = IF(@col_exists = 0, 
    'ALTER TABLE `re_buildings` ADD COLUMN `has_gym` TINYINT(1) NOT NULL DEFAULT 0 AFTER `parking_spaces`', 
    'SELECT "Column has_gym already exists" AS message');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() 
    AND TABLE_NAME = 're_buildings' 
    AND COLUMN_NAME = 'has_pool');
SET @sql = IF(@col_exists = 0, 
    'ALTER TABLE `re_buildings` ADD COLUMN `has_pool` TINYINT(1) NOT NULL DEFAULT 0 AFTER `has_gym`', 
    'SELECT "Column has_pool already exists" AS message');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() 
    AND TABLE_NAME = 're_buildings' 
    AND COLUMN_NAME = 'facilities_notes');
SET @sql = IF(@col_exists = 0, 
    'ALTER TABLE `re_buildings` ADD COLUMN `facilities_notes` TEXT DEFAULT NULL AFTER `has_pool`', 
    'SELECT "Column facilities_notes already exists" AS message');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Create common areas tracking table
CREATE TABLE IF NOT EXISTS `re_building_common_areas` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `building_id` INT(11) NOT NULL,
  `area_name` VARCHAR(200) NOT NULL,
  `area_type` ENUM('lobby', 'reception', 'corridor', 'elevator', 'staircase', 'rooftop', 'garden', 'playground', 'parking', 'storage', 'other') NOT NULL DEFAULT 'other',
  `floor_number` INT(11) DEFAULT NULL COMMENT 'NULL means ground/common level',
  `area_sqm` DECIMAL(10,2) DEFAULT NULL,
  `capacity` INT(11) DEFAULT NULL COMMENT 'Max capacity if applicable',
  `description` TEXT DEFAULT NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_building` (`building_id`),
  KEY `idx_area_type` (`area_type`),
  FOREIGN KEY (`building_id`) REFERENCES `re_buildings`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Create floor plans storage table
CREATE TABLE IF NOT EXISTS `re_building_floor_plans` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `building_id` INT(11) NOT NULL,
  `floor_number` INT(11) DEFAULT NULL COMMENT 'NULL means building-wide plan',
  `plan_name` VARCHAR(200) NOT NULL,
  `file_path` VARCHAR(500) NOT NULL,
  `file_size` INT(11) DEFAULT NULL COMMENT 'Size in bytes',
  `file_type` VARCHAR(50) DEFAULT NULL COMMENT 'MIME type',
  `description` TEXT DEFAULT NULL,
  `is_primary` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Primary plan for this floor',
  `uploaded_by` INT(11) DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_building` (`building_id`),
  KEY `idx_floor` (`floor_number`),
  KEY `idx_primary` (`building_id`, `floor_number`, `is_primary`),
  KEY `idx_uploaded_by` (`uploaded_by`),
  FOREIGN KEY (`building_id`) REFERENCES `re_buildings`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`uploaded_by`) REFERENCES `user`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

SELECT 'Building profile enhancement completed successfully!' AS message;

