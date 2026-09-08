-- Operations — daily repeating jobs (cleaners).
--
-- The supervisor does not manage a separate list of rules. They create one job
-- and tick "repeat every day"; that job becomes the head of a series and the
-- server fills in each following day on its own. This is deliberately narrower
-- than the ops_schedules table that ops_remove_repeating_jobs.sql took out:
-- there is no second screen to keep in step with the job it came from.
--
-- Run once, after migrations/operations_module.sql.

ALTER TABLE `ops_jobs`
  ADD COLUMN `repeat_daily` TINYINT(1) NOT NULL DEFAULT 0
      COMMENT '1 = this job is the head of a daily series and still repeating',
  ADD COLUMN `series_id` INT(11) DEFAULT NULL
      COMMENT 'id of the head job. Head rows point at themselves; NULL = one-off';

-- Two page loads landing in the same second must not both create today's job.
-- The generator relies on this key, not on checking first: NULL series_id
-- (every one-off job) is exempt, which is exactly what we want.
ALTER TABLE `ops_jobs`
  ADD UNIQUE KEY `uniq_ops_jobs_series_date` (`series_id`, `scheduled_date`);

-- The generator's own lookup: which heads in this company are still repeating.
ALTER TABLE `ops_jobs`
  ADD KEY `idx_ops_jobs_repeat` (`company_id`, `repeat_daily`);
