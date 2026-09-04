-- Real Estate Tasks Phase 2: Subtasks, Labels, Reminders
-- Safely adds supporting tables for advanced task management.

-- Subtasks for tasks
CREATE TABLE IF NOT EXISTS `re_task_subtasks` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `company_id` INT(11) NOT NULL,
  `task_id` INT(11) NOT NULL,
  `title` VARCHAR(255) NOT NULL,
  `is_completed` TINYINT(1) NOT NULL DEFAULT 0,
  `sort_order` INT(11) DEFAULT NULL,
  `created_by` INT(11) DEFAULT NULL,
  `completed_at` DATETIME DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_company_task` (`company_id`, `task_id`),
  KEY `idx_task_completed` (`task_id`, `is_completed`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Labels / tags
CREATE TABLE IF NOT EXISTS `re_task_labels` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `company_id` INT(11) NOT NULL,
  `label_name` VARCHAR(100) NOT NULL,
  `color` VARCHAR(7) DEFAULT '#6c757d',
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_company_label` (`company_id`, `label_name`),
  KEY `idx_company_active` (`company_id`, `is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Labels assigned to tasks (many-to-many)
CREATE TABLE IF NOT EXISTS `re_task_label_links` (
  `task_id` INT(11) NOT NULL,
  `label_id` INT(11) NOT NULL,
  PRIMARY KEY (`task_id`, `label_id`),
  KEY `idx_label` (`label_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Optional reminder fields on tasks
ALTER TABLE `re_tasks`
  ADD COLUMN IF NOT EXISTS `reminder_at` DATETIME NULL AFTER `due_date`,
  ADD COLUMN IF NOT EXISTS `reminder_method` ENUM('email','in_app') NULL AFTER `reminder_at`;

