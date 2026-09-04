-- Allow fractional unpaid absence days on payroll items (half-day attendance = 0.5).
-- Safe / idempotent: only alters when unpaid_leave_days is still an integer type.

SET @db := DATABASE();

SET @col_type := (
  SELECT DATA_TYPE
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db
    AND TABLE_NAME = 'payroll_items'
    AND COLUMN_NAME = 'unpaid_leave_days'
  LIMIT 1
);

SET @sql := IF(
  @col_type IS NOT NULL AND @col_type IN ('int', 'tinyint', 'smallint', 'mediumint', 'bigint'),
  'ALTER TABLE payroll_items
     MODIFY COLUMN unpaid_leave_days DECIMAL(8,2) NULL DEFAULT NULL',
  'SELECT ''payroll_items.unpaid_leave_days already decimal or missing'' AS info'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
