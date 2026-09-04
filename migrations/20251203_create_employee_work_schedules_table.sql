-- Create employee_work_schedules table for storing employee work schedules
-- Created: 2025-12-03

CREATE TABLE IF NOT EXISTS `employee_work_schedules` (
  `id` INT PRIMARY KEY AUTO_INCREMENT,
  `employee_id` INT NOT NULL,
  `schedule_type` ENUM('regular', 'shift', 'flexible', 'part_time') DEFAULT 'regular' COMMENT 'Type of schedule',
  `monday_hours` VARCHAR(50) NULL COMMENT 'Hours worked on Monday (e.g., 9:00 AM - 5:00 PM)',
  `tuesday_hours` VARCHAR(50) NULL COMMENT 'Hours worked on Tuesday (e.g., 9:00 AM - 5:00 PM)',
  `wednesday_hours` VARCHAR(50) NULL COMMENT 'Hours worked on Wednesday (e.g., 9:00 AM - 5:00 PM)',
  `thursday_hours` VARCHAR(50) NULL COMMENT 'Hours worked on Thursday (e.g., 9:00 AM - 5:00 PM)',
  `friday_hours` VARCHAR(50) NULL COMMENT 'Hours worked on Friday (e.g., 9:00 AM - 5:00 PM)',
  `saturday_hours` VARCHAR(50) NULL COMMENT 'Hours worked on Saturday (e.g., Off or 9:00 AM - 1:00 PM)',
  `sunday_hours` VARCHAR(50) NULL COMMENT 'Hours worked on Sunday (e.g., Off or 9:00 AM - 1:00 PM)',
  `effective_from` DATE NOT NULL COMMENT 'Date when this schedule becomes effective',
  `effective_to` DATE NULL COMMENT 'Date when this schedule expires (NULL for ongoing)',
  `notes` TEXT NULL COMMENT 'Additional notes about the schedule',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_employee_id` (`employee_id`),
  INDEX `idx_effective_from` (`effective_from`),
  INDEX `idx_effective_to` (`effective_to`),
  INDEX `idx_schedule_type` (`schedule_type`),
  FOREIGN KEY (`employee_id`) REFERENCES `employees`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

