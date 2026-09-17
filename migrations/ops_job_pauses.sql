-- Operations — staff can pause a job.
--
-- A job that has started can be paused and resumed, with a one-tap reason:
-- a break, waiting for materials, the tenant not being there. The job stays
-- 'in_progress' while paused — it is still somebody's job and still running —
-- so every list, late rule and report that already reads that status keeps
-- working untouched. The clock is not stopped: time spent paused counts in the
-- job's duration and in what it bills (the decision made for this feature).
--
-- 1. On the job: whether it is paused right now, since when, and why. That is
--    what the phone and the office board need to show at a glance.
--
-- 2. Every pause, kept: who, why, from when until when. The office can see a
--    job that stopped three times for materials without asking anybody.
--
-- Safe to re-run. IF NOT EXISTS on ADD COLUMN is MariaDB (this server is
-- MariaDB), matching migrations/ops_comment_material_request.sql.

ALTER TABLE `ops_jobs`
  ADD COLUMN IF NOT EXISTS `paused_at` DATETIME DEFAULT NULL
    COMMENT 'Set while the job is paused, NULL when it is running',
  ADD COLUMN IF NOT EXISTS `pause_reason` VARCHAR(30) DEFAULT NULL
    COMMENT 'break, materials, tenant or other, while paused';

CREATE TABLE IF NOT EXISTS `ops_job_pauses` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `job_id` INT(11) NOT NULL,
  `company_id` INT(11) NOT NULL,
  `user_id` INT(11) DEFAULT NULL COMMENT 'Who paused it',
  `reason` VARCHAR(30) NOT NULL,
  `paused_at` DATETIME NOT NULL,
  `resumed_at` DATETIME DEFAULT NULL COMMENT 'NULL while still paused',
  PRIMARY KEY (`id`),
  KEY `idx_ops_job_pauses_job` (`job_id`, `paused_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
