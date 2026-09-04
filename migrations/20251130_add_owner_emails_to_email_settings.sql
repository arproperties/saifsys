-- Migration: Add owner_emails field to app_email_settings table
-- Date: 2025-11-30
-- Description: Adds owner_emails field to store comma-separated owner email addresses for notifications

-- Add owner_emails column to app_email_settings table
ALTER TABLE `app_email_settings` 
ADD COLUMN `owner_emails` TEXT DEFAULT NULL AFTER `is_enabled`;

