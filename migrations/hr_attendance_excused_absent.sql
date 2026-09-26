-- Attendance: "Pending" and "Excused Absent" statuses + the note/attachment
-- trail behind every status change.
--
-- Run once on live (phpMyAdmin), BEFORE uploading the PHP files: attendance.php
-- writes the new statuses straight away and MySQL rejects an enum value that is
-- not listed here. Every statement is safe to run a second time.

-- 1. The two new statuses, and Pending becomes what a new row starts as.
--
--    'pending'        = recorded but not yet decided by HR. This is now the
--                       default, so a staff check-in lands here and HR's
--                       Approve button has a real job to do.
--    'excused_absent' = away, but with an accepted reason on file (sick note,
--                       letter, approved emergency).
--
--    Both are APPENDED to the end of the list on purpose. MySQL stores an enum
--    by its position, so inserting a value at the front would silently re-label
--    every stored row. Display order is decided in PHP, not here.
ALTER TABLE `attendance`
  MODIFY `status` ENUM('approved','absent','half','on_leave','excused_absent','pending')
  NOT NULL DEFAULT 'pending';

-- 2. One row per status change: who changed it, from what, to what, the reason
--    typed at the time, and the note the reason replaced (so nothing is lost).
CREATE TABLE IF NOT EXISTS `attendance_status_changes` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `attendance_id` INT(11) NOT NULL,
  `from_status` VARCHAR(32) DEFAULT NULL,
  `to_status` VARCHAR(32) NOT NULL,
  `note` VARCHAR(255) NOT NULL,
  `prev_notes` VARCHAR(255) DEFAULT NULL,
  `changed_by` INT(11) DEFAULT NULL,
  `changed_at` DATETIME NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_asc_attendance` (`attendance_id`),
  CONSTRAINT `fk_asc_attendance` FOREIGN KEY (`attendance_id`)
    REFERENCES `attendance` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- 3. The files. `file_path` is relative to the project root and is never linked
--    directly — hr/attendance_file.php re-checks the session and serves it.
CREATE TABLE IF NOT EXISTS `attendance_attachments` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `attendance_id` INT(11) NOT NULL,
  `change_id` INT(11) DEFAULT NULL,
  `file_name` VARCHAR(255) NOT NULL,
  `file_path` VARCHAR(500) NOT NULL,
  `mime_type` VARCHAR(100) DEFAULT NULL,
  `file_size` INT(11) DEFAULT NULL,
  `uploaded_by` INT(11) DEFAULT NULL,
  `uploaded_at` DATETIME NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_aa_attendance` (`attendance_id`),
  KEY `idx_aa_change` (`change_id`),
  CONSTRAINT `fk_aa_attendance` FOREIGN KEY (`attendance_id`)
    REFERENCES `attendance` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
