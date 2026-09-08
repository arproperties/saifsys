-- Operations Module — cleaning & maintenance task tracking
-- Jobs, time tracking, before/after photos, comments.
--
-- Materials are not tracked per job. A request is a flagged message
-- (ops_job_comments.is_material_request, which raises ops_jobs.needs_materials)
-- and the answer is a movement on the Stock page — see operations_inventory.sql.
-- An earlier version of this file created ops_job_materials; that table is
-- dropped by ops_drop_job_materials.sql.

-- ============================================================================
-- 1. Jobs
-- ============================================================================
CREATE TABLE IF NOT EXISTS `ops_jobs` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `company_id` INT(11) NOT NULL,
  `job_type` ENUM('cleaning','maintenance') NOT NULL DEFAULT 'cleaning',
  `title` VARCHAR(255) NOT NULL,
  `location` VARCHAR(255) DEFAULT NULL COMMENT 'Free text: building, unit, site, area',
  `description` TEXT DEFAULT NULL COMMENT 'What needs doing — shown to the technician',
  `assigned_to` INT(11) DEFAULT NULL COMMENT 'user.id — NULL = unassigned',
  `scheduled_date` DATE NOT NULL,
  `scheduled_time` TIME DEFAULT NULL COMMENT 'Optional start time shown on the field screen',
  `priority` ENUM('low','normal','high') NOT NULL DEFAULT 'normal',
  `status` ENUM('open','in_progress','done','cancelled') NOT NULL DEFAULT 'open',
  `started_at` DATETIME DEFAULT NULL COMMENT 'Set when technician taps Start',
  `finished_at` DATETIME DEFAULT NULL COMMENT 'Set when technician taps Finish',
  `duration_minutes` INT(11) DEFAULT NULL COMMENT 'finished_at - started_at, stored on finish',
  `needs_materials` TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1 = technician flagged a pending material request',
  `completion_notes` TEXT DEFAULT NULL,
  `created_by` INT(11) DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_ops_jobs_company` (`company_id`),
  KEY `idx_ops_jobs_status` (`status`),
  KEY `idx_ops_jobs_date` (`scheduled_date`),
  KEY `idx_ops_jobs_assigned` (`assigned_to`, `scheduled_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ============================================================================
-- 2. Before / after photos
-- ============================================================================
CREATE TABLE IF NOT EXISTS `ops_job_photos` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `job_id` INT(11) NOT NULL,
  `company_id` INT(11) NOT NULL,
  `photo_type` ENUM('before','after') NOT NULL DEFAULT 'before',
  `file_path` VARCHAR(500) NOT NULL COMMENT 'Relative to app root, e.g. uploads/operations/jobs/12/x.jpg',
  `caption` VARCHAR(255) DEFAULT NULL,
  `uploaded_by` INT(11) DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_ops_photos_job` (`job_id`, `photo_type`),
  CONSTRAINT `fk_ops_photos_job` FOREIGN KEY (`job_id`) REFERENCES `ops_jobs`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ============================================================================
-- 3. Comments — one conversation per job
-- ============================================================================
CREATE TABLE IF NOT EXISTS `ops_job_comments` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `job_id` INT(11) NOT NULL,
  `company_id` INT(11) NOT NULL,
  `user_id` INT(11) DEFAULT NULL,
  `comment` TEXT NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_ops_comments_job` (`job_id`, `created_at`),
  CONSTRAINT `fk_ops_comments_job` FOREIGN KEY (`job_id`) REFERENCES `ops_jobs`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
