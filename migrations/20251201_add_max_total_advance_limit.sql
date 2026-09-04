-- Add maximum total advance amount limit to cash_advance_policy table
-- This represents the maximum cumulative advance amount an employee can have outstanding at any time

ALTER TABLE `cash_advance_policy` 
ADD COLUMN `max_total_advance_amount` decimal(10,2) DEFAULT NULL 
COMMENT 'Maximum total cumulative advance amount an employee can have outstanding (e.g., 1000 AED total limit)' 
AFTER `max_advance_percentage_salary`;

