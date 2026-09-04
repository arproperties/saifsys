-- ============================================================================
-- CONSTRUCTION MODULE — Phase 2: Documents (Projects & Contractors)
-- ============================================================================
-- Run after construction_module_phase1.sql
-- ============================================================================

CREATE TABLE IF NOT EXISTS `co_documents` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `company_id` INT(11) NOT NULL,
  `project_id` INT(11) DEFAULT NULL,
  `contractor_id` INT(11) DEFAULT NULL,
  `doc_type` ENUM('contract','drawing','permit','invoice','other') NOT NULL DEFAULT 'other',
  `title` VARCHAR(255) NOT NULL,
  `file_path` VARCHAR(500) NOT NULL COMMENT 'Path or URL to file',
  `notes` TEXT DEFAULT NULL,
  `uploaded_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `uploaded_by` INT(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_company` (`company_id`),
  KEY `idx_project` (`project_id`),
  KEY `idx_contractor` (`contractor_id`),
  FOREIGN KEY (`company_id`) REFERENCES `companies`(`id`) ON DELETE RESTRICT,
  FOREIGN KEY (`project_id`) REFERENCES `co_projects`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`contractor_id`) REFERENCES `co_contractors`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`uploaded_by`) REFERENCES `user`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
