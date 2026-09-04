-- Performance indexes for strict task visibility + common task list filters
-- Safe to run multiple times.

SET NAMES utf8mb4;

-- re_tasks(company_id, status, due_date)
SET @table_exists := (
    SELECT COUNT(*)
    FROM information_schema.tables
    WHERE table_schema = DATABASE()
      AND table_name = 're_tasks'
);
SET @idx_exists := (
    SELECT COUNT(*)
    FROM information_schema.statistics
    WHERE table_schema = DATABASE()
      AND table_name = 're_tasks'
      AND index_name = 'idx_re_tasks_company_status_due'
);
SET @sql := IF(@table_exists = 0, 'SELECT "re_tasks table not found"',
          IF(@idx_exists = 0, 'CREATE INDEX idx_re_tasks_company_status_due ON re_tasks(company_id, status, due_date)',
                              'SELECT "idx_re_tasks_company_status_due exists"'));
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- re_tasks(company_id, created_by, status)
SET @idx_exists := (
    SELECT COUNT(*)
    FROM information_schema.statistics
    WHERE table_schema = DATABASE()
      AND table_name = 're_tasks'
      AND index_name = 'idx_re_tasks_company_created_status'
);
SET @sql := IF(@table_exists = 0, 'SELECT "re_tasks table not found"',
          IF(@idx_exists = 0, 'CREATE INDEX idx_re_tasks_company_created_status ON re_tasks(company_id, created_by, status)',
                              'SELECT "idx_re_tasks_company_created_status exists"'));
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- re_tasks(company_id, assigned_to, status)
SET @idx_exists := (
    SELECT COUNT(*)
    FROM information_schema.statistics
    WHERE table_schema = DATABASE()
      AND table_name = 're_tasks'
      AND index_name = 'idx_re_tasks_company_assigned_status'
);
SET @sql := IF(@table_exists = 0, 'SELECT "re_tasks table not found"',
          IF(@idx_exists = 0, 'CREATE INDEX idx_re_tasks_company_assigned_status ON re_tasks(company_id, assigned_to, status)',
                              'SELECT "idx_re_tasks_company_assigned_status exists"'));
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- re_task_assignees(employee_id, task_id)
SET @table_exists := (
    SELECT COUNT(*)
    FROM information_schema.tables
    WHERE table_schema = DATABASE()
      AND table_name = 're_task_assignees'
);
SET @idx_exists := (
    SELECT COUNT(*)
    FROM information_schema.statistics
    WHERE table_schema = DATABASE()
      AND table_name = 're_task_assignees'
      AND index_name = 'idx_re_task_assignees_employee_task'
);
SET @sql := IF(@table_exists = 0, 'SELECT "re_task_assignees table not found"',
          IF(@idx_exists = 0, 'CREATE INDEX idx_re_task_assignees_employee_task ON re_task_assignees(employee_id, task_id)',
                              'SELECT "idx_re_task_assignees_employee_task exists"'));
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

