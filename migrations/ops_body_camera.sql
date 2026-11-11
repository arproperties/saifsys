-- Body camera — a Looki L1 worn by field staff on chosen tasks (Operations → Body camera).
--
-- The L1 uploads its recordings to Looki's cloud while it charges on Wi-Fi.
-- The photos and videos stay there: saifsys keeps only what they are, and
-- shows one by asking Looki's API for a fresh link when someone opens it.
-- cron/body_camera_sync.php (every 30 minutes) asks Looki's API for each
-- device's new recordings and links each one to the job its wearer had
-- started at that time. The daily report is built from these rows plus Store
-- entries (st_moves) and the Store camera's changes (st_cam_changes).
--
-- Looki's audio summary is never kept: only a sentence of it that names a store
-- item or the store (a "voice event"). Rules: modules/operations/includes/ops_body_camera.php.
--
-- Safe to run more than once.

-- One L1: who wears it and the Looki API key of its account.
CREATE TABLE IF NOT EXISTS `ops_bodycam_devices` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `user_id` INT(11) NOT NULL COMMENT 'Who wears it (user.id, the same as ops_jobs.assigned_to)',
  `label` VARCHAR(80) NOT NULL COMMENT 'e.g. Looki L1 (51289)',
  `api_key` VARCHAR(255) NOT NULL COMMENT 'The Looki account''s API key; only its start is ever shown',
  `active` TINYINT(1) NOT NULL DEFAULT 1,
  `last_sync_at` DATETIME NULL,
  `last_sync_note` VARCHAR(255) NULL COMMENT 'What the last sync did, or why it failed',
  `last_sync_ok` TINYINT(1) NULL,
  `created_at` DATETIME NOT NULL,
  `created_by` INT(11) NULL,
  `updated_at` DATETIME NULL,
  PRIMARY KEY (`id`),
  KEY `idx_ops_bodycam_devices_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- One Looki "moment": a stretch of recording Looki has titled and described.
-- Looki keeps adding to a moment for a while, so each sync updates it.
CREATE TABLE IF NOT EXISTS `ops_bodycam_moments` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `device_id` INT(11) NOT NULL,
  `user_id` INT(11) NOT NULL,
  `looki_id` VARCHAR(64) NOT NULL,
  `title` VARCHAR(255) NULL,
  `note` VARCHAR(500) NULL COMMENT 'The first sentence of Looki''s description, as a work note',
  `start_time` DATETIME NOT NULL COMMENT 'Server time, the same clock as ops_jobs.started_at',
  `end_time` DATETIME NULL,
  `place` VARCHAR(255) NULL,
  `description` TEXT NULL COMMENT 'Looki''s whole description of what it saw, as work notes',
  `keywords` VARCHAR(500) NULL COMMENT 'Looki''s own keywords for the recording (search_context)',
  `office_note` VARCHAR(500) NULL COMMENT 'What the office wrote about it on the daily report',
  `job_chosen` TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1 = the office chose the job below; 0 = linked by time',
  `office_job_id` INT(11) NULL COMMENT 'The job the office chose; NULL with job_chosen 1 = no job',
  `edited_by` INT(11) NULL,
  `edited_at` DATETIME NULL,
  `synced_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_ops_bodycam_moments_looki` (`device_id`, `looki_id`),
  KEY `idx_ops_bodycam_moments_user` (`user_id`, `start_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- One photo or video in Looki's cloud. Not downloaded: body_camera_file.php
-- sends the viewer to a fresh Looki link for it.
CREATE TABLE IF NOT EXISTS `ops_bodycam_files` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `device_id` INT(11) NOT NULL,
  `user_id` INT(11) NOT NULL,
  `moment_id` INT(11) NULL,
  `looki_file_id` VARCHAR(64) NOT NULL,
  `media_type` ENUM('image','video','audio') NOT NULL,
  `recorded_at` DATETIME NOT NULL COMMENT 'Server time',
  `duration_ms` INT(11) NULL,
  `width` INT(11) NULL,
  `height` INT(11) NULL,
  `place` VARCHAR(255) NULL,
  `job_id` INT(11) NULL COMMENT 'NULL = not linked to a job',
  `linked_how` ENUM('auto','office') NULL,
  `linked_by` INT(11) NULL,
  `created_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_ops_bodycam_files_looki` (`device_id`, `looki_file_id`),
  KEY `idx_ops_bodycam_files_user` (`user_id`, `recorded_at`),
  KEY `idx_ops_bodycam_files_job` (`job_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Something the daily report shows as an event: a sentence of Looki's text
-- that names a store item or the store (voice), a Store camera change seen
-- while this person was recording (store_camera), a voice command the wearer
-- said to the L1's assistant (command: Start work, Break, Finish work …, read
-- from Looki's chat history), or one the office added (manual).
-- Attendance, jobs, pauses, trips and store entries are read from their own
-- tables when the report is drawn, not copied here.
CREATE TABLE IF NOT EXISTS `ops_bodycam_events` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `user_id` INT(11) NOT NULL,
  `kind` ENUM('voice','store_camera','manual','command') NOT NULL,
  `label` VARCHAR(30) NULL COMMENT 'manual: Note, Break, Check in …; command: Start work, Break, Finish work …',
  `at` DATETIME NOT NULL COMMENT 'Server time',
  `end_at` DATETIME NULL COMMENT 'manual: when it ended, if it lasted',
  `text` VARCHAR(500) NOT NULL,
  `moment_id` INT(11) NULL COMMENT 'voice: the moment the sentence came from',
  `cam_change_id` INT(11) NULL COMMENT 'store_camera: st_cam_changes.id',
  `file_id` INT(11) NULL COMMENT 'store_camera: the recording closest in time',
  `ref` VARCHAR(64) NOT NULL COMMENT 'Keeps one row per thing: v<moment>-<hash>, c<change>, vc-<hash> or m<random>',
  `created_by` INT(11) NULL COMMENT 'manual: who added it',
  `created_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_ops_bodycam_events_ref` (`user_id`, `ref`),
  KEY `idx_ops_bodycam_events_user` (`user_id`, `at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- The office's note on any other report row (check in, a job, a store entry …),
-- by the row's ref. The row's own text stays as its module wrote it.
CREATE TABLE IF NOT EXISTS `ops_bodycam_row_notes` (
  `user_id` INT(11) NOT NULL,
  `ref` VARCHAR(64) NOT NULL,
  `note` VARCHAR(500) NOT NULL,
  `noted_by` INT(11) NULL,
  `noted_at` DATETIME NOT NULL,
  PRIMARY KEY (`user_id`, `ref`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- A report row the office deleted. Rows from other modules (attendance, jobs,
-- trips, store entries) and the synced events are only hidden from the report,
-- so the sync cannot bring them back and nothing changes in HR, Operations or Store.
CREATE TABLE IF NOT EXISTS `ops_bodycam_hidden` (
  `user_id` INT(11) NOT NULL,
  `ref` VARCHAR(64) NOT NULL COMMENT 'The report row''s ref, e.g. att-in-12, job-s-1042, e55',
  `hidden_by` INT(11) NULL,
  `hidden_at` DATETIME NOT NULL,
  PRIMARY KEY (`user_id`, `ref`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
