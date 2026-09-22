-- Operations — finished jobs wait for the office to Finalize (2026-09-22).
--
-- Finish on the phone still writes the work order (make_order), completed,
-- but no longer invoices it. The office checks it in the old Work Orders list
-- and presses Finalize, as before. Until then the job's billing_status is
-- 'awaiting_finalize'. See modules/operations/includes/ops_billing.php.
--
-- Run BEFORE uploading the new ops_billing.php: without this value MariaDB
-- stores '' (or refuses, in strict mode) when a job finishes.
--
-- Safe to re-run.

ALTER TABLE `ops_jobs`
  MODIFY COLUMN `billing_status`
    ENUM('billed','not_billable','no_client','failed','awaiting_finalize') DEFAULT NULL
    COMMENT 'Set when the job finishes, NULL until then';
