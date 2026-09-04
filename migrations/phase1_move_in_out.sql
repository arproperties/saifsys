-- Phase 1 MVP Enhancement: Move-In/Out Workflows
-- Comprehensive workflow system for tenant move-in and move-out processes

-- ============================================================================
-- 1. Move-In Checklist Items (Template)
-- ============================================================================
CREATE TABLE IF NOT EXISTS `re_move_in_checklist_templates` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `company_id` INT(11) NOT NULL,
  `item_name` VARCHAR(255) NOT NULL,
  `item_description` TEXT DEFAULT NULL,
  `is_required` TINYINT(1) NOT NULL DEFAULT 1,
  `display_order` INT(11) NOT NULL DEFAULT 0,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_company` (`company_id`),
  FOREIGN KEY (`company_id`) REFERENCES `companies`(`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ============================================================================
-- 2. Move-In Records
-- ============================================================================
CREATE TABLE IF NOT EXISTS `re_move_ins` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `company_id` INT(11) NOT NULL,
  `lease_id` INT(11) NOT NULL,
  `move_in_date` DATE NOT NULL,
  `status` ENUM('pending', 'in_progress', 'completed', 'cancelled') NOT NULL DEFAULT 'pending',
  `contract_verified` TINYINT(1) NOT NULL DEFAULT 0,
  `contract_verified_by` INT(11) DEFAULT NULL COMMENT 'user_id',
  `contract_verified_at` DATETIME DEFAULT NULL,
  `payment_confirmed` TINYINT(1) NOT NULL DEFAULT 0,
  `payment_confirmed_by` INT(11) DEFAULT NULL COMMENT 'user_id',
  `payment_confirmed_at` DATETIME DEFAULT NULL,
  `deposit_received` TINYINT(1) NOT NULL DEFAULT 0,
  `deposit_amount` DECIMAL(10,2) DEFAULT NULL,
  `keys_handed_over` TINYINT(1) NOT NULL DEFAULT 0,
  `keys_handed_over_by` INT(11) DEFAULT NULL COMMENT 'user_id',
  `keys_handed_over_at` DATETIME DEFAULT NULL,
  `keys_received_by_tenant` TINYINT(1) NOT NULL DEFAULT 0,
  `inspection_completed` TINYINT(1) NOT NULL DEFAULT 0,
  `inspection_completed_by` INT(11) DEFAULT NULL COMMENT 'user_id',
  `inspection_completed_at` DATETIME DEFAULT NULL,
  `inspection_notes` TEXT DEFAULT NULL,
  `approved_by` INT(11) DEFAULT NULL COMMENT 'user_id',
  `approved_at` DATETIME DEFAULT NULL,
  `notes` TEXT DEFAULT NULL,
  `created_by` INT(11) DEFAULT NULL COMMENT 'user_id',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_company` (`company_id`),
  KEY `idx_lease` (`lease_id`),
  KEY `idx_status` (`status`),
  KEY `idx_move_in_date` (`move_in_date`),
  FOREIGN KEY (`company_id`) REFERENCES `companies`(`id`) ON DELETE RESTRICT,
  FOREIGN KEY (`lease_id`) REFERENCES `re_leases`(`id`) ON DELETE RESTRICT,
  FOREIGN KEY (`created_by`) REFERENCES `user`(`id`) ON DELETE SET NULL,
  FOREIGN KEY (`approved_by`) REFERENCES `user`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ============================================================================
-- 3. Move-In Checklist Items (Instance)
-- ============================================================================
CREATE TABLE IF NOT EXISTS `re_move_in_checklist_items` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `company_id` INT(11) NOT NULL,
  `move_in_id` INT(11) NOT NULL,
  `checklist_template_id` INT(11) DEFAULT NULL,
  `item_name` VARCHAR(255) NOT NULL,
  `item_description` TEXT DEFAULT NULL,
  `is_required` TINYINT(1) NOT NULL DEFAULT 1,
  `is_completed` TINYINT(1) NOT NULL DEFAULT 0,
  `completed_by` INT(11) DEFAULT NULL COMMENT 'user_id',
  `completed_at` DATETIME DEFAULT NULL,
  `notes` TEXT DEFAULT NULL,
  `display_order` INT(11) NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_company` (`company_id`),
  KEY `idx_move_in` (`move_in_id`),
  KEY `idx_template` (`checklist_template_id`),
  FOREIGN KEY (`company_id`) REFERENCES `companies`(`id`) ON DELETE RESTRICT,
  FOREIGN KEY (`move_in_id`) REFERENCES `re_move_ins`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`checklist_template_id`) REFERENCES `re_move_in_checklist_templates`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ============================================================================
