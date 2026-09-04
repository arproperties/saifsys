-- Migration: Add date_of_birth column to employees table
-- Date: 2025-11-30
-- Description: Adds date_of_birth field to store employee birthdays for upcoming events feature

-- Add date_of_birth column to employees table
ALTER TABLE `employees` 
ADD COLUMN `date_of_birth` DATE DEFAULT NULL AFTER `date_joined`;

-- Add index for birthday queries (optional, but helpful for birthday searches)
ALTER TABLE `employees` 
ADD INDEX `idx_date_of_birth` (`date_of_birth`);

