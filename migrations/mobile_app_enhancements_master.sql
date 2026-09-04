-- =============================================================================
-- mobile_app_enhancements_master.sql
-- =============================================================================
-- Master deployment script for Customer / Mobile App schema changes from the
-- HeroSysgro mobile enhancement workstream (this chat / related session).
--
-- Safe to run once on live (idempotent): CREATE TABLE IF NOT EXISTS,
-- ADD COLUMN IF NOT EXISTS, INSERT ... ON DUPLICATE KEY UPDATE.
--
-- Merged sources:
--   1) migrations/app_mobile_config.sql
--   2) migrations/company_settings_whatsapp.sql
--   3) migrations/re_building_primary_photo.sql
--   4) migrations/re_tenant_communication_center.sql
--   5) migrations/re_property_share_refs.sql
--   6) Property Sharing columns on app_mobile_config
--      (previously ensured only at runtime in
--       includes/app_mobile_config_helper.php → app_mobile_config_ensure_share_columns;
--       no separate .sql file existed — included here so live deploy is complete)
--
-- Intentionally omitted (no database changes in this chat):
--   - Flutter Welcome cinematic / MP4 backgrounds
--   - app_config.dart documentation refactor
--   - Property Sharing .well-known / PHP / Flutter (non-SQL)
--
-- Execution order: base tables → additive columns → share refs → seed row
-- =============================================================================

SET NAMES utf8mb4;

-- =============================================================================
-- 1) TABLES — Mobile App Version / Runtime Config
--    Source: migrations/app_mobile_config.sql
-- =============================================================================

CREATE TABLE IF NOT EXISTS `app_mobile_config` (
  `id` INT(11) NOT NULL DEFAULT 1,
  `android_latest_version` VARCHAR(32) NOT NULL DEFAULT '1.0.0',
  `android_min_version` VARCHAR(32) NOT NULL DEFAULT '1.0.0',
  `ios_latest_version` VARCHAR(32) NOT NULL DEFAULT '1.0.0',
  `ios_min_version` VARCHAR(32) NOT NULL DEFAULT '1.0.0',
  `force_update` TINYINT(1) NOT NULL DEFAULT 0,
  `play_store_url` VARCHAR(500) DEFAULT NULL,
  `app_store_url` VARCHAR(500) DEFAULT NULL,
  `update_title` VARCHAR(200) NOT NULL DEFAULT 'Update Available',
  `update_message` TEXT DEFAULT NULL,
  `maintenance_mode` TINYINT(1) NOT NULL DEFAULT 0,
  `maintenance_message` TEXT DEFAULT NULL,
  `feature_flags_json` LONGTEXT DEFAULT NULL COMMENT 'JSON object for future flags without app changes',
  `updated_at` DATETIME DEFAULT NULL,
  `updated_by` INT(11) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
-- 2) TABLES — Tenant Communication Center (announcements for Customer App Home)
--    Source: migrations/re_tenant_communication_center.sql
-- =============================================================================

