-- Operations — jobs that start as a tenant's request.
--
-- A job now begins one of two ways. Staff raise their own on the phone (the
-- default, 'staff'), or a tenant asks for something — a maintenance request
-- from the tenant portal or tenant app, or a cleaning booking the office has
-- approved. Those requests still live where they always did and the Real
-- Estate pages still read them untouched; the Operations module copies each
-- one into a job that waits in an open pool until somebody in the field
-- claims it. See modules/operations/includes/ops_sources.php.
--
-- The unique key is what makes that copy safe to run on every page load: one
-- request can become one job, ever. Staff jobs have no source_id, and NULLs do
-- not collide in a unique key, so they are unaffected.
--
-- Safe to re-run. IF NOT EXISTS on ADD COLUMN/INDEX is MariaDB (this server is
-- MariaDB), matching migrations/ops_comment_material_request.sql.

ALTER TABLE `ops_jobs`
  ADD COLUMN IF NOT EXISTS `source_type` ENUM('staff','tenant_maintenance','tenant_cleaning')
    NOT NULL DEFAULT 'staff' COMMENT 'Where the job came from' AFTER `description`,
  ADD COLUMN IF NOT EXISTS `source_id` INT(11) DEFAULT NULL
    COMMENT 're_maintenance_requests.id or tenant_cleaning_requests.id, per source_type' AFTER `source_type`;

CREATE UNIQUE INDEX IF NOT EXISTS `uniq_ops_jobs_source`
  ON `ops_jobs` (`source_type`, `source_id`);
