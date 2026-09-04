-- =====================================================
-- Add Weekly Schedule Column for Multiple Times Per Week Bookings
-- =====================================================

USE `bestsys`;

-- Add weekly_schedule JSON column to store multiple days/times per week
ALTER TABLE `online_bookings`
ADD COLUMN IF NOT EXISTS `weekly_schedule` JSON DEFAULT NULL COMMENT 'Weekly schedule for multiple times per week bookings (array of {day, time})' AFTER `frequency`;

-- Example structure of weekly_schedule JSON:
-- [
--   {"day": "Monday", "time": "09:00-11:00"},
--   {"day": "Wednesday", "time": "14:00-16:00"},
--   {"day": "Friday", "time": "10:00-12:00"}
-- ]

SELECT 'Migration completed successfully - Weekly schedule column added' AS message;

