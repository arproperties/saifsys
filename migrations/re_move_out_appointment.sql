-- Move-Out: Inspection Appointment (its own step before the Final Inspection)
--
-- The inspection is booked either on the move-out day (usual) or on another
-- date, with a time window. The date stays in re_move_outs.inspection_scheduled_date.
-- The tenant gets a confirmation email (with a calendar invite) and reminders:
-- "Send reminder now", and automatic ones the day before and on the morning
-- (cron/move_out_inspection_reminders.php). Every change and email is kept in
-- re_move_out_appointment_log.
--
-- Safe to run more than once.

ALTER TABLE `re_move_outs`
  ADD COLUMN IF NOT EXISTS `inspection_mode` VARCHAR(20) DEFAULT NULL COMMENT 'move_out_day | scheduled',
  ADD COLUMN IF NOT EXISTS `inspection_time_from` TIME DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `inspection_time_to` TIME DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `inspection_access_note` VARCHAR(255) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `inspection_appt_status` VARCHAR(20) DEFAULT NULL COMMENT 'scheduled | rescheduled | missed',
  -- Off for move-outs scheduled before this step: only appointments booked here (where staff
  -- tick it) get automatic reminders
  ADD COLUMN IF NOT EXISTS `inspection_auto_remind` TINYINT(1) NOT NULL DEFAULT 0;

CREATE TABLE IF NOT EXISTS `re_move_out_appointment_log` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `company_id` INT(11) NOT NULL,
  `move_out_id` INT(11) NOT NULL,
  `action` VARCHAR(30) NOT NULL COMMENT 'scheduled | rescheduled | missed | email_confirmation | email_reminder | email_auto_d1 | email_auto_d0',
  `appt_date` DATE DEFAULT NULL,
  `time_from` TIME DEFAULT NULL,
  `time_to` TIME DEFAULT NULL,
  `note` VARCHAR(500) DEFAULT NULL,
  `email_to` VARCHAR(255) DEFAULT NULL,
  `email_ok` TINYINT(1) DEFAULT NULL,
  `created_by` INT(11) DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_rmoal_move_out` (`move_out_id`, `created_at`),
  KEY `idx_rmoal_auto` (`move_out_id`, `action`, `appt_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Saved email templates for the inspection emails (per company and email type:
-- 'reminder' or 'confirmation'). Saved from the email review on the move-out
-- page; the tenant's details are kept as {tenant}, {when}, {date}, {unit}, {address},
-- {lease}, {note}, {company} and filled in for each email.
CREATE TABLE IF NOT EXISTS `re_move_out_email_templates` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `company_id` INT(11) NOT NULL,
  `email_type` VARCHAR(20) NOT NULL,
  `subject` VARCHAR(250) NOT NULL,
  `body_html` MEDIUMTEXT NOT NULL,
  `updated_by` INT(11) DEFAULT NULL,
  `updated_at` DATETIME NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_rmoet_company_type` (`company_id`, `email_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
