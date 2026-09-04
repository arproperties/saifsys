-- =====================================================
-- Fix Foreign Key in online_bookings Table
-- Change reference from 'users' to 'user' (correct table name)
-- =====================================================

USE `bestsys`;

-- Drop the incorrect foreign key constraint
ALTER TABLE `online_bookings`
DROP FOREIGN KEY IF EXISTS `fk_booking_confirmed_by`;

-- Add the correct foreign key constraint
ALTER TABLE `online_bookings`
ADD CONSTRAINT `fk_booking_confirmed_by` 
FOREIGN KEY (`confirmed_by`) REFERENCES `user`(`id`) ON DELETE SET NULL;

SELECT 'Migration completed successfully - Fixed online_bookings foreign key' AS message;

