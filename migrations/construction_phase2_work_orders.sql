-- ============================================================================
-- CONSTRUCTION MODULE — Phase 2: Subcontractor Work Orders
-- ============================================================================
-- Run after construction_module_phase1.sql
-- ============================================================================

CREATE TABLE IF NOT EXISTS `co_work_orders` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `company_id` INT(11) NOT NULL,
  `project_id` INT(11) NOT NULL,
  `project_contractor_id` INT(11) NOT NULL,
  `work_order_number` VARCHAR(50) NOT NULL,
  `description` VARCHAR(500) NOT NULL,
  `amount` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  `status` ENUM('draft','issued','in_progress','completed','cancelled') NOT NULL DEFAULT 'draft',
  `start_date` DATE DEFAULT NULL,
  `end_date` DATE DEFAULT NULL,
  `notes` TEXT DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `created_by` INT(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_company_wo_number` (`company_id`, `work_order_number`),
  KEY `idx_company` (`company_id`),
  KEY `idx_project` (`project_id`),
  KEY `idx_project_contractor` (`project_contractor_id`),
  FOREIGN KEY (`company_id`) REFERENCES `companies`(`id`) ON DELETE RESTRICT,
  FOREIGN KEY (`project_id`) REFERENCES `co_projects`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`project_contractor_id`) REFERENCES `co_project_contractors`(`id`) ON DELETE RESTRICT,
  FOREIGN KEY (`created_by`) REFERENCES `user`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