-- 4. Meter Readings (Initial - Move-In)
-- ============================================================================
CREATE TABLE IF NOT EXISTS `re_meter_readings` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `company_id` INT(11) NOT NULL,
  `lease_id` INT(11) NOT NULL,
  `reading_type` ENUM('move_in', 'move_out', 'periodic', 'other') NOT NULL,
  `meter_type` ENUM('electricity', 'water', 'gas', 'other') NOT NULL,
  `reading_value` DECIMAL(10,2) NOT NULL,
  `reading_date` DATE NOT NULL,
  `reading_time` TIME DEFAULT NULL,
  `meter_number` VARCHAR(100) DEFAULT NULL,
  `photo_path` VARCHAR(500) DEFAULT NULL,
  `taken_by` INT(11) DEFAULT NULL COMMENT 'user_id or tenant',
  `notes` TEXT DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_company` (`company_id`),
  KEY `idx_lease` (`lease_id`),
  KEY `idx_reading_type` (`reading_type`),
  KEY `idx_meter_type` (`meter_type`),
  KEY `idx_reading_date` (`reading_date`),
  FOREIGN KEY (`company_id`) REFERENCES `companies`(`id`) ON DELETE RESTRICT,
  FOREIGN KEY (`lease_id`) REFERENCES `re_leases`(`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ============================================================================
-- 5. Move-In Inspection Photos
-- ============================================================================
CREATE TABLE IF NOT EXISTS `re_move_in_photos` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `company_id` INT(11) NOT NULL,
  `move_in_id` INT(11) NOT NULL,
  `photo_path` VARCHAR(500) NOT NULL,
  `photo_description` VARCHAR(255) DEFAULT NULL,
  `room_area` VARCHAR(100) DEFAULT NULL COMMENT 'e.g., Living Room, Kitchen, Bedroom 1',
  `uploaded_by` INT(11) DEFAULT NULL COMMENT 'user_id',
  `uploaded_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_company` (`company_id`),
  KEY `idx_move_in` (`move_in_id`),
  FOREIGN KEY (`company_id`) REFERENCES `companies`(`id`) ON DELETE RESTRICT,
  FOREIGN KEY (`move_in_id`) REFERENCES `re_move_ins`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ============================================================================
-- 6. Move-Out Notices
-- ============================================================================
CREATE TABLE IF NOT EXISTS `re_move_out_notices` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `company_id` INT(11) NOT NULL,
  `lease_id` INT(11) NOT NULL,
  `notice_date` DATE NOT NULL,
  `intended_move_out_date` DATE NOT NULL,
  `notice_type` ENUM('tenant', 'landlord', 'mutual') NOT NULL DEFAULT 'tenant',
  `notice_reason` TEXT DEFAULT NULL,
  `notice_delivered_by` VARCHAR(255) DEFAULT NULL COMMENT 'How notice was delivered',
  `status` ENUM('pending', 'acknowledged', 'cancelled') NOT NULL DEFAULT 'pending',
  `acknowledged_by` INT(11) DEFAULT NULL COMMENT 'user_id',
  `acknowledged_at` DATETIME DEFAULT NULL,
  `notes` TEXT DEFAULT NULL,
  `created_by` INT(11) DEFAULT NULL COMMENT 'user_id',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_company` (`company_id`),
  KEY `idx_lease` (`lease_id`),
  KEY `idx_status` (`status`),
  KEY `idx_intended_date` (`intended_move_out_date`),
  FOREIGN KEY (`company_id`) REFERENCES `companies`(`id`) ON DELETE RESTRICT,
  FOREIGN KEY (`lease_id`) REFERENCES `re_leases`(`id`) ON DELETE RESTRICT,
  FOREIGN KEY (`created_by`) REFERENCES `user`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ============================================================================
-- 7. Move-Out Records
-- ============================================================================
CREATE TABLE IF NOT EXISTS `re_move_outs` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `company_id` INT(11) NOT NULL,
  `lease_id` INT(11) NOT NULL,
  `move_out_notice_id` INT(11) DEFAULT NULL,
  `actual_move_out_date` DATE NOT NULL,
  `status` ENUM('pending', 'inspection_scheduled', 'inspection_completed', 'deposit_processing', 'completed', 'cancelled') NOT NULL DEFAULT 'pending',
  `inspection_scheduled_date` DATE DEFAULT NULL,
  `inspection_completed` TINYINT(1) NOT NULL DEFAULT 0,
  `inspection_completed_by` INT(11) DEFAULT NULL COMMENT 'user_id',
  `inspection_completed_at` DATETIME DEFAULT NULL,
  `keys_returned` TINYINT(1) NOT NULL DEFAULT 0,
  `keys_returned_by` INT(11) DEFAULT NULL COMMENT 'user_id',
  `keys_returned_at` DATETIME DEFAULT NULL,
  `final_inspection_notes` TEXT DEFAULT NULL,
  `damage_assessment_total` DECIMAL(10,2) DEFAULT 0.00,
  `deposit_deduction_amount` DECIMAL(10,2) DEFAULT 0.00,
  `deposit_refund_amount` DECIMAL(10,2) DEFAULT NULL,
  `deposit_status` ENUM('pending', 'processing', 'refunded', 'forfeited', 'partial') DEFAULT NULL,
  `deposit_refunded_at` DATETIME DEFAULT NULL,
  `deposit_refund_method` VARCHAR(100) DEFAULT NULL,
  `deposit_refund_reference` VARCHAR(255) DEFAULT NULL,
  `approved_by` INT(11) DEFAULT NULL COMMENT 'user_id',
  `approved_at` DATETIME DEFAULT NULL,
  `notes` TEXT DEFAULT NULL,
  `created_by` INT(11) DEFAULT NULL COMMENT 'user_id',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_company` (`company_id`),
  KEY `idx_lease` (`lease_id`),
  KEY `idx_notice` (`move_out_notice_id`),
  KEY `idx_status` (`status`),
  KEY `idx_move_out_date` (`actual_move_out_date`),
  FOREIGN KEY (`company_id`) REFERENCES `companies`(`id`) ON DELETE RESTRICT,
  FOREIGN KEY (`lease_id`) REFERENCES `re_leases`(`id`) ON DELETE RESTRICT,
  FOREIGN KEY (`move_out_notice_id`) REFERENCES `re_move_out_notices`(`id`) ON DELETE SET NULL,
  FOREIGN KEY (`created_by`) REFERENCES `user`(`id`) ON DELETE SET NULL,
  FOREIGN KEY (`approved_by`) REFERENCES `user`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ============================================================================
