-- Attendance: one break per day.
--
-- The person taps Break when they leave the desk and taps again when they are
-- back. The pair is stored beside their check-in on the same row — one break
-- per person per day, which is what `attendance` being unique on
-- (employee_id, work_date) already gives us.
--
-- `hours` is deliberately NOT touched by a break. The day is still measured
-- check-in to check-out; the break is recorded so HR can see it, and nothing
-- in payroll, the summary or performance reads these columns.
--
-- Run once on live (phpMyAdmin) BEFORE uploading the PHP files. Every
-- statement is safe to run a second time.

-- 1. When the break started, in company time (Asia/Dubai), written as a
--    literal by PHP. NULL means no break taken yet today.
SET @col := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE()
     AND TABLE_NAME = 'attendance'
     AND COLUMN_NAME = 'break_start'
);
SET @sql := IF(@col = 0,
  'ALTER TABLE `attendance` ADD COLUMN `break_start` TIME NULL DEFAULT NULL AFTER `check_out_ip`',
  'DO 0'
);
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- 2. When they came back. A row with break_start set and break_end empty is
--    somebody currently on break — that is what puts the break screen up.
SET @col := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE()
     AND TABLE_NAME = 'attendance'
     AND COLUMN_NAME = 'break_end'
);
SET @sql := IF(@col = 0,
  'ALTER TABLE `attendance` ADD COLUMN `break_end` TIME NULL DEFAULT NULL AFTER `break_start`',
  'DO 0'
);
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- 3. The length in minutes, worked out once when they tap Back so every page
--    shows the same figure without repeating the arithmetic.
SET @col := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE()
     AND TABLE_NAME = 'attendance'
     AND COLUMN_NAME = 'break_minutes'
);
SET @sql := IF(@col = 0,
  'ALTER TABLE `attendance` ADD COLUMN `break_minutes` SMALLINT UNSIGNED NULL DEFAULT NULL AFTER `break_end`',
  'DO 0'
);
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
