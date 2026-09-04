-- ----------------------------------------------------------------------------
-- Controlled Cash Payment Workflow (extra services, cleaning, pest control)
-- Run after: tenant_extra_services_enhance.sql, tenant_cleaning_pest_control.sql
-- ----------------------------------------------------------------------------

-- 1) Cashier sessions: one per receptionist per day; open/close with expected vs actual
CREATE TABLE IF NOT EXISTS `re_cashier_sessions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `company_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL COMMENT 'Receptionist who opened the session',
  `opened_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `closed_at` datetime DEFAULT NULL,
  `expected_amount` decimal(14,2) DEFAULT NULL COMMENT 'Sum of receipts in session at close',
  `actual_amount` decimal(14,2) DEFAULT NULL COMMENT 'Physical cash count entered on close',
  `variance_amount` decimal(14,2) DEFAULT NULL COMMENT 'actual - expected',
  `status` enum('open','closed') NOT NULL DEFAULT 'open',
  `notes` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_company_user` (`company_id`, `user_id`),
  KEY `idx_status_opened` (`status`, `opened_at`),
  CONSTRAINT `fk_rcs_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_rcs_user` FOREIGN KEY (`user_id`) REFERENCES `user` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- 2) Cash payment requests: single table for extra_service, cleaning, pest_control
CREATE TABLE IF NOT EXISTS `re_cash_payment_requests` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `company_id` int(11) NOT NULL,
  `request_number` varchar(32) NOT NULL COMMENT 'Display ID e.g. CPR-2026-0001',
  `related_type` varchar(32) NOT NULL COMMENT 'extra_service, cleaning, pest_control',
  `related_id` int(11) NOT NULL COMMENT 'FK to tenant_extra_service_requests.id or tenant_cleaning_requests.id or tenant_pest_control_requests.id',
  `amount_aed` decimal(12,2) NOT NULL,
  `currency` varchar(3) NOT NULL DEFAULT 'AED',
  `status` enum('pending_cash_payment','cash_received_pending_verification','paid_verified','expired','rejected') NOT NULL DEFAULT 'pending_cash_payment',
  `expires_at` datetime NOT NULL,
  `received_at` datetime DEFAULT NULL,
  `received_by` int(11) DEFAULT NULL,
  `cashier_session_id` int(11) DEFAULT NULL,
  `receipt_number` varchar(32) DEFAULT NULL COMMENT 'Unique per company/session',
  `verified_at` datetime DEFAULT NULL,
  `verified_by` int(11) DEFAULT NULL,
  `accounting_entry_id` int(11) DEFAULT NULL COMMENT 'Optional: link to re_journal_headers or similar',
  `notes` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_request_number_company` (`company_id`, `request_number`),
  KEY `idx_company_status` (`company_id`, `status`),
  KEY `idx_related` (`related_type`, `related_id`),
  KEY `idx_expires` (`expires_at`),
  KEY `idx_session` (`cashier_session_id`),
  CONSTRAINT `fk_rcpr_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_rcpr_received_by` FOREIGN KEY (`received_by`) REFERENCES `user` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_rcpr_session` FOREIGN KEY (`cashier_session_id`) REFERENCES `re_cashier_sessions` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_rcpr_verified_by` FOREIGN KEY (`verified_by`) REFERENCES `user` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- 3) Variance log: one row per session close when variance is recorded
CREATE TABLE IF NOT EXISTS `re_cash_payment_variance_log` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `cashier_session_id` int(11) NOT NULL,
  `expected_amount` decimal(14,2) NOT NULL,
  `actual_amount` decimal(14,2) NOT NULL,
  `variance_amount` decimal(14,2) NOT NULL,
  `recorded_by` int(11) NOT NULL,
  `recorded_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `notes` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_session` (`cashier_session_id`),
  CONSTRAINT `fk_rcpvl_session` FOREIGN KEY (`cashier_session_id`) REFERENCES `re_cashier_sessions` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_rcpvl_user` FOREIGN KEY (`recorded_by`) REFERENCES `user` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- 4) Audit log for cash payment request changes (no hard deletes)
CREATE TABLE IF NOT EXISTS `re_cash_payment_audit_log` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `cash_payment_request_id` int(11) NOT NULL,
  `action` varchar(32) NOT NULL COMMENT 'create, update, status_change',
  `field_name` varchar(64) DEFAULT NULL,
  `old_value` text DEFAULT NULL,
  `new_value` text DEFAULT NULL,
  `changed_by` int(11) DEFAULT NULL,
  `changed_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_request` (`cash_payment_request_id`),
  KEY `idx_changed_at` (`changed_at`),
  CONSTRAINT `fk_rcpal_request` FOREIGN KEY (`cash_payment_request_id`) REFERENCES `re_cash_payment_requests` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_rcpal_user` FOREIGN KEY (`changed_by`) REFERENCES `user` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- 5) Receipt sequence per company (for unique receipt numbers per session)
CREATE TABLE IF NOT EXISTS `re_cash_receipt_sequences` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `company_id` int(11) NOT NULL,
  `cashier_session_id` int(11) NOT NULL,
  `last_number` int(11) NOT NULL DEFAULT 0,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_company_session` (`company_id`, `cashier_session_id`),
  CONSTRAINT `fk_rcrs_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_rcrs_session` FOREIGN KEY (`cashier_session_id`) REFERENCES `re_cashier_sessions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- 6) Link service requests to cash payment request (nullable)
-- If you get "Duplicate column name" or "Duplicate key", that part is already applied; skip that statement.
ALTER TABLE `tenant_extra_service_requests` ADD COLUMN `cash_payment_request_id` int(11) DEFAULT NULL COMMENT 'Set when tenant chooses Cash at Office';
ALTER TABLE `tenant_cleaning_requests` ADD COLUMN `cash_payment_request_id` int(11) DEFAULT NULL COMMENT 'Set when tenant chooses Cash at Office';
ALTER TABLE `tenant_pest_control_requests` ADD COLUMN `cash_payment_request_id` int(11) DEFAULT NULL COMMENT 'Set when tenant chooses Cash at Office';

ALTER TABLE `tenant_extra_service_requests` ADD KEY `idx_cash_request` (`cash_payment_request_id`);
ALTER TABLE `tenant_cleaning_requests` ADD KEY `idx_cash_request` (`cash_payment_request_id`);
ALTER TABLE `tenant_pest_control_requests` ADD KEY `idx_cash_request` (`cash_payment_request_id`);
