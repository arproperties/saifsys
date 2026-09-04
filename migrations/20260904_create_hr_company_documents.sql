-- Create hr_company_documents + hr_company_document_versions tables
-- Company-level statutory documents (trade licence, MOA, Ejari, establishment card,
-- power of attorney) with renewal history.
-- Created: 2026-09-04
-- Safe to run multiple times.

SET FOREIGN_KEY_CHECKS = 0;

CREATE TABLE IF NOT EXISTS `hr_company_documents` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `company_id` INT NOT NULL,
  `doc_type` VARCHAR(40) NOT NULL COMMENT 'trade_license|moa|ejari|establishment_card|power_of_attorney|other',
  `title` VARCHAR(200) NOT NULL DEFAULT '' COMMENT 'Free label, required for other and for multiple POAs',
  `doc_number` VARCHAR(100) DEFAULT NULL COMMENT 'Reference / licence number',
  `issuing_authority` VARCHAR(160) DEFAULT NULL COMMENT 'e.g. DED, MOHRE, Dubai Land Department',
  `issue_date` DATE DEFAULT NULL,
  `expiry_date` DATE DEFAULT NULL COMMENT 'NULL for documents that do not expire (MOA, most POAs)',
  `file_path` VARCHAR(255) DEFAULT NULL COMMENT 'Root-relative uploads/company_docs/<company_id>/<file>',
  `file_name` VARCHAR(255) DEFAULT NULL COMMENT 'Original upload filename',
  `file_size` INT DEFAULT NULL,
  `mime_type` VARCHAR(100) DEFAULT NULL COMMENT 'Resolved from the extension whitelist, never from the browser',
  `status` VARCHAR(20) NOT NULL DEFAULT 'active' COMMENT 'active|cancelled',
  `notes` TEXT DEFAULT NULL,
  `created_by` INT DEFAULT NULL,
  `updated_by` INT DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `idx_company_id` (`company_id`),
  INDEX `idx_doc_type` (`doc_type`),
  INDEX `idx_expiry_date` (`expiry_date`),
  INDEX `idx_company_type` (`company_id`, `doc_type`),
  CONSTRAINT `fk_hr_company_documents_company`
    FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `hr_company_document_versions` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `document_id` INT NOT NULL,
  `version_no` INT NOT NULL DEFAULT 1,
  `doc_number` VARCHAR(100) DEFAULT NULL,
  `issuing_authority` VARCHAR(160) DEFAULT NULL,
  `issue_date` DATE DEFAULT NULL,
  `expiry_date` DATE DEFAULT NULL,
  `file_path` VARCHAR(255) DEFAULT NULL,
  `file_name` VARCHAR(255) DEFAULT NULL,
  `file_size` INT DEFAULT NULL,
  `mime_type` VARCHAR(100) DEFAULT NULL,
  `notes` TEXT DEFAULT NULL,
  `archived_by` INT DEFAULT NULL,
  `archived_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_doc_version` (`document_id`, `version_no`),
  INDEX `idx_document_id` (`document_id`),
  CONSTRAINT `fk_hr_company_document_versions_document`
    FOREIGN KEY (`document_id`) REFERENCES `hr_company_documents` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
