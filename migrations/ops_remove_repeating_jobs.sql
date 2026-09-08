-- Operations — remove the Repeating jobs (schedules) feature.
--
-- Jobs that a schedule created stay exactly where they are; only the link back
-- to the rule and the rules themselves go. Run once on any database that
-- already has migrations/operations_module.sql applied.

-- 1. Drop the index that covers schedule_id, then the column itself.
ALTER TABLE `ops_jobs` DROP INDEX `idx_ops_jobs_schedule_date`;
ALTER TABLE `ops_jobs` DROP COLUMN `schedule_id`;

-- 2. Drop the rules table.
DROP TABLE IF EXISTS `ops_schedules`;
