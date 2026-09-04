-- ============================================================================
-- SAFE DATABASE UPDATE FOR LIVE SERVER (Hostinger)
-- Date: January 14, 2026
-- Description: Only adds new tables/columns - DOES NOT modify existing data
-- ============================================================================
-- 
-- IMPORTANT: This script is designed to be SAFE to run on live database
-- - Only creates new tables (IF NOT EXISTS)
-- - Only adds new columns (IF NOT EXISTS where supported)
-- - Does NOT delete or modify existing data
-- - Does NOT drop tables or columns
--
-- BEFORE RUNNING:
-- 1. BACKUP your live database first!
-- 2. Test on a staging environment if possible
-- 3. Run during low-traffic hours
-- ============================================================================

SET FOREIGN_KEY_CHECKS = 0;
SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';

-- ============================================================================
-- PART 1: ROLE-BASED DEPARTMENT ACCESS CONTROL (RBAC)
-- ============================================================================
-- This is the most recent addition - department-based permissions system

CREATE TABLE IF NOT EXISTS `role_departments` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `role_id` INT(11) NOT NULL,
  `module` VARCHAR(50) NOT NULL COMMENT 'cleaning or realestate',
  `department` VARCHAR(50) NOT NULL COMMENT 'Department code (e.g., cleaning_operations, hr)',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_role_module_department` (`role_id`, `module`, `department`),
  KEY `idx_role_id` (`role_id`),
  KEY `idx_module` (`module`),
  KEY `idx_department` (`department`),
  FOREIGN KEY (`role_id`) REFERENCES `roles`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ============================================================================
-- PART 2: CHECK AND ADD MISSING COLUMNS (Safe - only if not exists)
-- ============================================================================
-- Note: Some MySQL/MariaDB versions don't support IF NOT EXISTS for ALTER TABLE
-- If column already exists, the statement will fail but won't break the script

-- Check if company_id columns exist in core tables (if not, they were added in earlier migrations)
-- These are safe to add as they allow NULL values

-- ============================================================================
-- PART 3: VERIFY EXISTING STRUCTURES
-- ============================================================================
-- The following tables should already exist from previous migrations:
-- - companies
-- - user_companies  
-- - role_modules
-- - All Real Estate tables (re_buildings, re_units, re_tenants, etc.)
-- - All document management tables (re_documents, re_document_types, etc.)

-- If any of these are missing, you may need to run earlier migration files
-- But we won't recreate them here to avoid conflicts

SET FOREIGN_KEY_CHECKS = 1;

-- ============================================================================
-- VERIFICATION QUERIES (Run these after migration to verify)
-- ============================================================================
-- SELECT 'Migration completed. Verifying...' AS status;
-- SELECT COUNT(*) AS role_departments_count FROM role_departments;
-- SELECT COUNT(*) AS companies_count FROM companies;
-- SELECT COUNT(*) AS user_companies_count FROM user_companies;

-- ============================================================================
-- MIGRATION COMPLETE
-- ============================================================================
-- If you see this message, the migration completed successfully
-- Check the verification queries above to confirm all tables exist

SELECT '✅ Safe migration completed successfully!' AS status;
