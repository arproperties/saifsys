-- =====================================================
-- Add New Booking Detail Fields
-- For the enhanced mobile booking flow
-- =====================================================

USE `bestsys`;

-- Add new columns to online_bookings table
ALTER TABLE `online_bookings`
ADD COLUMN IF NOT EXISTS `hours` DECIMAL(4,2) DEFAULT 2.00 COMMENT 'Service duration in hours' AFTER `service_id`,
ADD COLUMN IF NOT EXISTS `professionals` INT DEFAULT 1 COMMENT 'Number of workers requested' AFTER `hours`,
ADD COLUMN IF NOT EXISTS `materials_included` TINYINT(1) DEFAULT 0 COMMENT 'Cleaning materials included' AFTER `professionals`,
ADD COLUMN IF NOT EXISTS `frequency` VARCHAR(20) DEFAULT 'one_time' COMMENT 'Booking frequency: one_time, weekly, biweekly, multiple' AFTER `materials_included`,
ADD COLUMN IF NOT EXISTS `subtotal` DECIMAL(10,2) DEFAULT 0.00 COMMENT 'Price before discount' AFTER `total_price`,
ADD COLUMN IF NOT EXISTS `discount_amount` DECIMAL(10,2) DEFAULT 0.00 COMMENT 'Discount applied' AFTER `subtotal`,
ADD COLUMN IF NOT EXISTS `service_fee` DECIMAL(10,2) DEFAULT 0.00 COMMENT 'Service fee added' AFTER `discount_amount`,
ADD COLUMN IF NOT EXISTS `instructions` TEXT DEFAULT NULL COMMENT 'Special instructions from customer' AFTER `notes`;

-- Add index for frequency filtering
ALTER TABLE `online_bookings`
ADD INDEX IF NOT EXISTS `idx_frequency` (`frequency`);

-- Update existing records with default values
UPDATE `online_bookings` 
SET 
    `hours` = 2.00,
    `professionals` = 1,
    `materials_included` = 0,
    `frequency` = 'one_time',
    `subtotal` = `total_price`,
    `discount_amount` = 0.00,
    `service_fee` = 0.00
WHERE `hours` IS NULL;

SELECT 'Migration completed successfully - New booking detail fields added' AS message;

