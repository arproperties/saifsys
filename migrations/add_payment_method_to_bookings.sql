-- Add payment_method and wallet_amount_used columns to online_bookings table

ALTER TABLE `online_bookings` 
ADD COLUMN IF NOT EXISTS `payment_method` VARCHAR(20) DEFAULT 'cash' COMMENT 'cash, wallet, wallet_partial' AFTER `total_price`,
ADD COLUMN IF NOT EXISTS `wallet_amount_used` DECIMAL(10,2) DEFAULT 0.00 COMMENT 'Amount paid from wallet' AFTER `payment_method`;

