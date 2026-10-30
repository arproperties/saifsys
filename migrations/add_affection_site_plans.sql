-- ============================================================================
-- Affection Plan / Site Plan module (Building Details page)
-- ----------------------------------------------------------------------------
-- Standalone table, own uploaded file, dedicated Add/Edit/View pages — same
-- pattern as re_title_deeds. Replaces routing these through the generic
-- re_documents system on the Building Details page.
-- ============================================================================

CREATE TABLE IF NOT EXISTS `re_affection_site_plans` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `company_id` INT(11) NOT NULL,
  `building_id` INT(11) NOT NULL,
  `plan_type` ENUM('affection_plan','site_plan') NOT NULL,
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
