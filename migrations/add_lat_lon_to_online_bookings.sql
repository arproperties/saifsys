-- =====================================================
-- Add latitude/longitude columns to online_bookings
-- Supports precise map location for admin dashboard
-- =====================================================

USE `bestsys`;

ALTER TABLE `online_bookings`
  ADD COLUMN IF NOT EXISTS `latitude` DECIMAL(10, 8) DEFAULT NULL AFTER `address`,
  ADD COLUMN IF NOT EXISTS `longitude` DECIMAL(11, 8) DEFAULT NULL AFTER `latitude`;

-- Optional composite index to speed up geo queries (if required later)
ALTER TABLE `online_bookings`
  ADD INDEX IF NOT EXISTS `idx_online_bookings_lat_lon` (`latitude`, `longitude`);

SELECT 'Migration completed successfully - latitude/longitude columns added to online_bookings' AS message;

