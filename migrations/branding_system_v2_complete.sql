-- ============================================================================
-- Branding System V2.0 - Complete Database Migration
-- ============================================================================
-- Date: October 28, 2025
-- Version: 2.0.0
-- Description: Adds all branding settings including logo upload and dark mode
-- Safe to run multiple times (uses INSERT ... ON DUPLICATE KEY UPDATE)
-- ============================================================================

-- Insert/Update all branding settings in the settings table
-- Uses ON DUPLICATE KEY UPDATE so it's safe to run multiple times

INSERT INTO `settings` (`key`, `value`, `created_at`, `updated_at`)
VALUES 
    -- System Identity
    ('brand_system_name', 'BMSystem', NOW(), NOW()),
    ('brand_system_name_short', 'BM', NOW(), NOW()),
    
    -- Primary Colors (Maroon - Default Theme)
    ('brand_primary_color', '#7a0000', NOW(), NOW()),
    ('brand_primary_light', '#910c0c', NOW(), NOW()),
    ('brand_primary_dark', '#600000', NOW(), NOW()),
    ('brand_accent_color', '#ffd86a', NOW(), NOW()),
    
    -- Logo & Dark Mode
    ('brand_logo_path', '', NOW(), NOW()),
    ('brand_dark_mode_enabled', '0', NOW(), NOW())

ON DUPLICATE KEY UPDATE 
    `updated_at` = NOW();

-- ============================================================================
-- Verification Query (Run this after to confirm)
-- ============================================================================
-- SELECT `key`, `value`, `updated_at` 
-- FROM `settings` 
-- WHERE `key` LIKE 'brand_%' 
-- ORDER BY `key`;
-- ============================================================================

-- Success! Branding System V2.0 database migration complete.
-- You can now use the branding settings in Settings → Branding tab.