CREATE TABLE IF NOT EXISTS `re_announcement_categories` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `company_id` INT(11) NOT NULL,
  `code` VARCHAR(50) NOT NULL,
  `name` VARCHAR(100) NOT NULL,
  `sort_order` INT(11) NOT NULL DEFAULT 0,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_re_ann_cat_company_code` (`company_id`, `code`),
  KEY `idx_re_ann_cat_active` (`company_id`, `is_active`, `sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `re_announcements` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `company_id` INT(11) NOT NULL,
  `category_id` INT(11) DEFAULT NULL,
  `title` VARCHAR(200) NOT NULL,
  `body` MEDIUMTEXT NOT NULL,
  `priority` ENUM('critical','high','normal','information') NOT NULL DEFAULT 'normal',
  `status` ENUM('draft','scheduled','published','archived') NOT NULL DEFAULT 'draft',
  `publish_at` DATETIME DEFAULT NULL,
  `expire_at` DATETIME DEFAULT NULL,
  `target_scope` ENUM('company','buildings','units','tenants','group') NOT NULL DEFAULT 'company'
    COMMENT 'group reserved for future targeting groups',
  `image_path` VARCHAR(500) DEFAULT NULL,
  `image_mime` VARCHAR(120) DEFAULT NULL,
  `send_in_app` TINYINT(1) NOT NULL DEFAULT 1,
  `send_push` TINYINT(1) NOT NULL DEFAULT 0,
  `push_batch_id` INT(11) DEFAULT NULL,
  `created_by` INT(11) DEFAULT NULL,
  `published_by` INT(11) DEFAULT NULL,
  `published_at` DATETIME DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_re_ann_company_status` (`company_id`, `status`, `publish_at`),
  KEY `idx_re_ann_priority` (`company_id`, `priority`, `status`),
  KEY `idx_re_ann_category` (`category_id`),
  KEY `idx_re_ann_expire` (`company_id`, `expire_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `re_announcement_targets` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `company_id` INT(11) NOT NULL,
  `announcement_id` INT(11) NOT NULL,
  `target_type` ENUM('building','unit','tenant','tenant_portal_user','group') NOT NULL,
  `target_id` INT(11) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_re_ann_target` (`announcement_id`, `target_type`, `target_id`),
  KEY `idx_re_ann_target_lookup` (`company_id`, `target_type`, `target_id`),
  KEY `idx_re_ann_target_ann` (`announcement_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `re_announcement_attachments` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `company_id` INT(11) NOT NULL,
  `announcement_id` INT(11) NOT NULL,
  `file_path` VARCHAR(500) NOT NULL,
  `file_name` VARCHAR(255) NOT NULL,
  `mime_type` VARCHAR(120) DEFAULT NULL,
  `file_size` INT(11) DEFAULT NULL,
  `uploaded_by` INT(11) DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_re_ann_attach` (`announcement_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
-- 3) TABLES — Property Sharing (opaque share codes)
--    Source: migrations/re_property_share_refs.sql
-- =============================================================================

CREATE TABLE IF NOT EXISTS `re_property_share_refs` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `company_id` INT(11) NOT NULL,
  `share_code` CHAR(36) NOT NULL,
  `property_type` VARCHAR(40) NOT NULL DEFAULT 'long_term_rental',
  `entity_id` INT(11) NOT NULL COMMENT 'For long_term_rental = re_units.id',
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_share_code` (`share_code`),
  UNIQUE KEY `uq_company_type_entity` (`company_id`, `property_type`, `entity_id`),
  KEY `idx_company_active` (`company_id`, `is_active`),
  KEY `idx_entity` (`property_type`, `entity_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
-- 4) COLUMNS — Company support WhatsApp (Account Support in Customer App)
--    Source: migrations/company_settings_whatsapp.sql
--    Requires existing table: company_settings
-- =============================================================================

ALTER TABLE `company_settings`
  ADD COLUMN IF NOT EXISTS `whatsapp` VARCHAR(50) NULL DEFAULT NULL
    COMMENT 'WhatsApp number for tenant/customer app support contact'
    AFTER `website`;

-- =============================================================================
-- 5) COLUMNS — Building primary photo (apps / website / AI)
--    Source: migrations/re_building_primary_photo.sql
--    Requires existing table: re_buildings
-- =============================================================================

ALTER TABLE `re_buildings`
  ADD COLUMN IF NOT EXISTS `primary_photo_path` VARCHAR(500) NULL DEFAULT NULL
    COMMENT 'Relative path e.g. uploads/realestate/buildings/{id}/primary_....jpg'
    AFTER `facilities_notes`;

ALTER TABLE `re_buildings`
  ADD COLUMN IF NOT EXISTS `primary_photo_mime` VARCHAR(100) NULL DEFAULT NULL
    AFTER `primary_photo_path`;

