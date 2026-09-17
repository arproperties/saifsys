-- Operations: customer-app bookings go straight to the field app's Requests pool.
-- See modules/operations/includes/ops_sources.php, ops_sync_customer_bookings().
-- Run after ops_checkout_cleaning_jobs.sql.

ALTER TABLE `ops_jobs`
  MODIFY COLUMN `source_type` ENUM('staff','tenant_maintenance','tenant_cleaning','cleaner_report','ars_checkout','tenant_move_out','customer_booking')
    NOT NULL DEFAULT 'staff' COMMENT 'Where the job came from';
