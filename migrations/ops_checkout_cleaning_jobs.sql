-- Operations — cleaning jobs from ARS guest checkouts and tenant move-outs.
--
-- Both land in the Requests pool like a tenant's request: source_type
-- 'ars_checkout' with ars_bookings.id, or 'tenant_move_out' with
-- re_move_outs.id. The unique key on (source_type, source_id) keeps it to one
-- job each. See modules/operations/includes/ops_sources.php.
--
-- Includes 'cleaner_report' from ops_cleaning_checklist.sql, so this is safe
-- whether or not that one has run. Safe to re-run.

ALTER TABLE `ops_jobs`
  MODIFY COLUMN `source_type` ENUM('staff','tenant_maintenance','tenant_cleaning','cleaner_report','ars_checkout','tenant_move_out')
    NOT NULL DEFAULT 'staff' COMMENT 'Where the job came from';
