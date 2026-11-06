-- ============================================================================
-- Title Deed module (Building Details page) — standalone, AMC-Contracts-style
-- ----------------------------------------------------------------------------
-- Replaces the earlier re_title_deed_details table. Title Deeds now carry
-- their own uploaded file (not routed through re_documents) and support more
-- than one record per building (e.g. a superseding deed), listed/viewed/
-- edited/deleted the same way AMC Contracts are.
--
-- Affection Plan / Site Plan documents are NOT part of this table — they
-- keep going through the generic re_documents system (re_document_types
-- already has the AFFECTION_PLAN type from an earlier migration).
-- ============================================================================

DROP TABLE IF EXISTS `re_title_deed_details`;
DROP TABLE IF EXISTS `re_title_deeds`;

CREATE TABLE `re_title_deeds` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `company_id` INT(11) NOT NULL,
  `building_id` INT(11) NOT NULL,
  `title_deed_no` VARCHAR(100) DEFAULT NULL COMMENT 'e.g. 0123456/2022',
  `issue_date` DATE DEFAULT NULL,
  `property_type` VARCHAR(150) DEFAULT NULL COMMENT 'e.g. Flat, Villa, Office, Land',
  `building_name` VARCHAR(255) DEFAULT NULL,
  `building_no` VARCHAR(100) DEFAULT NULL,
  `property_no` VARCHAR(100) DEFAULT NULL,
  `file_name` VARCHAR(255) DEFAULT NULL,
  `file_path` VARCHAR(500) DEFAULT NULL,
  `file_size` INT(11) DEFAULT NULL,
  `mime_type` VARCHAR(100) DEFAULT NULL,
  `notes` TEXT DEFAULT NULL,
  `created_by` INT(11) DEFAULT NULL,
  `updated_by` INT(11) DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_building` (`building_id`),
  KEY `idx_company` (`company_id`),
  FOREIGN KEY (`company_id`) REFERENCES `companies`(`id`) ON DELETE RESTRICT,
  FOREIGN KEY (`building_id`) REFERENCES `re_buildings`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`created_by`) REFERENCES `user`(`id`) ON DELETE SET NULL,
  FOREIGN KEY (`updated_by`) REFERENCES `user`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
