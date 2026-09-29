-- Operations — R410 gas weighed before and after, per unit, on maintenance jobs.
--
-- The boss's rule: before using the gas cylinder, weigh it and send the weight
-- with the unit number; after using it, weigh it again. Every unit, every time —
-- three units in a day means three pairs of weights. Until now this went into a
-- WhatsApp group as a photo of the scale.
--
-- One row per unit per use. The technician takes a photo of the scale and types
-- the kg for Before, does the work, then does the same for After. The app will
-- not finish the job while a Before has no After, and on Finish it asks every
-- maintenance job "did you use gas?" — gas_used holds that answer.
--
-- client_ref is the phone's own id for the reading. The app queues work while
-- offline, so the After can reach the server before anyone knows the reading's
-- real id; it names the reading by client_ref instead. Unique per job, which
-- also makes a replayed Before harmless.
--
-- place_kind/place_id/unit_label follow ops_job_places: the id is the link, the
-- label is what it was called on the day.
--
-- Safe to re-run.

CREATE TABLE IF NOT EXISTS `ops_job_gas_readings` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `job_id` INT(11) NOT NULL,
  `company_id` INT(11) NOT NULL,
  `client_ref` VARCHAR(64) NOT NULL COMMENT 'The phone''s id for this reading',
  `place_kind` ENUM('unit','common_area') DEFAULT NULL,
  `place_id` INT(11) DEFAULT NULL COMMENT 're_units.id or re_building_common_areas.id, per place_kind',
  `building_id` INT(11) DEFAULT NULL,
  `unit_label` VARCHAR(255) NOT NULL COMMENT 'The unit, as picked or typed',
  `before_kg` DECIMAL(7,3) NOT NULL,
  `before_photo` VARCHAR(255) NOT NULL,
  `before_at` DATETIME NOT NULL,
  `before_by` INT(11) DEFAULT NULL,
  `after_kg` DECIMAL(7,3) DEFAULT NULL,
  `after_photo` VARCHAR(255) DEFAULT NULL,
  `after_at` DATETIME DEFAULT NULL,
  `after_by` INT(11) DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_ops_gas_ref` (`job_id`, `client_ref`),
  KEY `idx_ops_gas_before_at` (`before_at`),
  KEY `idx_ops_gas_place` (`place_kind`, `place_id`),
  CONSTRAINT `fk_ops_gas_job` FOREIGN KEY (`job_id`) REFERENCES `ops_jobs`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- NULL = not asked (an older app, or a cleaning job). 0 = "No gas used". 1 = used.
SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ops_jobs' AND COLUMN_NAME = 'gas_used');
SET @sql := IF(@col = 0,
  'ALTER TABLE `ops_jobs` ADD COLUMN `gas_used` TINYINT(1) DEFAULT NULL COMMENT ''Did the technician use R410 gas: NULL not asked, 0 no, 1 yes''',
  'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
