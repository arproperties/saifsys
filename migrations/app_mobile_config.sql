-- Mobile App Version Management (Customer App runtime config)
-- Global singleton (one Flutter app). Additive only.
-- Rollback: DROP TABLE IF EXISTS app_mobile_config;

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
