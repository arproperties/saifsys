-- ============================================================================
-- Enhance Units Table - Add Parking Slot, Furniture Status, Premises Number
-- Make Floor Mandatory
-- ============================================================================
-- This migration adds new fields to re_units table:
-- - parking_slot: Parking slot number for each unit
-- - furniture_status: furnished/unfurnished
-- - premises_number: Unique premises number per unit
-- - Makes floor_id mandatory (NOT NULL)
-- ============================================================================

-- Add parking_slot column
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() 
    AND TABLE_NAME = 're_units' 
    AND COLUMN_NAME = 'parking_slot');
SET @sql = IF(@col_exists = 0, 
    'ALTER TABLE `re_units` ADD COLUMN `parking_slot` VARCHAR(50) DEFAULT NULL AFTER `monthly_rent`', 
    'SELECT "Column parking_slot already exists" AS message');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Add furniture_status column
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() 
    AND TABLE_NAME = 're_units' 
    AND COLUMN_NAME = 'furniture_status');
SET @sql = IF(@col_exists = 0, 
    'ALTER TABLE `re_units` ADD COLUMN `furniture_status` ENUM(\'furnished\', \'unfurnished\') DEFAULT NULL AFTER `parking_slot`', 
    'SELECT "Column furniture_status already exists" AS message');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Add premises_number column
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() 
    AND TABLE_NAME = 're_units' 
    AND COLUMN_NAME = 'premises_number');
SET @sql = IF(@col_exists = 0, 
    'ALTER TABLE `re_units` ADD COLUMN `premises_number` VARCHAR(50) DEFAULT NULL AFTER `unit_number`', 
    'SELECT "Column premises_number already exists" AS message');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Add unique index for premises_number (if not exists)
SET @idx_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS 
    WHERE TABLE_SCHEMA = DATABASE() 
    AND TABLE_NAME = 're_units' 
    AND INDEX_NAME = 'uq_premises_number');
SET @sql = IF(@idx_exists = 0, 
    'ALTER TABLE `re_units` ADD UNIQUE KEY `uq_premises_number` (`premises_number`)', 
    'SELECT "Index uq_premises_number already exists" AS message');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Make floor_id mandatory (NOT NULL)
-- First, set floor_id to a default value for existing NULL records
-- We'll use the first floor of each building, or create a default floor
UPDATE re_units u
LEFT JOIN re_floors f ON f.building_id = u.building_id AND f.floor_number = 1
SET u.floor_id = f.id
WHERE u.floor_id IS NULL AND f.id IS NOT NULL;

-- For units that still don't have a floor, we'll create a default floor for their building
-- This is a safety measure - ideally all units should have floors
INSERT INTO re_floors (building_id, floor_number, name, total_units)
SELECT DISTINCT u.building_id, 1, 'Ground Floor', 0
FROM re_units u
WHERE u.floor_id IS NULL
AND NOT EXISTS (
    SELECT 1 FROM re_floors f 
    WHERE f.building_id = u.building_id AND f.floor_number = 1
);

-- Now update remaining NULL floor_ids
UPDATE re_units u
JOIN re_floors f ON f.building_id = u.building_id AND f.floor_number = 1
SET u.floor_id = f.id
WHERE u.floor_id IS NULL;

-- Finally, make floor_id NOT NULL
SET @col_nullable = (SELECT IS_NULLABLE FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() 
    AND TABLE_NAME = 're_units' 
    AND COLUMN_NAME = 'floor_id');
SET @sql = IF(@col_nullable = 'YES', 
    'ALTER TABLE `re_units` MODIFY COLUMN `floor_id` INT(11) NOT NULL', 
    'SELECT "Column floor_id is already NOT NULL" AS message');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Add index for parking_slot if not exists
SET @idx_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS 
    WHERE TABLE_SCHEMA = DATABASE() 
    AND TABLE_NAME = 're_units' 
    AND INDEX_NAME = 'idx_parking_slot');
SET @sql = IF(@idx_exists = 0, 
    'ALTER TABLE `re_units` ADD KEY `idx_parking_slot` (`parking_slot`)', 
    'SELECT "Index idx_parking_slot already exists" AS message');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SELECT 'Migration completed successfully!' AS message;

