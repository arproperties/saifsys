-- HR employee lifecycle / offboarding fields.
-- Keeps former employees in history while excluding them from current workforce by default.

SET @sql := IF(
  EXISTS (
    SELECT 1
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'employees'
      AND COLUMN_NAME = 'status'
  ),
  'ALTER TABLE employees MODIFY status ENUM(''active'',''on_leave'',''notice_period'',''inactive'',''resigned'',''terminated'',''not_renewed'') NOT NULL DEFAULT ''active''',
  'SELECT ''employees.status missing'' AS message'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql := IF(
  NOT EXISTS (
    SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'employees' AND COLUMN_NAME = 'exit_date'
  ),
  'ALTER TABLE employees ADD COLUMN exit_date DATE NULL AFTER date_joined',
  'SELECT ''employees.exit_date already exists'' AS message'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql := IF(
  NOT EXISTS (
    SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'employees' AND COLUMN_NAME = 'last_working_day'
  ),
  'ALTER TABLE employees ADD COLUMN last_working_day DATE NULL AFTER exit_date',
  'SELECT ''employees.last_working_day already exists'' AS message'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql := IF(
  NOT EXISTS (
    SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'employees' AND COLUMN_NAME = 'exit_type'
  ),
  'ALTER TABLE employees ADD COLUMN exit_type ENUM(''resigned'',''terminated'',''not_renewed'',''other'') NULL AFTER last_working_day',
  'SELECT ''employees.exit_type already exists'' AS message'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql := IF(
  NOT EXISTS (
    SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'employees' AND COLUMN_NAME = 'exit_reason'
  ),
  'ALTER TABLE employees ADD COLUMN exit_reason TEXT NULL AFTER exit_type',
  'SELECT ''employees.exit_reason already exists'' AS message'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql := IF(
  NOT EXISTS (
    SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'employees' AND COLUMN_NAME = 'eligible_for_rehire'
  ),
  'ALTER TABLE employees ADD COLUMN eligible_for_rehire TINYINT(1) NULL AFTER exit_reason',
  'SELECT ''employees.eligible_for_rehire already exists'' AS message'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql := IF(
  NOT EXISTS (
    SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'employees' AND COLUMN_NAME = 'final_settlement_status'
  ),
  'ALTER TABLE employees ADD COLUMN final_settlement_status ENUM(''not_started'',''in_progress'',''completed'',''not_applicable'') NOT NULL DEFAULT ''not_started'' AFTER eligible_for_rehire',
  'SELECT ''employees.final_settlement_status already exists'' AS message'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @idx := (
  SELECT COUNT(1)
  FROM INFORMATION_SCHEMA.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'employees'
    AND INDEX_NAME = 'idx_employee_lifecycle'
);
SET @sql := IF(
  @idx = 0,
  'ALTER TABLE employees ADD INDEX idx_employee_lifecycle (status, exit_date, last_working_day)',
  'SELECT ''idx_employee_lifecycle already exists'' AS message'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
