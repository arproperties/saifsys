-- ============================================================================
-- Migration: Add Role-Based Department Access Control
-- Date: January 12, 2026
-- Description: Creates role_departments table for department-based RBAC
-- ============================================================================

SET FOREIGN_KEY_CHECKS = 0;

-- ============================================================================
-- CREATE ROLE_DEPARTMENTS TABLE
-- ============================================================================

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
-- DEPARTMENT CODES REFERENCE
-- ============================================================================
-- Cleaning Module:
--   - cleaning_operations: All under /operation.php
--   - cleaning_accounts: All under /account.php
--   - hr: Shared HR department
--
-- Real Estate Module:
--   - realestate_core: Core Management (Buildings, Units, Tenants, Leases)
--   - realestate_financial: Financial (Payments, Billing, Collections)
--   - realestate_maintenance: Maintenance (Maintenance, Preventive, Vendors, SLA)
--   - realestate_operations: Operations (Move-Ins, Move-Outs, Tasks)
--   - realestate_compliance: Compliance & Reports
--   - hr: Shared HR department

SET FOREIGN_KEY_CHECKS = 1;

-- ============================================================================
-- MIGRATION COMPLETE
-- ============================================================================