ALTER TABLE `re_buildings`
  ADD COLUMN IF NOT EXISTS `primary_photo_updated_at` DATETIME NULL DEFAULT NULL
    AFTER `primary_photo_mime`;

-- =============================================================================
-- 6) COLUMNS — Property Sharing config on app_mobile_config
--    Source: includes/app_mobile_config_helper.php (app_mobile_config_ensure_share_columns)
--    Requires: app_mobile_config table (section 1)
-- =============================================================================

ALTER TABLE `app_mobile_config`
  ADD COLUMN IF NOT EXISTS `property_share_enabled` TINYINT(1) NOT NULL DEFAULT 1
    AFTER `feature_flags_json`;

ALTER TABLE `app_mobile_config`
  ADD COLUMN IF NOT EXISTS `share_base_url` VARCHAR(500) DEFAULT NULL
    AFTER `property_share_enabled`;

ALTER TABLE `app_mobile_config`
  ADD COLUMN IF NOT EXISTS `share_message_template` TEXT DEFAULT NULL
    AFTER `share_base_url`;

ALTER TABLE `app_mobile_config`
  ADD COLUMN IF NOT EXISTS `android_package_name` VARCHAR(200) DEFAULT 'com.ainalreem.living'
    AFTER `share_message_template`;

ALTER TABLE `app_mobile_config`
  ADD COLUMN IF NOT EXISTS `android_sha256_fingerprints` TEXT DEFAULT NULL
    AFTER `android_package_name`;

ALTER TABLE `app_mobile_config`
  ADD COLUMN IF NOT EXISTS `ios_team_id` VARCHAR(32) DEFAULT NULL
    AFTER `android_sha256_fingerprints`;

ALTER TABLE `app_mobile_config`
  ADD COLUMN IF NOT EXISTS `ios_bundle_id` VARCHAR(200) DEFAULT 'com.ainalreem.living'
    AFTER `ios_team_id`;

-- =============================================================================
-- 7) SEED — Default singleton row for app_mobile_config
--    Source: migrations/app_mobile_config.sql
--    Does not overwrite an existing row (ON DUPLICATE KEY UPDATE id = id).
-- =============================================================================

INSERT INTO `app_mobile_config` (
  `id`,
  `android_latest_version`,
  `android_min_version`,
  `ios_latest_version`,
  `ios_min_version`,
  `force_update`,
  `update_title`,
  `update_message`,
  `maintenance_mode`,
  `maintenance_message`,
  `feature_flags_json`,
  `updated_at`
) VALUES (
  1,
  '1.0.1',
  '1.0.0',
  '1.0.1',
  '1.0.0',
  0,
  'Update Available',
  'A new version of Ain Al Reem Living is available. Please update for the latest improvements.',
  0,
  'We are performing scheduled maintenance. Please try again shortly.',
  '{}',
  NOW()
)
ON DUPLICATE KEY UPDATE `id` = `id`;

-- =============================================================================
-- Verification (optional — run manually after deploy)
-- =============================================================================
-- SHOW TABLES LIKE 'app_mobile_config';
-- SHOW TABLES LIKE 're_property_share_refs';
-- SHOW TABLES LIKE 're_announcement%';
-- SHOW COLUMNS FROM company_settings LIKE 'whatsapp';
-- SHOW COLUMNS FROM re_buildings LIKE 'primary_photo%';
-- SHOW COLUMNS FROM app_mobile_config LIKE 'property_share%';
-- SHOW COLUMNS FROM app_mobile_config LIKE 'share_%';
-- SHOW COLUMNS FROM app_mobile_config LIKE 'android_%';
-- SHOW COLUMNS FROM app_mobile_config LIKE 'ios_%';
-- SELECT id, android_latest_version, ios_latest_version, property_share_enabled
--   FROM app_mobile_config WHERE id = 1;
-- =============================================================================
