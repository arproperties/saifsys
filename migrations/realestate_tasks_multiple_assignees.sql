-- Multiple assignees per task: new link table; keep re_tasks.assigned_to as primary/first assignee for compatibility.

CREATE TABLE IF NOT EXISTS `re_task_assignees` (
  `task_id` INT(11) NOT NULL,
  `employee_id` INT(11) NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`task_id`, `employee_id`),
  KEY `idx_employee` (`employee_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Backfill: add current single assignee into assignees table so existing tasks show one assignee
INSERT IGNORE INTO re_task_assignees (task_id, employee_id)
SELECT id, assigned_to FROM re_tasks WHERE assigned_to IS NOT NULL AND assigned_to > 0;
