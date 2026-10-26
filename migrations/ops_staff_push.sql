-- Staff app (/staff/) attendance reminders by web push.
-- At each company's reminder time (HR → Reminders; 18:00 Dubai until set), anyone
-- due to work who has not checked in, or checked in but not out, gets one
-- notification on each phone that turned reminders on.
-- No APK, no Composer package, no Hostinger cron: see includes/web_push.php and
-- modules/operations/includes/ops_attendance_reminder.php. Safe to re-run.

-- One row per phone (browser) that allowed notifications.
CREATE TABLE IF NOT EXISTS `staff_push_subscriptions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `endpoint_hash` char(64) NOT NULL COMMENT 'sha256 of endpoint',
  `endpoint` text NOT NULL,
  `p256dh` varchar(200) NOT NULL,
  `auth` varchar(100) NOT NULL,
  `user_agent` varchar(255) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `last_seen_at` datetime DEFAULT NULL,
  `last_sent_at` datetime DEFAULT NULL,
  `last_status` smallint(6) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_sps_endpoint` (`endpoint_hash`),
  KEY `idx_sps_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- One reminder per person per day, whatever happens.
CREATE TABLE IF NOT EXISTS `staff_push_log` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `employee_id` int(11) DEFAULT NULL,
  `work_date` date NOT NULL,
  `kind` enum('not_checked_in','no_check_out') NOT NULL,
  `phones` smallint(6) NOT NULL DEFAULT 0,
  `delivered` smallint(6) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_spl_user_day` (`user_id`, `work_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Each company's check runs once a day: the first request after that company's
-- time claims its day here.
CREATE TABLE IF NOT EXISTS `staff_push_runs` (
  `work_date` date NOT NULL,
  `company_id` int(11) NOT NULL DEFAULT 0,
  `started_at` datetime NOT NULL DEFAULT current_timestamp(),
  `finished_at` datetime DEFAULT NULL,
  `sent` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`work_date`, `company_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- A database that ran the first version of this file has staff_push_runs keyed
-- by day only: give it the company column. Does nothing when it is already there.
SET @spr_has_company := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'staff_push_runs' AND COLUMN_NAME = 'company_id');
SET @spr_sql := IF(@spr_has_company = 0,
  'ALTER TABLE `staff_push_runs` ADD COLUMN `company_id` int(11) NOT NULL DEFAULT 0 AFTER `work_date`, DROP PRIMARY KEY, ADD PRIMARY KEY (`work_date`, `company_id`)',
  'SELECT 1');
PREPARE spr_stmt FROM @spr_sql;
EXECUTE spr_stmt;
DEALLOCATE PREPARE spr_stmt;
