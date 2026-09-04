-- Payroll validation override marker (WPS / take-home guards).
-- Does NOT change the 85% / 90% rules — only stores authorized override metadata.
--
-- Permission key (grant via role_modules.permissions JSON under module `hr`):
--   {"payroll":["override_validation", ...]}
-- Owner/Admin already bypass has_permission() and can override without this grant.
-- Regular HR users cannot override unless this permission is granted.

SET @db := DATABASE();

SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'payroll_runs' AND COLUMN_NAME = 'validation_override'
);
SET @sql := IF(@col_exists = 0,
  'ALTER TABLE payroll_runs
     ADD COLUMN validation_override TINYINT(1) NOT NULL DEFAULT 0
       COMMENT ''1 = saved/posted with authorized validation override'' AFTER payroll_type,
     ADD COLUMN validation_override_reason TEXT NULL AFTER validation_override,
     ADD COLUMN validation_override_failures MEDIUMTEXT NULL
       COMMENT ''JSON: codes + failure messages at override time'' AFTER validation_override_reason,
     ADD COLUMN validation_override_by INT NULL AFTER validation_override_failures,
     ADD COLUMN validation_override_at DATETIME NULL AFTER validation_override_by',
  'SELECT ''payroll_runs.validation_override already exists'' AS info'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
