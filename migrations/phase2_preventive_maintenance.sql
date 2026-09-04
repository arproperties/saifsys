-- Phase 2: Preventive Maintenance System
-- Enterprise-grade facility management preventive maintenance

-- ============================================================================
-- 1. Maintenance Assets/Equipment
-- ============================================================================
-- Track all equipment and assets that require preventive maintenance
CREATE TABLE IF NOT EXISTS `re_maintenance_assets` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `company_id` INT(11) NOT NULL,
  `building_id` INT(11) DEFAULT NULL COMMENT 'NULL = building-wide equipment',
  `unit_id` INT(11) DEFAULT NULL COMMENT 'NULL = not unit-specific',
  `asset_type` ENUM('ac_unit', 'elevator', 'fire_system', 'plumbing', 'electrical', 'hvac', 'generator', 'pump', 'security_system', 'other') NOT NULL,
  `asset_name` VARCHAR(255) NOT NULL,
  `asset_code` VARCHAR(100) DEFAULT NULL COMMENT 'Asset serial number or code',
  `manufacturer` VARCHAR(255) DEFAULT NULL,
  `model` VARCHAR(255) DEFAULT NULL,
  `installation_date` DATE DEFAULT NULL,
  `warranty_expiry` DATE DEFAULT NULL,
  `location` VARCHAR(500) DEFAULT NULL COMMENT 'Specific location description',
  `notes` TEXT DEFAULT NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_company` (`company_id`),
  KEY `idx_building` (`building_id`),
  KEY `idx_unit` (`unit_id`),
  KEY `idx_asset_type` (`asset_type`),
  KEY `idx_active` (`is_active`),
  FOREIGN KEY (`company_id`) REFERENCES `companies`(`id`) ON DELETE RESTRICT,
  FOREIGN KEY (`building_id`) REFERENCES `re_buildings`(`id`) ON DELETE SET NULL,
  FOREIGN KEY (`unit_id`) REFERENCES `re_units`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ============================================================================
-- 2. Preventive Maintenance Schedules
-- ============================================================================
-- Define recurring maintenance schedules
CREATE TABLE IF NOT EXISTS `re_preventive_maintenance_schedules` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `company_id` INT(11) NOT NULL,
  `schedule_name` VARCHAR(255) NOT NULL,
  `asset_id` INT(11) DEFAULT NULL COMMENT 'NULL = applies to all assets of type',
  `asset_type` ENUM('ac_unit', 'elevator', 'fire_system', 'plumbing', 'electrical', 'hvac', 'generator', 'pump', 'security_system', 'other') DEFAULT NULL,
  `building_id` INT(11) DEFAULT NULL COMMENT 'NULL = all buildings',
  `task_description` TEXT NOT NULL,
  `frequency_type` ENUM('daily', 'weekly', 'monthly', 'quarterly', 'semi_annual', 'annual', 'custom') NOT NULL,
  `frequency_value` INT(11) DEFAULT NULL COMMENT 'For custom: number of days',
  `frequency_day` INT(11) DEFAULT NULL COMMENT 'Day of week (1-7) or day of month (1-31)',
  `frequency_month` INT(11) DEFAULT NULL COMMENT 'Month (1-12) for annual',
  `estimated_duration_minutes` INT(11) DEFAULT NULL,
  `estimated_cost` DECIMAL(12,2) DEFAULT 0.00,
  `assigned_to` INT(11) DEFAULT NULL COMMENT 'Default employee/team',
  `priority` ENUM('low', 'medium', 'high', 'urgent') NOT NULL DEFAULT 'medium',
  `category` VARCHAR(100) DEFAULT NULL,
  `required_parts` TEXT DEFAULT NULL COMMENT 'List of required parts/materials',
  `instructions` TEXT DEFAULT NULL COMMENT 'Step-by-step instructions',
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `next_due_date` DATE DEFAULT NULL COMMENT 'Calculated next due date',
  `last_completed_date` DATE DEFAULT NULL,
  `created_by` INT(11) DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_company` (`company_id`),
  KEY `idx_asset` (`asset_id`),
  KEY `idx_asset_type` (`asset_type`),
  KEY `idx_building` (`building_id`),
  KEY `idx_frequency` (`frequency_type`),
  KEY `idx_next_due` (`next_due_date`),
  KEY `idx_active` (`is_active`),
  FOREIGN KEY (`company_id`) REFERENCES `companies`(`id`) ON DELETE RESTRICT,
  FOREIGN KEY (`asset_id`) REFERENCES `re_maintenance_assets`(`id`) ON DELETE SET NULL,
  FOREIGN KEY (`building_id`) REFERENCES `re_buildings`(`id`) ON DELETE SET NULL,
  FOREIGN KEY (`assigned_to`) REFERENCES `employees`(`id`) ON DELETE SET NULL,
  FOREIGN KEY (`created_by`) REFERENCES `user`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ============================================================================
