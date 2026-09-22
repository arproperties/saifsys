-- Driver app — daily pre-drive checklist.
--
-- Once a day per driver per vehicle, before their first trip in it: every
-- item answered OK or Problem (a few also allow N/A), plus the starting
-- kilometres. A Problem needs a note but does not block the trip — the check
-- shows in red on HR → Fleet → Daily Checks until the office marks it seen.
--
-- The item list lives in code (hr/includes/hr_fleet_checks.php) and each
-- check stores its answers by item key, so old checks read correctly after the
-- wording changes.

CREATE TABLE IF NOT EXISTS `fleet_daily_checks` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `company_id` INT(11) NOT NULL COMMENT 'The vehicle''s company at the time',
  `vehicle_id` INT(11) NOT NULL,
  `driver_user_id` INT(11) NOT NULL,
  `driver_name` VARCHAR(150) NOT NULL DEFAULT '' COMMENT 'Snapshot, like fleet_trips',
  `check_date` DATE NOT NULL COMMENT 'Dubai date',
  `start_km` INT(11) UNSIGNED NOT NULL COMMENT 'Odometer reading before driving',
  `answers` TEXT NOT NULL COMMENT 'JSON {item_key: ok|problem|na}',
  `problem_count` TINYINT(3) UNSIGNED NOT NULL DEFAULT 0,
  `notes` TEXT NULL COMMENT 'What is wrong — required when problem_count > 0',
  `created_at` DATETIME NOT NULL,
  `reviewed_at` DATETIME NULL COMMENT 'Office marked the problems as seen',
  `reviewed_by` INT(11) NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_fleet_daily_checks_day` (`vehicle_id`, `driver_user_id`, `check_date`),
  KEY `idx_fleet_daily_checks_date` (`check_date`),
  KEY `idx_fleet_daily_checks_open` (`problem_count`, `reviewed_at`),
  CONSTRAINT `fk_fleet_daily_checks_vehicle`
    FOREIGN KEY (`vehicle_id`) REFERENCES `fleet_vehicles`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
