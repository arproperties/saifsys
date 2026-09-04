-- ============================================================================
-- CONSTRUCTION MODULE — Phase 3: Submittals & RFIs
-- ============================================================================
-- Run after construction_phase2_*.sql
-- ============================================================================

-- co_submittals (drawings, specs, samples submitted for approval)
CREATE TABLE IF NOT EXISTS `co_submittals` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `company_id` INT(11) NOT NULL,
  `project_id` INT(11) NOT NULL,
  `contractor_id` INT(11) DEFAULT NULL COMMENT 'Contractor who submitted',
  `submittal_number` VARCHAR(50) NOT NULL COMMENT 'e.g. SUB-001',
  `title` VARCHAR(255) NOT NULL,
  `description` TEXT DEFAULT NULL COMMENT 'Spec/drawing reference',
  `submittal_type` ENUM('drawing','specification','sample','other') NOT NULL DEFAULT 'other',
  `status` ENUM('draft','submitted','under_review','approved','rejected','revised') NOT NULL DEFAULT 'draft',
  `due_date` DATE DEFAULT NULL,
  `submitted_date` DATE DEFAULT NULL,
  `response_date` DATE DEFAULT NULL,
  `response_notes` TEXT DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `created_by` INT(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_company_submittal_number` (`company_id`, `submittal_number`),
  KEY `idx_company` (`company_id`),
  KEY `idx_project` (`project_id`),
  KEY `idx_contractor` (`contractor_id`),
  KEY `idx_status` (`status`),
  FOREIGN KEY (`company_id`) REFERENCES `companies`(`id`) ON DELETE RESTRICT,
  FOREIGN KEY (`project_id`) REFERENCES `co_projects`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`contractor_id`) REFERENCES `co_contractors`(`id`) ON DELETE SET NULL,
  FOREIGN KEY (`created_by`) REFERENCES `user`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- co_rfis (Request for Information)
CREATE TABLE IF NOT EXISTS `co_rfis` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `company_id` INT(11) NOT NULL,
  `project_id` INT(11) NOT NULL,
  `project_contractor_id` INT(11) DEFAULT NULL COMMENT 'Contractor who raised RFI',
  `rfi_number` VARCHAR(50) NOT NULL COMMENT 'e.g. RFI-001',
  `subject` VARCHAR(255) NOT NULL,
  `description` TEXT DEFAULT NULL COMMENT 'Question / body',
  `status` ENUM('open','answered','closed') NOT NULL DEFAULT 'open',
  `issued_date` DATE NOT NULL,
  `due_date` DATE DEFAULT NULL,
  `answered_date` DATE DEFAULT NULL,
  `response` TEXT DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `created_by` INT(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_company_rfi_number` (`company_id`, `rfi_number`),
  KEY `idx_company` (`company_id`),
  KEY `idx_project` (`project_id`),
  KEY `idx_project_contractor` (`project_contractor_id`),
  KEY `idx_status` (`status`),
  FOREIGN KEY (`company_id`) REFERENCES `companies`(`id`) ON DELETE RESTRICT,
  FOREIGN KEY (`project_id`) REFERENCES `co_projects`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`project_contractor_id`) REFERENCES `co_project_contractors`(`id`) ON DELETE SET NULL,
  FOREIGN KEY (`created_by`) REFERENCES `user`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
