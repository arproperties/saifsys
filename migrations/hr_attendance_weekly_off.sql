-- Attendance: a "Weekly Off" status HR can save for a day (Quick Add, Edit,
-- Bulk Apply, or More > Weekly Off on a row).
--
-- Run once on live (phpMyAdmin). Until it has run, the option simply does not
-- appear; nothing breaks. Safe to run a second time.
--
-- 'weekly_off' is APPENDED to the end of the list on purpose: MySQL stores an
-- enum by its position, so inserting a value anywhere else would silently
-- re-label every stored row. Display order is decided in PHP.
--
-- Payroll is unchanged: it deducts only 'absent' and 'half', so a Weekly Off
-- row costs nothing, the same as a day with no row.
ALTER TABLE `attendance`
  MODIFY `status` ENUM('approved','absent','half','on_leave','excused_absent','pending','weekly_off')
  NOT NULL DEFAULT 'pending';

-- Weekly off plans (Attendance > Add Weekly Off): HR picks employees, a weekday
-- and a date range; one save is one group, listed and deleted on
-- hr/attendance_weekly_off.php. The Attendance list shows those days as Weekly
-- Off; nothing is written to attendance, so payroll is unchanged.
CREATE TABLE IF NOT EXISTS `hr_weekly_off_plans` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `weekday` TINYINT(1) NOT NULL COMMENT '1 = Monday ... 7 = Sunday',
  `repeat_weeks` TINYINT(1) NOT NULL DEFAULT 1 COMMENT '1 = every week, 2 = every other week, ... counted from the first such day on or after date_from',
  `date_from` DATE NOT NULL,
  `date_to` DATE NOT NULL,
  `created_by` INT(11) DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_wop_dates` (`date_from`, `date_to`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `hr_weekly_off_plan_employees` (
  `plan_id` INT(11) NOT NULL,
  `employee_id` INT(11) NOT NULL,
  PRIMARY KEY (`plan_id`, `employee_id`),
  KEY `idx_wope_employee` (`employee_id`),
  CONSTRAINT `fk_wope_plan` FOREIGN KEY (`plan_id`)
    REFERENCES `hr_weekly_off_plans` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_wope_employee` FOREIGN KEY (`employee_id`)
    REFERENCES `employees` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