-- 8. Move-Out Damage Assessment
-- ============================================================================
CREATE TABLE IF NOT EXISTS `re_move_out_damages` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `company_id` INT(11) NOT NULL,
  `move_out_id` INT(11) NOT NULL,
  `damage_description` TEXT NOT NULL,
  `room_area` VARCHAR(100) DEFAULT NULL,
  `damage_type` ENUM('minor', 'moderate', 'major', 'severe') NOT NULL DEFAULT 'minor',
  `repair_cost` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `photo_path` VARCHAR(500) DEFAULT NULL,
  `assessed_by` INT(11) DEFAULT NULL COMMENT 'user_id',
  `assessed_at` DATETIME DEFAULT NULL,
  `notes` TEXT DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_company` (`company_id`),
  KEY `idx_move_out` (`move_out_id`),
  FOREIGN KEY (`company_id`) REFERENCES `companies`(`id`) ON DELETE RESTRICT,
  FOREIGN KEY (`move_out_id`) REFERENCES `re_move_outs`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ============================================================================
-- 9. Move-Out Inspection Photos
-- ============================================================================
CREATE TABLE IF NOT EXISTS `re_move_out_photos` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `company_id` INT(11) NOT NULL,
  `move_out_id` INT(11) NOT NULL,
  `photo_path` VARCHAR(500) NOT NULL,
  `photo_description` VARCHAR(255) DEFAULT NULL,
  `room_area` VARCHAR(100) DEFAULT NULL,
  `is_damage` TINYINT(1) NOT NULL DEFAULT 0,
  `damage_id` INT(11) DEFAULT NULL,
  `uploaded_by` INT(11) DEFAULT NULL COMMENT 'user_id',
  `uploaded_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_company` (`company_id`),
  KEY `idx_move_out` (`move_out_id`),
  KEY `idx_damage` (`damage_id`),
  FOREIGN KEY (`company_id`) REFERENCES `companies`(`id`) ON DELETE RESTRICT,
  FOREIGN KEY (`move_out_id`) REFERENCES `re_move_outs`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`damage_id`) REFERENCES `re_move_out_damages`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ============================================================================
-- 10. Default Move-In Checklist Templates
-- ============================================================================
-- These will be inserted per company via application logic
-- Example items:
-- - Contract signed and verified
-- - Deposit received
-- - First month rent received
-- - Keys handed over
-- - Initial inspection completed
-- - Meter readings taken
-- - Utilities connected
-- - Welcome package provided

-- ============================================================================
-- 11. Add move_in_date and move_out_date tracking to leases (if not exists)
-- ============================================================================
ALTER TABLE `re_leases`
  ADD COLUMN IF NOT EXISTS `move_in_completed` TINYINT(1) NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS `move_out_completed` TINYINT(1) NOT NULL DEFAULT 0;

-- ============================================================================
-- 12. Indexes for performance
-- ============================================================================
ALTER TABLE `re_move_ins` ADD INDEX IF NOT EXISTS `idx_lease_status` (`lease_id`, `status`);
ALTER TABLE `re_move_outs` ADD INDEX IF NOT EXISTS `idx_lease_status` (`lease_id`, `status`);

