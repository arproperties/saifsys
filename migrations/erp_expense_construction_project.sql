-- Link ERP expenses to Construction projects (safe re-run)
-- Adds optional project_id to erp_expense_headers for construction expenses.

SET @col := (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'erp_expense_headers'
    AND COLUMN_NAME = 'project_id'
);
SET @sql := IF(
  @col = 0,
  'ALTER TABLE `erp_expense_headers` ADD COLUMN `project_id` int(11) DEFAULT NULL AFTER `expense_date`',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @idx := (
  SELECT COUNT(*)
  FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'erp_expense_headers'
    AND INDEX_NAME = 'idx_erp_exp_project'
);
SET @sql := IF(
  @idx = 0,
  'ALTER TABLE `erp_expense_headers` ADD KEY `idx_erp_exp_project` (`project_id`)',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
