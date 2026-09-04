-- Create employee_activity_log table for storing employee activity history
-- Created: 2025-12-03

CREATE TABLE IF NOT EXISTS `employee_activity_log` (
  `id` INT PRIMARY KEY AUTO_INCREMENT,
  `employee_id` INT NOT NULL,
  `action_type` VARCHAR(100) NOT NULL COMMENT 'Type of action: profile_update, document_added, document_removed, status_change, etc.',
  `action_description` TEXT NOT NULL COMMENT 'Human-readable description of the action',
  `changed_field` VARCHAR(100) NULL COMMENT 'Field name that was changed (if applicable)',
  `old_value` TEXT NULL COMMENT 'Previous value (if applicable)',
  `new_value` TEXT NULL COMMENT 'New value (if applicable)',
  `performed_by` INT NULL COMMENT 'User ID who performed the action',
  `ip_address` VARCHAR(45) NULL COMMENT 'IP address of the user who performed the action',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_employee_id` (`employee_id`),
  INDEX `idx_action_type` (`action_type`),
  INDEX `idx_created_at` (`created_at`),
  INDEX `idx_performed_by` (`performed_by`),
  FOREIGN KEY (`employee_id`) REFERENCES `employees`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`performed_by`) REFERENCES `user`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

