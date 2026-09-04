-- ============================================================================
-- CONSTRUCTION MODULE — Phase 2: Project Phases & Progress
-- ============================================================================
-- Run after construction_module_phase1.sql
-- ============================================================================

-- co_project_phases (milestones / phases per project)
CREATE TABLE IF NOT EXISTS `co_project_phases` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `company_id` INT(11) NOT NULL,
  `project_id` INT(11) NOT NULL,
  `phase_name` VARCHAR(255) NOT NULL,
  `sequence` INT(11) NOT NULL DEFAULT 0,
  `planned_start_date` DATE DEFAULT NULL,
  `planned_end_date` DATE DEFAULT NULL,
  `actual_start_date` DATE DEFAULT NULL,
  `actual_end_date` DATE DEFAULT NULL,
  `percent_complete` DECIMAL(5,2) NOT NULL DEFAULT 0.00,
  `status` ENUM('not_started','in_progress','completed') NOT NULL DEFAULT 'not_started',
  `notes` TEXT DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_company` (`company_id`),
  KEY `idx_project` (`project_id`),
  KEY `idx_status` (`status`),
  FOREIGN KEY (`company_id`) REFERENCES `companies`(`id`) ON DELETE RESTRICT,
  FOREIGN KEY (`project_id`) REFERENCES `co_projects`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
