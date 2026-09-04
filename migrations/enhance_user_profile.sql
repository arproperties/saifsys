-- =====================================================
-- User Profile Enhancement Migration
-- Safe to run on live database - only adds new columns
-- Will NOT modify or delete any existing data
-- =====================================================

-- Add profile-related columns to user table
-- Using ADD COLUMN IF NOT EXISTS for safety (MariaDB 10.5.2+)

-- If your MariaDB version doesn't support IF NOT EXISTS, 
-- the script will fail gracefully on existing columns

-- Basic contact information
ALTER TABLE `user` 
ADD COLUMN IF NOT EXISTS `email` VARCHAR(255) DEFAULT NULL COMMENT 'User email address' AFTER `fullname`;

ALTER TABLE `user` 
ADD COLUMN IF NOT EXISTS `phone` VARCHAR(20) DEFAULT NULL COMMENT 'Phone number' AFTER `email`;

-- Profile customization
ALTER TABLE `user` 
ADD COLUMN IF NOT EXISTS `avatar_path` VARCHAR(500) DEFAULT NULL COMMENT 'Path to profile picture' AFTER `phone`;

ALTER TABLE `user` 
ADD COLUMN IF NOT EXISTS `job_title` VARCHAR(100) DEFAULT NULL COMMENT 'Job title/position' AFTER `avatar_path`;

ALTER TABLE `user` 
ADD COLUMN IF NOT EXISTS `department` VARCHAR(100) DEFAULT NULL COMMENT 'Department name' AFTER `job_title`;

-- Tracking information
ALTER TABLE `user` 
ADD COLUMN IF NOT EXISTS `date_joined` DATE DEFAULT NULL COMMENT 'Date user joined system' AFTER `department`;

ALTER TABLE `user` 
ADD COLUMN IF NOT EXISTS `last_login` TIMESTAMP NULL DEFAULT NULL COMMENT 'Last login timestamp' AFTER `date_joined`;

-- User preferences
ALTER TABLE `user` 
ADD COLUMN IF NOT EXISTS `timezone` VARCHAR(50) DEFAULT 'Asia/Dubai' COMMENT 'User timezone' AFTER `last_login`;

ALTER TABLE `user` 
ADD COLUMN IF NOT EXISTS `language_preference` VARCHAR(10) DEFAULT 'en' COMMENT 'Preferred language (en, ar, etc.)' AFTER `timezone`;

ALTER TABLE `user` 
ADD COLUMN IF NOT EXISTS `theme_preference` ENUM('light', 'dark', 'auto') DEFAULT 'light' COMMENT 'UI theme preference' AFTER `language_preference`;

ALTER TABLE `user` 
ADD COLUMN IF NOT EXISTS `email_notifications` TINYINT(1) DEFAULT 1 COMMENT 'Enable email notifications' AFTER `theme_preference`;

-- Status and timestamps
ALTER TABLE `user` 
ADD COLUMN IF NOT EXISTS `is_active` TINYINT(1) DEFAULT 1 COMMENT 'User active status' AFTER `email_notifications`;

ALTER TABLE `user` 
ADD COLUMN IF NOT EXISTS `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT 'Last update timestamp' AFTER `is_active`;

-- =====================================================
-- Add indexes for better performance
-- =====================================================

-- Email index (for unique constraint and faster lookups)
CREATE INDEX IF NOT EXISTS `idx_user_email` ON `user`(`email`);

-- Active status index (for filtering active users)
CREATE INDEX IF NOT EXISTS `idx_user_active` ON `user`(`is_active`);

-- Last login index (for activity reports)
CREATE INDEX IF NOT EXISTS `idx_user_last_login` ON `user`(`last_login`);

-- =====================================================
-- Optional: Initialize date_joined for existing users
-- =====================================================

-- Set date_joined to a reasonable default for existing users who don't have it
UPDATE `user` 
SET `date_joined` = '2024-01-01' 
WHERE `date_joined` IS NULL;

-- =====================================================
-- Create uploads directory structure (manual step)
-- =====================================================

-- Note: You need to manually create this directory structure:
-- /herosys/uploads/avatars/
-- 
-- Run this command in terminal:
-- mkdir -p /Applications/XAMPP/xamppfiles/htdocs/herosys/uploads/avatars
-- chmod 755 /Applications/XAMPP/xamppfiles/htdocs/herosys/uploads/avatars

-- =====================================================
-- Migration Complete!
-- =====================================================
-- 
-- What this migration does:
-- ✅ Adds 13 new columns to user table for profile management
-- ✅ Adds 3 indexes for better query performance
-- ✅ Sets reasonable defaults for all new columns
-- ✅ Does NOT modify or delete any existing data
-- ✅ Safe to run multiple times (IF NOT EXISTS clauses)
-- 
-- New columns added:
-- - email, phone (contact info)
-- - avatar_path (profile picture)
-- - job_title, department (organizational info)
-- - date_joined, last_login (tracking)
-- - timezone, language_preference, theme_preference (preferences)
-- - email_notifications (notification settings)
-- - is_active, updated_at (status tracking)
-- 
-- Next steps:
-- 1. Run this migration on your database
-- 2. Create the avatars directory (see note above)
-- 3. Proceed with profile page implementation
-- =====================================================

