-- Operations — the cleaning checklist on unit cleaning jobs.
--
-- Built from the office's Move-Out Inspection Report, cut down to what a
-- cleaner actually does: keys, signatures, inventory and "is it working" checks
-- belong to the inspection, not the clean, and the report's duplicates (AC,
-- floors, walls, curtains, smell) are merged. The items themselves live in
-- modules/operations/includes/ops_checklist.php, so the list can change
-- without a new APK.
--
-- One row per finished job. The cleaner ticks on the phone — offline if need
-- be — and the whole checklist arrives with Finish, which the server refuses
-- until every item is Done or N/A.
--
-- A problem the cleaner flags (a leak, a broken appliance) becomes one
-- maintenance job in the Requests pool, source_type 'cleaner_report' with the
-- cleaning job's id as source_id. The unique key on (source_type, source_id)
-- keeps that to one per cleaning job. Maintenance raised by staff is not
-- invoiced, so nothing about billing changes.
--
-- Safe to re-run.

CREATE TABLE IF NOT EXISTS `ops_job_checklists` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `job_id` INT(11) NOT NULL,
  `company_id` INT(11) NOT NULL,
  `items` TEXT NOT NULL COMMENT 'JSON: item key => done | na',
  `problems` VARCHAR(255) DEFAULT NULL COMMENT 'Comma-separated problem keys the cleaner flagged',
  `problem_note` TEXT DEFAULT NULL,
  `maintenance_job_id` INT(11) DEFAULT NULL COMMENT 'ops_jobs.id raised from the problems, if any',
  `completed_by` INT(11) DEFAULT NULL,
  `created_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_ops_job_checklist` (`job_id`),
  CONSTRAINT `fk_ops_checklist_job` FOREIGN KEY (`job_id`) REFERENCES `ops_jobs`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

ALTER TABLE `ops_jobs`
  MODIFY COLUMN `source_type` ENUM('staff','tenant_maintenance','tenant_cleaning','cleaner_report')
    NOT NULL DEFAULT 'staff' COMMENT 'Where the job came from';
