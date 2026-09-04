-- Create table for maintenance request photos
-- This table stores photos uploaded as evidence for maintenance work completion

CREATE TABLE IF NOT EXISTS `re_maintenance_photos` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `company_id` INT(11) NOT NULL,
  `maintenance_request_id` INT(11) NOT NULL,
  `photo_type` ENUM('before', 'during', 'after', 'completion') NOT NULL DEFAULT 'completion',
  `file_name` VARCHAR(255) NOT NULL,
  `file_path` VARCHAR(500) NOT NULL,
  `file_size` INT(11) NOT NULL,
  `mime_type` VARCHAR(100) DEFAULT NULL,
  `description` TEXT DEFAULT NULL,
  `uploaded_by` INT(11) DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_company` (`company_id`),
  KEY `idx_maintenance_request` (`maintenance_request_id`),
  KEY `idx_photo_type` (`photo_type`),
  FOREIGN KEY (`company_id`) REFERENCES `companies`(`id`) ON DELETE RESTRICT,
  FOREIGN KEY (`maintenance_request_id`) REFERENCES `re_maintenance_requests`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`uploaded_by`) REFERENCES `user`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

