-- Attendance: a "Day Off" status HR can save for a day (Quick Add, Edit,
-- Bulk Apply, or More > Day Off on a row). A paid day off, shown in the
-- Attendance list with its own card.
--
-- Run once on live (phpMyAdmin), after hr_attendance_weekly_off.sql. Until it
-- has run, the option simply does not appear; nothing breaks. Safe to run a
-- second time.
--
-- 'day_off' is APPENDED to the end of the list on purpose: MySQL stores an
-- enum by its position, so inserting a value anywhere else would silently
-- re-label every stored row. Display order is decided in PHP.
--
-- Payroll is unchanged: it deducts only 'absent' and 'half', so a Day Off row
-- is paid, the same as a Weekly Off.
ALTER TABLE `attendance`
  MODIFY `status` ENUM('approved','absent','half','on_leave','excused_absent','pending','weekly_off','day_off')
  NOT NULL DEFAULT 'pending';
