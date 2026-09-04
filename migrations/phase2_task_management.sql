-- Phase 2: Task Management System
-- Internal operations tracking for property management

-- ============================================================================
-- 1. Task Categories
-- ============================================================================
-- Categories for organizing different types of tasks
CREATE TABLE IF NOT EXISTS `re_task_categories` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `company_id` INT(11) NOT NULL,
  `category_name` VARCHAR(100) NOT NULL,
  `description` TEXT DEFAULT NULL,
  `color` VARCHAR(7) DEFAULT '#007bff' COMMENT 'Hex color for UI',
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_company_category` (`company_id`, `category_name`),
  KEY `idx_company` (`company_id`),
  FOREIGN KEY (`company_id`) REFERENCES `companies`(`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ============================================================================
-- 2. Tasks
-- ============================================================================
-- Internal operational tasks for property management
CREATE TABLE IF NOT EXISTS `re_tasks` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `company_id` INT(11) NOT NULL,
  `task_title` VARCHAR(255) NOT NULL,
  `task_description` TEXT DEFAULT NULL,
  `category_id` INT(11) DEFAULT NULL,
  `task_type` ENUM('inspection', 'follow_up', 'approval', 'general', 'maintenance', 'compliance', 'documentation', 'other') NOT NULL DEFAULT 'general',
  `priority` ENUM('low', 'medium', 'high', 'urgent') NOT NULL DEFAULT 'medium',
  `status` ENUM('pending', 'in_progress', 'on_hold', 'completed', 'cancelled') NOT NULL DEFAULT 'pending',
  `assigned_to` INT(11) DEFAULT NULL COMMENT 'employee_id',
  `created_by` INT(11) DEFAULT NULL COMMENT 'user_id',
  `due_date` DATE DEFAULT NULL,
  `start_date` DATE DEFAULT NULL,
  `completed_date` DATE DEFAULT NULL,
  `estimated_hours` DECIMAL(10,2) DEFAULT NULL,
  `actual_hours` DECIMAL(10,2) DEFAULT NULL,
  `related_type` ENUM('building', 'unit', 'tenant', 'lease', 'maintenance_request', 'payment', 'document', 'other') DEFAULT NULL,
  `related_id` INT(11) DEFAULT NULL COMMENT 'ID of related record',
  `building_id` INT(11) DEFAULT NULL,
  `unit_id` INT(11) DEFAULT NULL,
  `tenant_id` INT(11) DEFAULT NULL,
  `notes` TEXT DEFAULT NULL,
  `completion_notes` TEXT DEFAULT NULL,
  `attachments` TEXT DEFAULT NULL COMMENT 'JSON array of file paths',
  `is_recurring` TINYINT(1) NOT NULL DEFAULT 0,
  `recurrence_pattern` VARCHAR(100) DEFAULT NULL COMMENT 'daily, weekly, monthly, etc.',
  `recurrence_interval` INT(11) DEFAULT NULL,
  `parent_task_id` INT(11) DEFAULT NULL COMMENT 'For recurring tasks',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_company` (`company_id`),
  KEY `idx_category` (`category_id`),
  KEY `idx_assigned_to` (`assigned_to`),
  KEY `idx_created_by` (`created_by`),
  KEY `idx_status` (`status`),
  KEY `idx_priority` (`priority`),
  KEY `idx_due_date` (`due_date`),
  KEY `idx_related` (`related_type`, `related_id`),
  KEY `idx_building` (`building_id`),
  KEY `idx_unit` (`unit_id`),
  KEY `idx_tenant` (`tenant_id`),
  KEY `idx_parent_task` (`parent_task_id`),
  FOREIGN KEY (`company_id`) REFERENCES `companies`(`id`) ON DELETE RESTRICT,
  FOREIGN KEY (`category_id`) REFERENCES `re_task_categories`(`id`) ON DELETE SET NULL,
  FOREIGN KEY (`assigned_to`) REFERENCES `employees`(`id`) ON DELETE SET NULL,
  FOREIGN KEY (`created_by`) REFERENCES `user`(`id`) ON DELETE SET NULL,
  FOREIGN KEY (`building_id`) REFERENCES `re_buildings`(`id`) ON DELETE SET NULL,
  FOREIGN KEY (`unit_id`) REFERENCES `re_units`(`id`) ON DELETE SET NULL,
  FOREIGN KEY (`tenant_id`) REFERENCES `re_tenants`(`id`) ON DELETE SET NULL,
  FOREIGN KEY (`parent_task_id`) REFERENCES `re_tasks`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ============================================================================
-- 3. Task Comments
-- ============================================================================
-- Comments and updates on tasks
CREATE TABLE IF NOT EXISTS `re_task_comments` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `company_id` INT(11) NOT NULL,
  `task_id` INT(11) NOT NULL,
  `user_id` INT(11) DEFAULT NULL,
  `comment` TEXT NOT NULL,
  `is_internal` TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'Internal note vs visible to tenant',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_company` (`company_id`),
  KEY `idx_task` (`task_id`),
  KEY `idx_user` (`user_id`),
  FOREIGN KEY (`company_id`) REFERENCES `companies`(`id`) ON DELETE RESTRICT,
  FOREIGN KEY (`task_id`) REFERENCES `re_tasks`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`user_id`) REFERENCES `user`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ============================================================================
