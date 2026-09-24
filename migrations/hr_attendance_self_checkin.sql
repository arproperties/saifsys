-- Staff self check-in / check-out
--
-- Adds the two columns the self-service flow needs on top of the existing
-- `attendance` table. Nothing here changes a row that is already there, and
-- every statement is written so a second run is harmless.
--
-- Run once on live, then upload the PHP files.

-- 1. `source` must accept 'self'. It already does on live, but a database that
--    was set up from an older dump may not have it, and an INSERT with an
--    unknown enum value fails silently into '' on some configurations.
ALTER TABLE `attendance`
  MODIFY `source` ENUM('admin','self','import') NOT NULL DEFAULT 'admin';

-- 2. Where the person was standing when they tapped. Kept so HR can tell an
--    office check-in from one made at home once the office-IP rule is on.
--    45 characters because an IPv6 address needs them.
SET @col := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE()
     AND TABLE_NAME = 'attendance'
     AND COLUMN_NAME = 'check_in_ip'
);
SET @sql := IF(@col = 0,
  'ALTER TABLE `attendance` ADD COLUMN `check_in_ip` VARCHAR(45) NULL DEFAULT NULL AFTER `check_in`',
  'DO 0'
);
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @col := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE()
     AND TABLE_NAME = 'attendance'
     AND COLUMN_NAME = 'check_out_ip'
);
SET @sql := IF(@col = 0,
  'ALTER TABLE `attendance` ADD COLUMN `check_out_ip` VARCHAR(45) NULL DEFAULT NULL AFTER `check_out`',
  'DO 0'
);
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- 3. One row per person per day. The check-in writes with ON DUPLICATE KEY
--    UPDATE, which silently inserts a second row instead of updating if this
--    key is missing — two taps would then become two records. The key is
--    expected to be there already; this adds it only if it is not, and only
--    when no duplicate rows exist to block it. If the SELECT below returns
--    anything, stop and clean those duplicates first.
SELECT employee_id, work_date, COUNT(*) AS copies
  FROM `attendance`
 GROUP BY employee_id, work_date
HAVING copies > 1;

SET @idx := (
  SELECT COUNT(*) FROM information_schema.STATISTICS
   WHERE TABLE_SCHEMA = DATABASE()
     AND TABLE_NAME = 'attendance'
     AND NON_UNIQUE = 0
     AND INDEX_NAME <> 'PRIMARY'
     AND COLUMN_NAME IN ('employee_id','work_date')
);
SET @dupes := (
  SELECT COUNT(*) FROM (
    SELECT 1 FROM `attendance`
     GROUP BY employee_id, work_date
    HAVING COUNT(*) > 1
  ) d
);
SET @sql := IF(@idx < 2 AND @dupes = 0,
  'ALTER TABLE `attendance` ADD UNIQUE KEY `uniq_attendance_employee_date` (`employee_id`,`work_date`)',
  'DO 0'
);
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- 4. The office IP allow-list, empty to begin with. Empty means "allow from
--    anywhere", so the feature behaves normally until you fill this in.
--    Put one or more public IPs in, comma separated, e.g. 94.200.11.22,94.200.11.23
INSERT INTO `settings` (`key`, `value`)
SELECT 'attendance_self_office_ips', ''
 WHERE NOT EXISTS (
   SELECT 1 FROM `settings` WHERE `key` = 'attendance_self_office_ips'
 );
