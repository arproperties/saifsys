-- Operations — orders scheduled in the old cleaning module become app jobs
-- (2026-09-22). See modules/operations/includes/ops_work_order_link.php.
--
-- Adds 'work_order' to ops_jobs.source_type. Until this runs, saving an order
-- in the old module links nothing (the code checks for the value first).
--
-- Safe to re-run.

ALTER TABLE `ops_jobs`
  MODIFY COLUMN `source_type`
    ENUM('staff','tenant_maintenance','tenant_cleaning','cleaner_report','ars_checkout','tenant_move_out','customer_booking','work_order')
    NOT NULL DEFAULT 'staff' COMMENT 'Where the job came from';