-- 4. Task Attachments
-- ============================================================================
-- File attachments for tasks
CREATE TABLE IF NOT EXISTS `re_task_attachments` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `company_id` INT(11) NOT NULL,
  `task_id` INT(11) NOT NULL,
  `file_name` VARCHAR(255) NOT NULL,
  `file_path` VARCHAR(500) NOT NULL,
  `file_size` INT(11) NOT NULL,
  `mime_type` VARCHAR(100) DEFAULT NULL,
  `uploaded_by` INT(11) DEFAULT NULL,
  `uploaded_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_company` (`company_id`),
  KEY `idx_task` (`task_id`),
  FOREIGN KEY (`company_id`) REFERENCES `companies`(`id`) ON DELETE RESTRICT,
  FOREIGN KEY (`task_id`) REFERENCES `re_tasks`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`uploaded_by`) REFERENCES `user`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ============================================================================
-- 5. Task History
-- ============================================================================
-- Audit trail of task changes
CREATE TABLE IF NOT EXISTS `re_task_history` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `company_id` INT(11) NOT NULL,
  `task_id` INT(11) NOT NULL,
  `action` VARCHAR(50) NOT NULL COMMENT 'created, assigned, status_changed, priority_changed, etc.',
  `old_value` VARCHAR(255) DEFAULT NULL,
  `new_value` VARCHAR(255) DEFAULT NULL,
  `changed_by` INT(11) DEFAULT NULL,
  `notes` TEXT DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_company` (`company_id`),
  KEY `idx_task` (`task_id`),
  KEY `idx_changed_by` (`changed_by`),
  FOREIGN KEY (`company_id`) REFERENCES `companies`(`id`) ON DELETE RESTRICT,
  FOREIGN KEY (`task_id`) REFERENCES `re_tasks`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`changed_by`) REFERENCES `user`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ============================================================================
-- 6. Task Templates
-- ============================================================================
-- Reusable task templates for common operations
CREATE TABLE IF NOT EXISTS `re_task_templates` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `company_id` INT(11) NOT NULL,
  `template_name` VARCHAR(255) NOT NULL,
  `task_type` ENUM('inspection', 'follow_up', 'approval', 'general', 'maintenance', 'compliance', 'documentation', 'other') NOT NULL DEFAULT 'general',
  `category_id` INT(11) DEFAULT NULL,
  `description` TEXT DEFAULT NULL,
  `default_priority` ENUM('low', 'medium', 'high', 'urgent') NOT NULL DEFAULT 'medium',
  `estimated_hours` DECIMAL(10,2) DEFAULT NULL,
  `checklist_items` TEXT DEFAULT NULL COMMENT 'JSON array',
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_company` (`company_id`),
  KEY `idx_category` (`category_id`),
  FOREIGN KEY (`company_id`) REFERENCES `companies`(`id`) ON DELETE RESTRICT,
  FOREIGN KEY (`category_id`) REFERENCES `re_task_categories`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Insert default categories
INSERT INTO `re_task_categories` (`company_id`, `category_name`, `description`, `color`) VALUES
(1, 'Inspections', 'Property and unit inspections', '#28a745'),
(1, 'Follow-ups', 'Follow-up tasks and reminders', '#ffc107'),
(1, 'Approvals', 'Approval workflows', '#17a2b8'),
(1, 'Maintenance', 'Maintenance-related tasks', '#dc3545'),
(1, 'Compliance', 'Compliance and legal tasks', '#6f42c1'),
(1, 'Documentation', 'Document management tasks', '#20c997'),
(1, 'General', 'General operational tasks', '#007bff')
ON DUPLICATE KEY UPDATE category_name = category_name;

