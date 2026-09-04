-- Create employment_history table for storing employee employment history events
-- Created: 2025-12-03

CREATE TABLE IF NOT EXISTS `employment_history` (
  `id` INT PRIMARY KEY AUTO_INCREMENT,
  `employee_id` INT NOT NULL,
  `event_type` ENUM('hire', 'promotion', 'transfer', 'salary_change', 'position_change', 'termination', 'other') DEFAULT 'other',
  `event_date` DATE NOT NULL,
  `title` VARCHAR(255) NULL COMMENT 'Position title or event title',
  `department_id` INT NULL,
  `location_id` INT NULL,
  `salary_before` DECIMAL(10,2) NULL,
  `salary_after` DECIMAL(10,2) NULL,
  `notes` TEXT NULL,
  `created_by` INT NOT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_employee_id` (`employee_id`),
  INDEX `idx_event_date` (`event_date`),
  INDEX `idx_event_type` (`event_type`),
  INDEX `idx_department_id` (`department_id`),
  INDEX `idx_location_id` (`location_id`),
  INDEX `idx_created_at` (`created_at`),
  FOREIGN KEY (`employee_id`) REFERENCES `employees`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`department_id`) REFERENCES `departments`(`id`) ON DELETE SET NULL,
  FOREIGN KEY (`location_id`) REFERENCES `locations`(`id`) ON DELETE SET NULL,
  FOREIGN KEY (`created_by`) REFERENCES `user`(`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

