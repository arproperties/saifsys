-- ============================================================================
-- Real Estate Module - Maintenance Schedule (Work Orders) + Team Assignments
-- Part of the existing Real Estate -> Maintenance section. No new module.
-- Safe to run multiple times (CREATE TABLE IF NOT EXISTS).
-- ============================================================================

CREATE TABLE IF NOT EXISTS `re_maintenance_schedules` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `company_id` INT(11) NOT NULL,
  `work_order_number` VARCHAR(40) DEFAULT NULL,
  `maintenance_request_id` INT(11) DEFAULT NULL COMMENT 'Optional link to re_maintenance_requests',
  `building_id` INT(11) DEFAULT NULL,
  `unit_id` INT(11) DEFAULT NULL,
  `task_type` ENUM('ac','electrical','plumbing','civil','painting','carpentry','inspection','general','emergency','other')
    NOT NULL DEFAULT 'general',
  `title` VARCHAR(255) DEFAULT NULL,
  `description` TEXT DEFAULT NULL,
  `priority` ENUM('low','normal','high','emergency') NOT NULL DEFAULT 'normal',
  `schedule_date` DATE NOT NULL,
  `start_time` TIME NOT NULL DEFAULT '09:00:00',
  `end_time` TIME NOT NULL DEFAULT '10:00:00',
  `status` ENUM('scheduled','in_progress','completed','delayed','cancelled') NOT NULL DEFAULT 'scheduled',
  `notes` TEXT DEFAULT NULL,
  `created_by` INT(11) DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_company_date` (`company_id`, `schedule_date`),
  KEY `idx_request` (`maintenance_request_id`),
  KEY `idx_building` (`building_id`),
  KEY `idx_unit` (`unit_id`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `re_maintenance_schedule_assignees` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `company_id` INT(11) NOT NULL,
  `schedule_id` INT(11) NOT NULL,
  `employee_id` INT(11) NOT NULL,
  `team_role` ENUM('engineer','supervisor','technician','helper','other') NOT NULL DEFAULT 'technician',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_schedule_employee` (`schedule_id`, `employee_id`),
  KEY `idx_employee` (`employee_id`),
  KEY `idx_company` (`company_id`),
  CONSTRAINT `fk_re_sched_assignee_schedule`
    FOREIGN KEY (`schedule_id`) REFERENCES `re_maintenance_schedules` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ----------------------------------------------------------------------------
-- Employee capacity columns used by the schedule utilization bars.
-- These usually already exist; the IF NOT EXISTS guard (MariaDB) makes this
-- migration safe to run on installs that don't have them yet.
-- ----------------------------------------------------------------------------
ALTER TABLE `employees` ADD COLUMN IF NOT EXISTS `daily_cap_hours` DECIMAL(5,2) DEFAULT NULL;
ALTER TABLE `employees` ADD COLUMN IF NOT EXISTS `weekly_cap_hours` DECIMAL(5,2) DEFAULT NULL;
 