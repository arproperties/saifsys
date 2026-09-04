-- =====================================================
-- Fix Foreign Key in booking_events Table
-- Change reference from 'users' to 'user' (correct table name)
-- =====================================================

USE `bestsys`;

-- Drop the incorrect foreign key constraint
ALTER TABLE `booking_events`
DROP FOREIGN KEY IF EXISTS `fk_event_user`;

-- Add the correct foreign key constraint
ALTER TABLE `booking_events`
ADD CONSTRAINT `fk_event_user` 
FOREIGN KEY (`user_id`) REFERENCES `user`(`id`) ON DELETE SET NULL;

SELECT 'Migration completed successfully - Fixed booking_events foreign key' AS message;

