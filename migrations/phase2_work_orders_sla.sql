-- Phase 2: Work Orders with SLA
-- This migration adds SLA (Service Level Agreement) tracking to maintenance requests

-- ============================================================================
-- 1. SLA Rules Configuration
-- ============================================================================
-- Defines SLA targets based on priority and category
CREATE TABLE IF NOT EXISTS `re_sla_rules` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `company_id` INT(11) NOT NULL,
  `priority` ENUM('low', 'medium', 'high', 'urgent') NOT NULL,
  `category` VARCHAR(100) DEFAULT NULL COMMENT 'NULL means applies to all categories',
  `response_time_minutes` INT(11) NOT NULL COMMENT 'Target time to respond (in minutes)',
  `resolution_time_hours` INT(11) NOT NULL COMMENT 'Target time to resolve (in hours)',
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_company` (`company_id`),
  KEY `idx_priority` (`priority`),
  KEY `idx_category` (`category`),
  UNIQUE KEY `uq_company_priority_category` (`company_id`, `priority`, `category`),
  FOREIGN KEY (`company_id`) REFERENCES `companies`(`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ============================================================================
-- 2. SLA Tracking
-- ============================================================================
-- Tracks actual vs target times for each maintenance request
CREATE TABLE IF NOT EXISTS `re_sla_tracking` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `company_id` INT(11) NOT NULL,
  `maintenance_request_id` INT(11) NOT NULL,
  `sla_rule_id` INT(11) DEFAULT NULL COMMENT 'SLA rule that was applied',
  `target_response_time` DATETIME DEFAULT NULL COMMENT 'Target time to respond',
  `actual_response_time` DATETIME DEFAULT NULL COMMENT 'Actual time when request was assigned/acknowledged',
  `target_resolution_time` DATETIME DEFAULT NULL COMMENT 'Target time to resolve',
  `actual_resolution_time` DATETIME DEFAULT NULL COMMENT 'Actual time when request was completed',
  `response_time_minutes` INT(11) DEFAULT NULL COMMENT 'Actual response time in minutes',
  `resolution_time_hours` DECIMAL(10,2) DEFAULT NULL COMMENT 'Actual resolution time in hours',
  `response_sla_met` TINYINT(1) DEFAULT NULL COMMENT '1 = met, 0 = violated, NULL = not applicable',
  `resolution_sla_met` TINYINT(1) DEFAULT NULL COMMENT '1 = met, 0 = violated, NULL = not applicable',
  `sla_violation_notified` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Whether violation email was sent',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_company` (`company_id`),
  KEY `idx_maintenance_request` (`maintenance_request_id`),
  KEY `idx_sla_rule` (`sla_rule_id`),
  KEY `idx_response_sla` (`response_sla_met`),
  KEY `idx_resolution_sla` (`resolution_sla_met`),
  KEY `idx_violation_notified` (`sla_violation_notified`),
  FOREIGN KEY (`company_id`) REFERENCES `companies`(`id`) ON DELETE RESTRICT,
  FOREIGN KEY (`maintenance_request_id`) REFERENCES `re_maintenance_requests`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`sla_rule_id`) REFERENCES `re_sla_rules`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ============================================================================
-- 3. Add SLA-related columns to maintenance_requests
-- ============================================================================
-- Add columns to track response and resolution times
ALTER TABLE `re_maintenance_requests`
  ADD COLUMN IF NOT EXISTS `responded_at` DATETIME DEFAULT NULL COMMENT 'When request was first assigned/acknowledged',
  ADD COLUMN IF NOT EXISTS `response_time_minutes` INT(11) DEFAULT NULL COMMENT 'Time taken to respond in minutes',
  ADD COLUMN IF NOT EXISTS `resolution_time_hours` DECIMAL(10,2) DEFAULT NULL COMMENT 'Time taken to resolve in hours',
  ADD COLUMN IF NOT EXISTS `sla_response_met` TINYINT(1) DEFAULT NULL COMMENT 'Whether response SLA was met',
  ADD COLUMN IF NOT EXISTS `sla_resolution_met` TINYINT(1) DEFAULT NULL COMMENT 'Whether resolution SLA was met';

-- Add indexes for SLA tracking
ALTER TABLE `re_maintenance_requests`
  ADD KEY IF NOT EXISTS `idx_responded_at` (`responded_at`),
  ADD KEY IF NOT EXISTS `idx_sla_response` (`sla_response_met`),
  ADD KEY IF NOT EXISTS `idx_sla_resolution` (`sla_resolution_met`);

-- ============================================================================
-- 4. Default SLA Rules (Insert default rules for each priority)
-- ============================================================================
-- These are example defaults - companies can customize them
-- Note: These will be inserted per company, so we'll create a procedure or insert on company creation

-- Example default rules (will be inserted via application logic):
-- Urgent: 15 min response, 4 hours resolution
-- High: 30 min response, 8 hours resolution
-- Medium: 2 hours response, 24 hours resolution
-- Low: 4 hours response, 48 hours resolution

