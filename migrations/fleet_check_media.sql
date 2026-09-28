-- Driver daily check — photos and voice notes of a Problem.
--
-- Many drivers cannot write, so the Staff web app (/staff/ → Driver) lets them
-- show a problem with a photo or say it in a voice note instead of typing. Each
-- file belongs to one check and names the checklist item it is about.
--
-- Files live in uploads/fleet_checks/{check_id}/ (not web-readable) and are
-- served to HR by hr/fleet_check_media.php. Rules: hr/includes/hr_fleet_checks.php.
--
-- Safe to run more than once.

CREATE TABLE IF NOT EXISTS `fleet_daily_check_media` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `check_id` INT(11) NOT NULL,
  `item_key` VARCHAR(40) NOT NULL COMMENT 'Checklist item the problem is about',
  `kind` ENUM('photo','voice') NOT NULL,
  `file_path` VARCHAR(255) NOT NULL COMMENT 'Relative to the app root',
  `duration_seconds` SMALLINT(5) UNSIGNED NULL COMMENT 'Voice notes, as the phone measured',
  `created_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_fleet_check_media_check` (`check_id`),
  CONSTRAINT `fk_fleet_check_media_check`
    FOREIGN KEY (`check_id`) REFERENCES `fleet_daily_checks`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
