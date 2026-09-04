-- =====================================================
-- Add VAT Column to Online Bookings
-- Fix for mobile app booking creation
-- =====================================================

USE `bestsys`;

-- Add vat column if it doesn't exist
ALTER TABLE `online_bookings`
ADD COLUMN IF NOT EXISTS `vat` DECIMAL(10,2) DEFAULT 0.00 COMMENT '5% VAT amount' AFTER `service_fee`;

SELECT 'Migration completed successfully - VAT column added to online_bookings' AS message;

