-- ============================================================================
-- CONSTRUCTION MODULE — Phase 2: Retention Releases
-- ============================================================================
-- Run after construction_module_phase1.sql
-- ============================================================================

CREATE TABLE IF NOT EXISTS `co_retention_releases` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `company_id` INT(11) NOT NULL,
  `project_contractor_id` INT(11) NOT NULL,
  `release_date` DATE NOT NULL,
  `amount` DECIMAL(15,2) NOT NULL,
  `journal_id` INT(11) DEFAULT NULL,
  `notes` TEXT DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `created_by` INT(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_company` (`company_id`),
  KEY `idx_project_contractor` (`project_contractor_id`),
  KEY `idx_journal` (`journal_id`),
  FOREIGN KEY (`company_id`) REFERENCES `companies`(`id`) ON DELETE RESTRICT,
  FOREIGN KEY (`project_contractor_id`) REFERENCES `co_project_contractors`(`id`) ON DELETE RESTRICT,
  FOREIGN KEY (`journal_id`) REFERENCES `re_journal_headers`(`id`) ON DELETE SET NULL,
  FOREIGN KEY (`created_by`) REFERENCES `user`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