-- 3. Preventive Maintenance Tasks (Generated Work Orders)
-- ============================================================================
-- Individual tasks generated from schedules
CREATE TABLE IF NOT EXISTS `re_preventive_maintenance_tasks` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `company_id` INT(11) NOT NULL,
  `schedule_id` INT(11) NOT NULL,
  `asset_id` INT(11) DEFAULT NULL,
  `building_id` INT(11) DEFAULT NULL,
  `unit_id` INT(11) DEFAULT NULL,
  `maintenance_request_id` INT(11) DEFAULT NULL COMMENT 'Linked maintenance request if created',
  `task_name` VARCHAR(255) NOT NULL,
  `task_description` TEXT NOT NULL,
  `due_date` DATE NOT NULL,
  `scheduled_date` DATE DEFAULT NULL COMMENT 'Planned execution date',
  `completed_date` DATE DEFAULT NULL,
  `status` ENUM('pending', 'scheduled', 'in_progress', 'completed', 'skipped', 'cancelled') NOT NULL DEFAULT 'pending',
  `assigned_to` INT(11) DEFAULT NULL,
  `completed_by` INT(11) DEFAULT NULL,
  `actual_duration_minutes` INT(11) DEFAULT NULL,
  `actual_cost` DECIMAL(12,2) DEFAULT 0.00,
  `notes` TEXT DEFAULT NULL,
  `completion_notes` TEXT DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_company` (`company_id`),
  KEY `idx_schedule` (`schedule_id`),
  KEY `idx_asset` (`asset_id`),
  KEY `idx_building` (`building_id`),
  KEY `idx_unit` (`unit_id`),
  KEY `idx_due_date` (`due_date`),
  KEY `idx_status` (`status`),
  KEY `idx_assigned` (`assigned_to`),
  FOREIGN KEY (`company_id`) REFERENCES `companies`(`id`) ON DELETE RESTRICT,
  FOREIGN KEY (`schedule_id`) REFERENCES `re_preventive_maintenance_schedules`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`asset_id`) REFERENCES `re_maintenance_assets`(`id`) ON DELETE SET NULL,
  FOREIGN KEY (`building_id`) REFERENCES `re_buildings`(`id`) ON DELETE SET NULL,
  KEY `idx_maintenance_request` (`maintenance_request_id`),
  FOREIGN KEY (`maintenance_request_id`) REFERENCES `re_maintenance_requests`(`id`) ON DELETE SET NULL,
  FOREIGN KEY (`assigned_to`) REFERENCES `employees`(`id`) ON DELETE SET NULL,
  FOREIGN KEY (`completed_by`) REFERENCES `employees`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ============================================================================
-- 4. Maintenance History
-- ============================================================================
-- Complete history of all preventive maintenance performed
CREATE TABLE IF NOT EXISTS `re_preventive_maintenance_history` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `company_id` INT(11) NOT NULL,
  `task_id` INT(11) NOT NULL,
  `schedule_id` INT(11) NOT NULL,
  `asset_id` INT(11) DEFAULT NULL,
  `maintenance_request_id` INT(11) DEFAULT NULL,
  `performed_date` DATE NOT NULL,
  `performed_by` INT(11) DEFAULT NULL,
  `duration_minutes` INT(11) DEFAULT NULL,
  `cost` DECIMAL(12,2) DEFAULT 0.00,
  `status_before` VARCHAR(100) DEFAULT NULL COMMENT 'Condition before maintenance',
  `status_after` VARCHAR(100) DEFAULT NULL COMMENT 'Condition after maintenance',
  `issues_found` TEXT DEFAULT NULL,
  `parts_replaced` TEXT DEFAULT NULL,
  `next_service_due` DATE DEFAULT NULL,
  `notes` TEXT DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_company` (`company_id`),
  KEY `idx_task` (`task_id`),
  KEY `idx_schedule` (`schedule_id`),
  KEY `idx_asset` (`asset_id`),
  KEY `idx_performed_date` (`performed_date`),
  FOREIGN KEY (`company_id`) REFERENCES `companies`(`id`) ON DELETE RESTRICT,
  FOREIGN KEY (`task_id`) REFERENCES `re_preventive_maintenance_tasks`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`schedule_id`) REFERENCES `re_preventive_maintenance_schedules`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`asset_id`) REFERENCES `re_maintenance_assets`(`id`) ON DELETE SET NULL,
  FOREIGN KEY (`maintenance_request_id`) REFERENCES `re_maintenance_requests`(`id`) ON DELETE SET NULL,
  FOREIGN KEY (`performed_by`) REFERENCES `employees`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ============================================================================
-- 5. Maintenance Templates
-- ============================================================================
-- Reusable templates for common maintenance tasks
CREATE TABLE IF NOT EXISTS `re_maintenance_templates` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `company_id` INT(11) NOT NULL,
  `template_name` VARCHAR(255) NOT NULL,
  `asset_type` ENUM('ac_unit', 'elevator', 'fire_system', 'plumbing', 'electrical', 'hvac', 'generator', 'pump', 'security_system', 'other') NOT NULL,
  `task_description` TEXT NOT NULL,
  `estimated_duration_minutes` INT(11) DEFAULT NULL,
  `estimated_cost` DECIMAL(12,2) DEFAULT 0.00,
  `required_parts` TEXT DEFAULT NULL,
  `instructions` TEXT DEFAULT NULL,
  `checklist_items` TEXT DEFAULT NULL COMMENT 'JSON array of checklist items',
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_by` INT(11) DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_company` (`company_id`),
  KEY `idx_asset_type` (`asset_type`),
  FOREIGN KEY (`company_id`) REFERENCES `companies`(`id`) ON DELETE RESTRICT,
  FOREIGN KEY (`created_by`) REFERENCES `user`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

