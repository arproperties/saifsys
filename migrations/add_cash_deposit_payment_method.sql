-- Add 'cash_deposit' to payment_method ENUM in re_payments table
ALTER TABLE `re_payments` 
MODIFY COLUMN `payment_method` ENUM('cash', 'bank_transfer', 'cheque', 'auto_debit', 'cash_deposit') NOT NULL;
