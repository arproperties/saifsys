-- ============================================================================
-- COMPREHENSIVE DATABASE UPDATE FOR LIVE SERVER (Hostinger)
-- Generated: 2026-01-14 10:40:57
-- Description: Adds missing tables AND updates existing table structures
-- ============================================================================
-- 
-- IMPORTANT: This script is SAFE to run on live database
-- - Creates NEW tables (IF NOT EXISTS)
-- - Adds missing columns (with IF NOT EXISTS where supported)
-- - Adds missing indexes (with IF NOT EXISTS)
-- - Does NOT modify or delete existing data
--
-- BEFORE RUNNING:
-- 1. BACKUP your live database first!
-- 2. Test on a staging environment if possible
-- 3. Run during low-traffic hours
-- ============================================================================

SET FOREIGN_KEY_CHECKS = 0;
SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';

-- ============================================================================
-- PART 1: MISSING TABLES
-- ============================================================================

-- Table: companies
CREATE TABLE IF NOT EXISTS `companies` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(200) NOT NULL,
  `code` varchar(50) DEFAULT NULL,
  `business_type` enum('cleaning','realestate','supermarket','restaurant') NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_company_code` (`code`),
  KEY `idx_business_type` (`business_type`),
  KEY `idx_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- Table: re_billing_items
CREATE TABLE IF NOT EXISTS `re_billing_items` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `company_id` int(11) NOT NULL,
  `lease_id` int(11) NOT NULL,
  `item_type` enum('service_charge','parking_fee','penalty','other') NOT NULL,
  `item_name` varchar(255) NOT NULL,
  `item_description` text DEFAULT NULL,
  `amount` decimal(12,2) NOT NULL,
  `quantity` decimal(10,2) DEFAULT 1.00,
  `unit_price` decimal(10,2) DEFAULT NULL,
  `tax_rate` decimal(5,2) DEFAULT 0.00,
  `tax_amount` decimal(10,2) DEFAULT 0.00,
  `total_amount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `billing_period_start` date DEFAULT NULL,
  `billing_period_end` date DEFAULT NULL,
  `billing_date` date NOT NULL COMMENT 'Date this charge applies to',
  `due_date` date NOT NULL,
  `service_charge_id` int(11) DEFAULT NULL,
  `penalty_rule_id` int(11) DEFAULT NULL,
  `installment_id` int(11) DEFAULT NULL COMMENT 'If linked to rent installment',
  `status` enum('pending','paid','overdue','waived') NOT NULL DEFAULT 'pending',
  `payment_id` int(11) DEFAULT NULL COMMENT 'Link to re_payments if paid',
  `notes` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `created_by` int(11) DEFAULT NULL,
  `is_paid` tinyint(1) NOT NULL DEFAULT 0,
  `paid_amount` decimal(10,2) DEFAULT 0.00,
  `paid_date` date DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_company` (`company_id`),
  KEY `idx_lease` (`lease_id`),
  KEY `idx_type` (`item_type`),
  KEY `idx_status` (`status`),
  KEY `idx_due_date` (`due_date`),
  KEY `idx_payment` (`payment_id`),
  KEY `idx_created_by` (`created_by`),
  KEY `idx_lease_due_date` (`lease_id`,`due_date`,`is_paid`),
  KEY `idx_due_paid` (`due_date`,`is_paid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- Table: re_bounced_cheque_alerts
CREATE TABLE IF NOT EXISTS `re_bounced_cheque_alerts` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `company_id` int(11) NOT NULL,
  `cheque_id` int(11) NOT NULL,
  `lease_id` int(11) NOT NULL,
  `cheque_number` varchar(100) NOT NULL,
  `cheque_amount` decimal(10,2) NOT NULL,
  `bounced_date` date NOT NULL,
  `bounced_reason` text DEFAULT NULL,
  `alert_sent_date` datetime NOT NULL,
  `alert_sent_to` text DEFAULT NULL COMMENT 'Comma-separated emails',
  `status` enum('pending','sent','resolved','cancelled') NOT NULL DEFAULT 'pending',
  `resolved_date` date DEFAULT NULL,
  `resolved_by` int(11) DEFAULT NULL COMMENT 'user_id',
  `resolution_notes` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_company` (`company_id`),
  KEY `idx_cheque` (`cheque_id`),
  KEY `idx_lease` (`lease_id`),
  KEY `idx_status` (`status`),
  KEY `idx_bounced_date` (`bounced_date`),
  KEY `resolved_by` (`resolved_by`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- Table: re_buildings
CREATE TABLE IF NOT EXISTS `re_buildings` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `company_id` int(11) NOT NULL,
  `name` varchar(200) NOT NULL,
  `address` text DEFAULT NULL,
  `total_floors` int(11) DEFAULT NULL,
  `total_units` int(11) DEFAULT NULL,
  `has_parking` tinyint(1) NOT NULL DEFAULT 0,
  `parking_spaces` int(11) DEFAULT NULL,
  `has_gym` tinyint(1) NOT NULL DEFAULT 0,
  `has_pool` tinyint(1) NOT NULL DEFAULT 0,
  `facilities_notes` text DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_company` (`company_id`),
  KEY `idx_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- Table: re_building_common_areas
CREATE TABLE IF NOT EXISTS `re_building_common_areas` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `building_id` int(11) NOT NULL,
  `area_name` varchar(200) NOT NULL,
  `area_type` enum('lobby','reception','corridor','elevator','staircase','rooftop','garden','playground','parking','storage','other') NOT NULL DEFAULT 'other',
  `floor_number` int(11) DEFAULT NULL COMMENT 'NULL means ground/common level',
  `area_sqm` decimal(10,2) DEFAULT NULL,
  `capacity` int(11) DEFAULT NULL COMMENT 'Max capacity if applicable',
  `description` text DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_building` (`building_id`),
  KEY `idx_area_type` (`area_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- Table: re_building_floor_plans
CREATE TABLE IF NOT EXISTS `re_building_floor_plans` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `building_id` int(11) NOT NULL,
  `floor_number` int(11) DEFAULT NULL COMMENT 'NULL means building-wide plan',
  `plan_name` varchar(200) NOT NULL,
  `file_path` varchar(500) NOT NULL,
  `file_size` int(11) DEFAULT NULL COMMENT 'Size in bytes',
  `file_type` varchar(50) DEFAULT NULL COMMENT 'MIME type',
  `description` text DEFAULT NULL,
  `is_primary` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'Primary plan for this floor',
  `uploaded_by` int(11) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_building` (`building_id`),
  KEY `idx_floor` (`floor_number`),
  KEY `idx_primary` (`building_id`,`floor_number`,`is_primary`),
  KEY `idx_uploaded_by` (`uploaded_by`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- Table: re_collections_alerts_config
CREATE TABLE IF NOT EXISTS `re_collections_alerts_config` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `company_id` int(11) NOT NULL,
  `alert_type` enum('overdue_rent','payment_received','bounced_cheque','upcoming_due','invoice_overdue') NOT NULL,
  `is_enabled` tinyint(1) NOT NULL DEFAULT 1,
  `days_before_alert` int(11) DEFAULT 0 COMMENT 'For upcoming_due alerts',
  `days_overdue_threshold` int(11) DEFAULT 0 COMMENT 'Days overdue before alert (0 = immediate)',
  `alert_frequency` enum('once','daily','weekly') NOT NULL DEFAULT 'daily',
  `recipient_emails` text DEFAULT NULL COMMENT 'Comma-separated email addresses',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_company_alert_type` (`company_id`,`alert_type`),
  KEY `idx_company` (`company_id`),
  KEY `idx_enabled` (`is_enabled`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- Table: re_compliance_status
CREATE TABLE IF NOT EXISTS `re_compliance_status` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `company_id` int(11) NOT NULL,
  `unit_id` int(11) NOT NULL,
  `lease_id` int(11) DEFAULT NULL,
  `compliance_type` enum('ejari_registered','municipality_approved','insurance_valid','tenant_id_valid','tenant_visa_valid','noc_obtained','utility_connected') NOT NULL,
  `is_compliant` tinyint(1) NOT NULL DEFAULT 0,
  `document_id` int(11) DEFAULT NULL COMMENT 'Link to re_documents',
  `expires_at` date DEFAULT NULL,
  `last_verified_at` datetime DEFAULT NULL,
  `verified_by` int(11) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_unit_compliance` (`unit_id`,`compliance_type`),
  KEY `idx_company` (`company_id`),
  KEY `idx_lease` (`lease_id`),
  KEY `idx_compliant` (`is_compliant`),
  KEY `idx_expires` (`expires_at`),
  KEY `idx_document` (`document_id`),
  KEY `idx_verified_by` (`verified_by`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- Table: re_contract_templates
CREATE TABLE IF NOT EXISTS `re_contract_templates` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `company_id` int(11) NOT NULL,
  `template_name` varchar(200) NOT NULL,
  `template_type` enum('standard','commercial','short_term','long_term','renewal') NOT NULL DEFAULT 'standard',
  `description` text DEFAULT NULL,
  `template_content` longtext NOT NULL COMMENT 'HTML/Template content with placeholders',
  `template_html` longtext DEFAULT NULL COMMENT 'Full HTML template with CSS for PDF generation',
  `template_format` enum('text','html') NOT NULL DEFAULT 'text',
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `is_default` tinyint(1) NOT NULL DEFAULT 0,
  `created_by` int(11) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_company` (`company_id`),
  KEY `idx_template_type` (`template_type`),
  KEY `idx_active` (`is_active`),
  KEY `idx_created_by` (`created_by`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- Table: re_documents
CREATE TABLE IF NOT EXISTS `re_documents` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `company_id` int(11) NOT NULL,
  `document_type_id` int(11) DEFAULT NULL,
  `document_name` varchar(255) NOT NULL DEFAULT '',
  `document_number` varchar(100) DEFAULT NULL COMMENT 'Document reference number (e.g., Ejari number)',
  `document_type` enum('lease_agreement','ejari','tenant_id','tenant_passport','tenant_visa','noc','municipality_approval','insurance','building_permit','utility_connection','other') NOT NULL,
  `related_type` enum('lease','tenant','unit','building','maintenance') NOT NULL,
  `related_id` int(11) NOT NULL COMMENT 'ID of related lease/tenant/unit/building/maintenance',
  `file_name` varchar(255) NOT NULL COMMENT 'Original filename',
  `file_path` varchar(500) NOT NULL COMMENT 'Relative path from project root',
  `file_size` int(11) NOT NULL COMMENT 'File size in bytes',
  `mime_type` varchar(100) DEFAULT NULL,
  `issue_date` date DEFAULT NULL,
  `expiry_date` date DEFAULT NULL,
  `is_expired` tinyint(1) NOT NULL DEFAULT 0,
  `expiry_alert_sent` tinyint(1) NOT NULL DEFAULT 0,
  `status` enum('active','expired','renewed','cancelled') NOT NULL DEFAULT 'active',
  `current_version` int(11) NOT NULL DEFAULT 1,
  `notes` text DEFAULT NULL,
  `tags` varchar(500) DEFAULT NULL COMMENT 'Comma-separated tag names for quick search',
  `is_favorite` tinyint(1) NOT NULL DEFAULT 0,
  `download_count` int(11) NOT NULL DEFAULT 0,
  `view_count` int(11) NOT NULL DEFAULT 0,
  `uploaded_by` int(11) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_company` (`company_id`),
  KEY `idx_type` (`document_type`),
  KEY `idx_related` (`related_type`,`related_id`),
  KEY `idx_expires` (`expiry_date`,`is_expired`),
  KEY `idx_uploaded_by` (`uploaded_by`),
  KEY `fk_document_type` (`document_type_id`),
  KEY `idx_expiry_alert` (`expiry_date`,`expiry_alert_sent`,`is_expired`),
  KEY `idx_status_created` (`status`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- Table: re_document_access_log
CREATE TABLE IF NOT EXISTS `re_document_access_log` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `company_id` int(11) NOT NULL,
  `document_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `action` enum('viewed','downloaded','uploaded','updated','deleted','shared') NOT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_company` (`company_id`),
  KEY `idx_document` (`document_id`),
  KEY `idx_user` (`user_id`),
  KEY `idx_action` (`action`),
  KEY `idx_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- Table: re_document_expiry_alerts
CREATE TABLE IF NOT EXISTS `re_document_expiry_alerts` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `company_id` int(11) NOT NULL,
  `document_id` int(11) NOT NULL,
  `alert_sent_date` date NOT NULL,
  `alert_sent_to` text DEFAULT NULL COMMENT 'Comma-separated emails',
  `days_before_expiry` int(11) NOT NULL COMMENT 'Days before expiry when alert was sent',
  `expiry_date` date NOT NULL,
  `status` enum('pending','sent','resolved','cancelled') NOT NULL DEFAULT 'pending',
  `resolved_date` date DEFAULT NULL,
  `resolved_by` int(11) DEFAULT NULL COMMENT 'user_id',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_company` (`company_id`),
  KEY `idx_document` (`document_id`),
  KEY `idx_status` (`status`),
  KEY `idx_expiry_date` (`expiry_date`),
  KEY `resolved_by` (`resolved_by`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- Table: re_document_favorites
CREATE TABLE IF NOT EXISTS `re_document_favorites` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `company_id` int(11) NOT NULL,
  `document_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_user_document` (`user_id`,`document_id`),
  KEY `idx_company` (`company_id`),
  KEY `idx_document` (`document_id`),
  KEY `idx_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- Table: re_document_sharing
CREATE TABLE IF NOT EXISTS `re_document_sharing` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `company_id` int(11) NOT NULL,
  `document_id` int(11) NOT NULL,
  `shared_with_user_id` int(11) DEFAULT NULL COMMENT 'Shared with specific user',
  `shared_with_role` varchar(100) DEFAULT NULL COMMENT 'Shared with role',
  `permission` enum('view','download','edit','delete') NOT NULL DEFAULT 'view',
  `expires_at` datetime DEFAULT NULL COMMENT 'Sharing expiration',
  `shared_by` int(11) DEFAULT NULL COMMENT 'user_id who shared',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_company` (`company_id`),
  KEY `idx_document` (`document_id`),
  KEY `idx_user` (`shared_with_user_id`),
  KEY `idx_role` (`shared_with_role`),
  KEY `idx_expires` (`expires_at`),
  KEY `shared_by` (`shared_by`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- Table: re_document_tags
CREATE TABLE IF NOT EXISTS `re_document_tags` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `company_id` int(11) NOT NULL,
  `tag_name` varchar(100) NOT NULL,
  `tag_color` varchar(7) DEFAULT '#007bff' COMMENT 'Hex color code',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_company_tag` (`company_id`,`tag_name`),
  KEY `idx_company` (`company_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- Table: re_document_tag_relations
CREATE TABLE IF NOT EXISTS `re_document_tag_relations` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `document_id` int(11) NOT NULL,
  `tag_id` int(11) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_document_tag` (`document_id`,`tag_id`),
  KEY `idx_document` (`document_id`),
  KEY `idx_tag` (`tag_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- Table: re_document_types
CREATE TABLE IF NOT EXISTS `re_document_types` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `company_id` int(11) NOT NULL,
  `document_type_name` varchar(255) NOT NULL,
  `document_type_code` varchar(50) NOT NULL,
  `description` text DEFAULT NULL,
  `is_required` tinyint(1) NOT NULL DEFAULT 0,
  `has_expiry` tinyint(1) NOT NULL DEFAULT 0,
  `default_expiry_days` int(11) DEFAULT NULL COMMENT 'Default validity period in days',
  `alert_days_before_expiry` int(11) DEFAULT 30 COMMENT 'Days before expiry to send alert',
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `display_order` int(11) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_company_code` (`company_id`,`document_type_code`),
  KEY `idx_company` (`company_id`),
  KEY `idx_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- Table: re_document_versions
CREATE TABLE IF NOT EXISTS `re_document_versions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `company_id` int(11) NOT NULL,
  `document_id` int(11) NOT NULL,
  `version_number` int(11) NOT NULL DEFAULT 1,
  `file_name` varchar(255) NOT NULL,
  `file_path` varchar(500) NOT NULL,
  `file_size` int(11) DEFAULT NULL,
  `mime_type` varchar(100) DEFAULT NULL,
  `change_summary` text DEFAULT NULL COMMENT 'What changed in this version',
  `uploaded_by` int(11) DEFAULT NULL COMMENT 'user_id',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_company` (`company_id`),
  KEY `idx_document` (`document_id`),
  KEY `idx_version` (`document_id`,`version_number`),
  KEY `uploaded_by` (`uploaded_by`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- Table: re_ejari_tracking
CREATE TABLE IF NOT EXISTS `re_ejari_tracking` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `company_id` int(11) NOT NULL,
  `lease_id` int(11) NOT NULL,
  `ejari_number` varchar(100) NOT NULL,
  `registration_date` date DEFAULT NULL,
  `expiry_date` date DEFAULT NULL,
  `registration_status` enum('pending','registered','expired','renewed','cancelled') NOT NULL DEFAULT 'pending',
  `registration_fee` decimal(10,2) DEFAULT NULL,
  `renewal_reminder_sent` tinyint(1) NOT NULL DEFAULT 0,
  `document_id` int(11) DEFAULT NULL COMMENT 'Link to re_documents table',
  `notes` text DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL COMMENT 'user_id',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_ejari_number` (`company_id`,`ejari_number`),
  KEY `idx_company` (`company_id`),
  KEY `idx_lease` (`lease_id`),
  KEY `idx_registration_status` (`registration_status`),
  KEY `idx_expiry_date` (`expiry_date`),
  KEY `idx_document` (`document_id`),
  KEY `created_by` (`created_by`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- Table: re_email_logs
CREATE TABLE IF NOT EXISTS `re_email_logs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `company_id` int(11) NOT NULL,
  `notification_type` varchar(100) NOT NULL,
  `recipient_email` varchar(255) NOT NULL,
  `subject` varchar(500) DEFAULT NULL,
  `status` enum('pending','sent','failed','skipped') NOT NULL DEFAULT 'pending',
  `error_message` text DEFAULT NULL,
  `related_id` int(11) DEFAULT NULL COMMENT 'e.g., maintenance_request_id',
  `related_type` varchar(50) DEFAULT NULL COMMENT 'e.g., maintenance_request',
  `sent_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_company` (`company_id`),
  KEY `idx_notification_type` (`notification_type`),
  KEY `idx_status` (`status`),
  KEY `idx_created_at` (`created_at`),
  KEY `idx_related` (`related_type`,`related_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- Table: re_email_notifications
CREATE TABLE IF NOT EXISTS `re_email_notifications` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `company_id` int(11) NOT NULL,
  `notification_type` varchar(100) NOT NULL COMMENT 'e.g., maintenance_request, lease_expiry, payment_overdue',
  `recipient_email` varchar(255) NOT NULL COMMENT 'Email address to receive notifications',
  `is_enabled` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_company_type_email` (`company_id`,`notification_type`,`recipient_email`),
  KEY `idx_company` (`company_id`),
  KEY `idx_type` (`notification_type`),
  KEY `idx_enabled` (`is_enabled`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- Table: re_floors
CREATE TABLE IF NOT EXISTS `re_floors` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `building_id` int(11) NOT NULL,
  `floor_number` int(11) NOT NULL,
  `name` varchar(100) DEFAULT NULL,
  `total_units` int(11) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_building_floor` (`building_id`,`floor_number`),
  KEY `idx_building` (`building_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- Table: re_invoices
CREATE TABLE IF NOT EXISTS `re_invoices` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `company_id` int(11) NOT NULL,
  `lease_id` int(11) NOT NULL,
  `invoice_number` varchar(100) NOT NULL,
  `invoice_date` date NOT NULL,
  `due_date` date NOT NULL,
  `subtotal` decimal(10,2) NOT NULL DEFAULT 0.00,
  `tax_rate` decimal(5,2) DEFAULT 0.00,
  `tax_amount` decimal(10,2) DEFAULT 0.00,
  `discount_amount` decimal(10,2) DEFAULT 0.00,
  `total_amount` decimal(10,2) NOT NULL,
  `paid_amount` decimal(10,2) DEFAULT 0.00,
  `outstanding_amount` decimal(10,2) NOT NULL,
  `status` enum('draft','sent','paid','partial','overdue','cancelled') NOT NULL DEFAULT 'draft',
  `notes` text DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL COMMENT 'user_id',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_invoice_number` (`company_id`,`invoice_number`),
  KEY `idx_company` (`company_id`),
  KEY `idx_lease` (`lease_id`),
  KEY `idx_invoice_date` (`invoice_date`),
  KEY `idx_due_date` (`due_date`),
  KEY `idx_status` (`status`),
  KEY `created_by` (`created_by`),
  KEY `idx_lease_status` (`lease_id`,`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- Table: re_invoice_items
CREATE TABLE IF NOT EXISTS `re_invoice_items` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `company_id` int(11) NOT NULL,
  `invoice_id` int(11) NOT NULL,
  `billing_item_id` int(11) DEFAULT NULL,
  `item_name` varchar(255) NOT NULL,
  `item_description` text DEFAULT NULL,
  `quantity` decimal(10,2) DEFAULT 1.00,
  `unit_price` decimal(10,2) NOT NULL,
  `tax_rate` decimal(5,2) DEFAULT 0.00,
  `tax_amount` decimal(10,2) DEFAULT 0.00,
  `line_total` decimal(10,2) NOT NULL,
  `display_order` int(11) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_company` (`company_id`),
  KEY `idx_invoice` (`invoice_id`),
  KEY `idx_billing_item` (`billing_item_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- Table: re_invoice_sequences
CREATE TABLE IF NOT EXISTS `re_invoice_sequences` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `company_id` int(11) NOT NULL,
  `year` int(11) NOT NULL,
  `sequence_number` int(11) NOT NULL DEFAULT 0,
  `prefix` varchar(50) DEFAULT 'INV',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_company_year` (`company_id`,`year`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- Table: re_leases
CREATE TABLE IF NOT EXISTS `re_leases` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `company_id` int(11) NOT NULL,
  `unit_id` int(11) NOT NULL,
  `tenant_id` int(11) NOT NULL,
  `lease_number` varchar(50) DEFAULT NULL,
  `start_date` date NOT NULL,
  `end_date` date NOT NULL,
  `monthly_rent` decimal(12,2) NOT NULL,
  `annual_rent` decimal(12,2) DEFAULT NULL COMMENT 'Annual rent amount in AED',
  `monthly_service_charge` decimal(12,2) DEFAULT 0.00,
  `monthly_parking_fee` decimal(12,2) DEFAULT 0.00,
  `has_additional_parking` tinyint(1) DEFAULT 0 COMMENT 'Tenant has additional parking',
  `additional_parking_fee` decimal(12,2) DEFAULT 0.00 COMMENT 'Monthly fee for additional parking',
  `additional_parking_start_date` date DEFAULT NULL COMMENT 'Start date for additional parking',
  `additional_parking_end_date` date DEFAULT NULL COMMENT 'End date for additional parking',
  `has_additional_store` tinyint(1) DEFAULT 0 COMMENT 'Tenant has additional store',
  `additional_store_fee` decimal(12,2) DEFAULT 0.00 COMMENT 'Monthly fee for additional store',
  `additional_store_start_date` date DEFAULT NULL COMMENT 'Start date for additional store',
  `additional_store_end_date` date DEFAULT NULL COMMENT 'End date for additional store',
  `security_deposit` decimal(12,2) NOT NULL DEFAULT 0.00,
  `payment_day` int(2) NOT NULL DEFAULT 1 COMMENT 'Day of month rent is due',
  `number_of_installments` int(3) DEFAULT 12 COMMENT 'Number of payment installments per year',
  `grace_period_days` int(3) DEFAULT 0 COMMENT 'Days after due date before penalty applies',
  `penalty_rate_percent` decimal(5,2) DEFAULT 0.00 COMMENT 'Percentage penalty per month on overdue amount',
  `payment_method` enum('cash','bank_transfer','cheque','auto_debit') NOT NULL DEFAULT 'bank_transfer',
  `status` enum('draft','active','expired','terminated','renewed') NOT NULL DEFAULT 'draft',
  `move_in_date` date DEFAULT NULL,
  `move_out_date` date DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `renewal_terms` text DEFAULT NULL COMMENT 'Renewal conditions and terms',
  `template_id` int(11) DEFAULT NULL,
  `ejari_registration_number` varchar(100) DEFAULT NULL COMMENT 'Ejari Registration Number',
  `ejari_issue_date` date DEFAULT NULL COMMENT 'Ejari Issue Date',
  `ejari_property_code` varchar(100) DEFAULT NULL COMMENT 'Ejari Property Code',
  `ejari_document_path` varchar(500) DEFAULT NULL COMMENT 'Path to uploaded Ejari PDF document',
  `landlord_signature_path` varchar(500) DEFAULT NULL COMMENT 'Path to landlord signature image',
  `tenant_signature_path` varchar(500) DEFAULT NULL COMMENT 'Path to tenant signature image',
  `company_stamp_path` varchar(500) DEFAULT NULL COMMENT 'Path to company stamp/seal image',
  `generated_contract_path` varchar(500) DEFAULT NULL COMMENT 'Path to generated contract PDF',
  `contract_generated_at` datetime DEFAULT NULL COMMENT 'Timestamp when contract PDF was generated',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `created_by` int(11) DEFAULT NULL,
  `move_in_completed` tinyint(1) NOT NULL DEFAULT 0,
  `move_out_completed` tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_lease_number` (`lease_number`),
  KEY `idx_company` (`company_id`),
  KEY `idx_unit` (`unit_id`),
  KEY `idx_tenant` (`tenant_id`),
  KEY `idx_status` (`status`),
  KEY `idx_active_lease` (`unit_id`,`status`),
  KEY `idx_created_by` (`created_by`),
  KEY `idx_template` (`template_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- Table: re_lease_cheques
CREATE TABLE IF NOT EXISTS `re_lease_cheques` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `lease_id` int(11) NOT NULL,
  `installment_id` int(11) DEFAULT NULL COMMENT 'Link to re_lease_installments if applicable',
  `cheque_number` varchar(100) DEFAULT NULL,
  `cheque_date` date DEFAULT NULL,
  `cheque_amount` decimal(12,2) NOT NULL,
  `cheque_holder_name` varchar(200) DEFAULT NULL,
  `payment_method` enum('cheque','cash','bank_transfer','card') NOT NULL DEFAULT 'cheque',
  `cheque_photo_path` varchar(500) DEFAULT NULL COMMENT 'Path to uploaded cheque photo',
  `status` enum('pending','deposited','cleared','bounced','cancelled') NOT NULL DEFAULT 'pending',
  `deposited_date` date DEFAULT NULL,
  `cleared_date` date DEFAULT NULL,
  `bounced_date` date DEFAULT NULL,
  `bounced_reason` text DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_lease` (`lease_id`),
  KEY `idx_installment` (`installment_id`),
  KEY `idx_cheque_date` (`cheque_date`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- Table: re_lease_expiry_reminders
CREATE TABLE IF NOT EXISTS `re_lease_expiry_reminders` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `lease_id` int(11) NOT NULL,
  `reminder_date` date NOT NULL COMMENT 'Date when reminder should be sent',
  `reminder_type` enum('30_days','15_days','7_days','1_day','expired') NOT NULL DEFAULT '30_days',
  `sent_to_management` tinyint(1) NOT NULL DEFAULT 0,
  `sent_to_tenant` tinyint(1) NOT NULL DEFAULT 0,
  `management_sent_at` datetime DEFAULT NULL,
  `tenant_sent_at` datetime DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_lease` (`lease_id`),
  KEY `idx_reminder_date` (`reminder_date`),
  KEY `idx_sent` (`sent_to_management`,`sent_to_tenant`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- Table: re_lease_installments
CREATE TABLE IF NOT EXISTS `re_lease_installments` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `lease_id` int(11) NOT NULL,
  `installment_date` date NOT NULL,
  `amount` decimal(12,2) NOT NULL,
  `status` enum('pending','paid','overdue','waived') NOT NULL DEFAULT 'pending',
  `paid_at` datetime DEFAULT NULL,
  `payment_id` int(11) DEFAULT NULL,
  `notes` varchar(500) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `invoice_id` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_lease` (`lease_id`),
  KEY `idx_status` (`status`),
  KEY `idx_date` (`installment_date`),
  KEY `idx_payment` (`payment_id`),
  KEY `idx_invoice` (`invoice_id`),
  KEY `idx_status_date` (`status`,`installment_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- Table: re_lease_renewal_workflows
CREATE TABLE IF NOT EXISTS `re_lease_renewal_workflows` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `lease_id` int(11) NOT NULL,
  `workflow_step` enum('initiated','terms_reviewed','tenant_notified','tenant_response','terms_negotiated','renewal_approved','new_lease_created','completed','cancelled') NOT NULL DEFAULT 'initiated',
  `initiated_date` date NOT NULL,
  `target_renewal_date` date DEFAULT NULL,
  `proposed_rent` decimal(12,2) DEFAULT NULL,
  `proposed_start_date` date DEFAULT NULL,
  `proposed_end_date` date DEFAULT NULL,
  `tenant_response` text DEFAULT NULL,
  `tenant_response_date` date DEFAULT NULL,
  `negotiation_notes` text DEFAULT NULL,
  `new_lease_id` int(11) DEFAULT NULL COMMENT 'ID of the new lease created from renewal',
  `assigned_to` int(11) DEFAULT NULL COMMENT 'User assigned to handle renewal',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_lease` (`lease_id`),
  KEY `idx_workflow_step` (`workflow_step`),
  KEY `idx_assigned_to` (`assigned_to`),
  KEY `idx_new_lease` (`new_lease_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- Table: re_maintenance_assets
CREATE TABLE IF NOT EXISTS `re_maintenance_assets` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `company_id` int(11) NOT NULL,
  `building_id` int(11) DEFAULT NULL COMMENT 'NULL = building-wide equipment',
  `unit_id` int(11) DEFAULT NULL COMMENT 'NULL = not unit-specific',
  `asset_type` enum('ac_unit','elevator','fire_system','plumbing','electrical','hvac','generator','pump','security_system','other') NOT NULL,
  `asset_name` varchar(255) NOT NULL,
  `asset_code` varchar(100) DEFAULT NULL COMMENT 'Asset serial number or code',
  `manufacturer` varchar(255) DEFAULT NULL,
  `model` varchar(255) DEFAULT NULL,
  `installation_date` date DEFAULT NULL,
  `warranty_expiry` date DEFAULT NULL,
  `location` varchar(500) DEFAULT NULL COMMENT 'Specific location description',
  `notes` text DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_company` (`company_id`),
  KEY `idx_building` (`building_id`),
  KEY `idx_unit` (`unit_id`),
  KEY `idx_asset_type` (`asset_type`),
  KEY `idx_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- Table: re_maintenance_photos
CREATE TABLE IF NOT EXISTS `re_maintenance_photos` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `company_id` int(11) NOT NULL,
  `maintenance_request_id` int(11) NOT NULL,
  `photo_type` enum('before','during','after','completion') NOT NULL DEFAULT 'completion',
  `file_name` varchar(255) NOT NULL,
  `file_path` varchar(500) NOT NULL,
  `file_size` int(11) NOT NULL,
  `mime_type` varchar(100) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `uploaded_by` int(11) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_company` (`company_id`),
  KEY `idx_maintenance_request` (`maintenance_request_id`),
  KEY `idx_photo_type` (`photo_type`),
  KEY `uploaded_by` (`uploaded_by`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- Table: re_maintenance_requests
CREATE TABLE IF NOT EXISTS `re_maintenance_requests` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `company_id` int(11) NOT NULL,
  `unit_id` int(11) NOT NULL,
  `tenant_id` int(11) DEFAULT NULL,
  `lease_id` int(11) DEFAULT NULL,
  `request_date` date NOT NULL,
  `priority` enum('low','medium','high','urgent') NOT NULL DEFAULT 'medium',
  `category` varchar(100) DEFAULT NULL,
  `description` text NOT NULL,
  `status` enum('pending','in_progress','completed','cancelled') NOT NULL DEFAULT 'pending',
  `assigned_to` int(11) DEFAULT NULL COMMENT 'employee_id',
  `vendor_id` int(11) DEFAULT NULL,
  `completed_at` datetime DEFAULT NULL,
  `cost` decimal(12,2) NOT NULL DEFAULT 0.00,
  `notes` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `created_by` int(11) DEFAULT NULL,
  `responded_at` datetime DEFAULT NULL COMMENT 'When request was first assigned/acknowledged',
  `response_time_minutes` int(11) DEFAULT NULL COMMENT 'Time taken to respond in minutes',
  `resolution_time_hours` decimal(10,2) DEFAULT NULL COMMENT 'Time taken to resolve in hours',
  `sla_response_met` tinyint(1) DEFAULT NULL COMMENT 'Whether response SLA was met',
  `sla_resolution_met` tinyint(1) DEFAULT NULL COMMENT 'Whether resolution SLA was met',
  PRIMARY KEY (`id`),
  KEY `idx_company` (`company_id`),
  KEY `idx_unit` (`unit_id`),
  KEY `idx_tenant` (`tenant_id`),
  KEY `idx_lease` (`lease_id`),
  KEY `idx_status` (`status`),
  KEY `idx_assigned_to` (`assigned_to`),
  KEY `idx_created_by` (`created_by`),
  KEY `idx_responded_at` (`responded_at`),
  KEY `idx_sla_response` (`sla_response_met`),
  KEY `idx_sla_resolution` (`sla_resolution_met`),
  KEY `idx_vendor` (`vendor_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- Table: re_maintenance_templates
CREATE TABLE IF NOT EXISTS `re_maintenance_templates` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `company_id` int(11) NOT NULL,
  `template_name` varchar(255) NOT NULL,
  `asset_type` enum('ac_unit','elevator','fire_system','plumbing','electrical','hvac','generator','pump','security_system','other') NOT NULL,
  `task_description` text NOT NULL,
  `estimated_duration_minutes` int(11) DEFAULT NULL,
  `estimated_cost` decimal(12,2) DEFAULT 0.00,
  `required_parts` text DEFAULT NULL,
  `instructions` text DEFAULT NULL,
  `checklist_items` text DEFAULT NULL COMMENT 'JSON array of checklist items',
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_by` int(11) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_company` (`company_id`),
  KEY `idx_asset_type` (`asset_type`),
  KEY `created_by` (`created_by`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- Table: re_meter_readings
CREATE TABLE IF NOT EXISTS `re_meter_readings` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `company_id` int(11) NOT NULL,
  `lease_id` int(11) NOT NULL,
  `reading_type` enum('move_in','move_out','periodic','other') NOT NULL,
  `meter_type` enum('electricity','water','gas','other') NOT NULL,
  `reading_value` decimal(10,2) NOT NULL,
  `reading_date` date NOT NULL,
  `reading_time` time DEFAULT NULL,
  `meter_number` varchar(100) DEFAULT NULL,
  `photo_path` varchar(500) DEFAULT NULL,
  `taken_by` int(11) DEFAULT NULL COMMENT 'user_id or tenant',
  `notes` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_company` (`company_id`),
  KEY `idx_lease` (`lease_id`),
  KEY `idx_reading_type` (`reading_type`),
  KEY `idx_meter_type` (`meter_type`),
  KEY `idx_reading_date` (`reading_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- Table: re_missing_documents
CREATE TABLE IF NOT EXISTS `re_missing_documents` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `company_id` int(11) NOT NULL,
  `related_type` enum('lease','tenant','unit','building') NOT NULL,
  `related_id` int(11) NOT NULL,
  `document_type_id` int(11) NOT NULL,
  `is_required` tinyint(1) NOT NULL DEFAULT 1,
  `status` enum('missing','pending_upload','uploaded','resolved') NOT NULL DEFAULT 'missing',
  `alert_sent_date` date DEFAULT NULL,
  `resolved_date` date DEFAULT NULL,
  `resolved_by` int(11) DEFAULT NULL COMMENT 'user_id',
  `notes` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_company` (`company_id`),
  KEY `idx_related` (`related_type`,`related_id`),
  KEY `idx_document_type` (`document_type_id`),
  KEY `idx_status` (`status`),
  KEY `resolved_by` (`resolved_by`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- Table: re_move_ins
CREATE TABLE IF NOT EXISTS `re_move_ins` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `company_id` int(11) NOT NULL,
  `lease_id` int(11) NOT NULL,
  `move_in_date` date NOT NULL,
  `status` enum('pending','in_progress','completed','cancelled') NOT NULL DEFAULT 'pending',
  `contract_verified` tinyint(1) NOT NULL DEFAULT 0,
  `contract_verified_by` int(11) DEFAULT NULL COMMENT 'user_id',
  `contract_verified_at` datetime DEFAULT NULL,
  `payment_confirmed` tinyint(1) NOT NULL DEFAULT 0,
  `payment_confirmed_by` int(11) DEFAULT NULL COMMENT 'user_id',
  `payment_confirmed_at` datetime DEFAULT NULL,
  `deposit_received` tinyint(1) NOT NULL DEFAULT 0,
  `deposit_amount` decimal(10,2) DEFAULT NULL,
  `keys_handed_over` tinyint(1) NOT NULL DEFAULT 0,
  `keys_handed_over_by` int(11) DEFAULT NULL COMMENT 'user_id',
  `keys_handed_over_at` datetime DEFAULT NULL,
  `keys_received_by_tenant` tinyint(1) NOT NULL DEFAULT 0,
  `inspection_completed` tinyint(1) NOT NULL DEFAULT 0,
  `inspection_completed_by` int(11) DEFAULT NULL COMMENT 'user_id',
  `inspection_completed_at` datetime DEFAULT NULL,
  `inspection_notes` text DEFAULT NULL,
  `approved_by` int(11) DEFAULT NULL COMMENT 'user_id',
  `approved_at` datetime DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL COMMENT 'user_id',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_company` (`company_id`),
  KEY `idx_lease` (`lease_id`),
  KEY `idx_status` (`status`),
  KEY `idx_move_in_date` (`move_in_date`),
  KEY `created_by` (`created_by`),
  KEY `approved_by` (`approved_by`),
  KEY `idx_lease_status` (`lease_id`,`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- Table: re_move_in_checklist_items
CREATE TABLE IF NOT EXISTS `re_move_in_checklist_items` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `company_id` int(11) NOT NULL,
  `move_in_id` int(11) NOT NULL,
  `checklist_template_id` int(11) DEFAULT NULL,
  `item_name` varchar(255) NOT NULL,
  `item_description` text DEFAULT NULL,
  `is_required` tinyint(1) NOT NULL DEFAULT 1,
  `is_completed` tinyint(1) NOT NULL DEFAULT 0,
  `completed_by` int(11) DEFAULT NULL COMMENT 'user_id',
  `completed_at` datetime DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `display_order` int(11) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_company` (`company_id`),
  KEY `idx_move_in` (`move_in_id`),
  KEY `idx_template` (`checklist_template_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- Table: re_move_in_checklist_templates
CREATE TABLE IF NOT EXISTS `re_move_in_checklist_templates` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `company_id` int(11) NOT NULL,
  `item_name` varchar(255) NOT NULL,
  `item_description` text DEFAULT NULL,
  `is_required` tinyint(1) NOT NULL DEFAULT 1,
  `display_order` int(11) NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_company` (`company_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- Table: re_move_in_photos
CREATE TABLE IF NOT EXISTS `re_move_in_photos` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `company_id` int(11) NOT NULL,
  `move_in_id` int(11) NOT NULL,
  `photo_path` varchar(500) NOT NULL,
  `photo_description` varchar(255) DEFAULT NULL,
  `room_area` varchar(100) DEFAULT NULL COMMENT 'e.g., Living Room, Kitchen, Bedroom 1',
  `uploaded_by` int(11) DEFAULT NULL COMMENT 'user_id',
  `uploaded_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_company` (`company_id`),
  KEY `idx_move_in` (`move_in_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- Table: re_move_operations
CREATE TABLE IF NOT EXISTS `re_move_operations` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `company_id` int(11) NOT NULL,
  `lease_id` int(11) NOT NULL,
  `unit_id` int(11) NOT NULL,
  `tenant_id` int(11) NOT NULL,
  `operation_type` enum('move_in','move_out') NOT NULL,
  `operation_date` date NOT NULL,
  `status` enum('pending','in_progress','completed','cancelled') NOT NULL DEFAULT 'pending',
  `contract_verified` tinyint(1) DEFAULT 0,
  `payment_confirmed` tinyint(1) DEFAULT 0,
  `keys_handed_over` tinyint(1) DEFAULT 0,
  `meter_reading_electricity` decimal(10,2) DEFAULT NULL,
  `meter_reading_water` decimal(10,2) DEFAULT NULL,
  `meter_reading_gas` decimal(10,2) DEFAULT NULL,
  `inspection_completed` tinyint(1) DEFAULT 0,
  `inspection_notes` text DEFAULT NULL,
  `notice_received_date` date DEFAULT NULL,
  `final_inspection_date` date DEFAULT NULL,
  `damage_assessment` text DEFAULT NULL,
  `deposit_deduction_amount` decimal(12,2) DEFAULT 0.00,
  `deposit_deduction_reason` text DEFAULT NULL,
  `deposit_returned_amount` decimal(12,2) DEFAULT 0.00,
  `final_meter_reading_electricity` decimal(10,2) DEFAULT NULL,
  `final_meter_reading_water` decimal(10,2) DEFAULT NULL,
  `final_meter_reading_gas` decimal(10,2) DEFAULT NULL,
  `completed_by` int(11) DEFAULT NULL COMMENT 'user_id',
  `completed_at` datetime DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `created_by` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_company` (`company_id`),
  KEY `idx_lease` (`lease_id`),
  KEY `idx_unit` (`unit_id`),
  KEY `idx_tenant` (`tenant_id`),
  KEY `idx_type_status` (`operation_type`,`status`),
  KEY `idx_date` (`operation_date`),
  KEY `idx_created_by` (`created_by`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- Table: re_move_outs
CREATE TABLE IF NOT EXISTS `re_move_outs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `company_id` int(11) NOT NULL,
  `lease_id` int(11) NOT NULL,
  `move_out_notice_id` int(11) DEFAULT NULL,
  `actual_move_out_date` date NOT NULL,
  `status` enum('pending','inspection_scheduled','inspection_completed','deposit_processing','completed','cancelled') NOT NULL DEFAULT 'pending',
  `inspection_scheduled_date` date DEFAULT NULL,
  `inspection_completed` tinyint(1) NOT NULL DEFAULT 0,
  `inspection_completed_by` int(11) DEFAULT NULL COMMENT 'user_id',
  `inspection_completed_at` datetime DEFAULT NULL,
  `keys_returned` tinyint(1) NOT NULL DEFAULT 0,
  `keys_returned_by` int(11) DEFAULT NULL COMMENT 'user_id',
  `keys_returned_at` datetime DEFAULT NULL,
  `final_inspection_notes` text DEFAULT NULL,
  `damage_assessment_total` decimal(10,2) DEFAULT 0.00,
  `deposit_deduction_amount` decimal(10,2) DEFAULT 0.00,
  `deposit_refund_amount` decimal(10,2) DEFAULT NULL,
  `deposit_status` enum('pending','processing','refunded','forfeited','partial') DEFAULT NULL,
  `deposit_refunded_at` datetime DEFAULT NULL,
  `deposit_refund_method` varchar(100) DEFAULT NULL,
  `deposit_refund_reference` varchar(255) DEFAULT NULL,
  `approved_by` int(11) DEFAULT NULL COMMENT 'user_id',
  `approved_at` datetime DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL COMMENT 'user_id',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_company` (`company_id`),
  KEY `idx_lease` (`lease_id`),
  KEY `idx_notice` (`move_out_notice_id`),
  KEY `idx_status` (`status`),
  KEY `idx_move_out_date` (`actual_move_out_date`),
  KEY `created_by` (`created_by`),
  KEY `approved_by` (`approved_by`),
  KEY `idx_lease_status` (`lease_id`,`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- Table: re_move_out_damages
CREATE TABLE IF NOT EXISTS `re_move_out_damages` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `company_id` int(11) NOT NULL,
  `move_out_id` int(11) NOT NULL,
  `damage_description` text NOT NULL,
  `room_area` varchar(100) DEFAULT NULL,
  `damage_type` enum('minor','moderate','major','severe') NOT NULL DEFAULT 'minor',
  `repair_cost` decimal(10,2) NOT NULL DEFAULT 0.00,
  `photo_path` varchar(500) DEFAULT NULL,
  `assessed_by` int(11) DEFAULT NULL COMMENT 'user_id',
  `assessed_at` datetime DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_company` (`company_id`),
  KEY `idx_move_out` (`move_out_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- Table: re_move_out_notices
CREATE TABLE IF NOT EXISTS `re_move_out_notices` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `company_id` int(11) NOT NULL,
  `lease_id` int(11) NOT NULL,
  `notice_date` date NOT NULL,
  `intended_move_out_date` date NOT NULL,
  `notice_type` enum('tenant','landlord','mutual') NOT NULL DEFAULT 'tenant',
  `notice_reason` text DEFAULT NULL,
  `notice_delivered_by` varchar(255) DEFAULT NULL COMMENT 'How notice was delivered',
  `status` enum('pending','acknowledged','cancelled') NOT NULL DEFAULT 'pending',
  `acknowledged_by` int(11) DEFAULT NULL COMMENT 'user_id',
  `acknowledged_at` datetime DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL COMMENT 'user_id',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_company` (`company_id`),
  KEY `idx_lease` (`lease_id`),
  KEY `idx_status` (`status`),
  KEY `idx_intended_date` (`intended_move_out_date`),
  KEY `created_by` (`created_by`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- Table: re_move_out_photos
CREATE TABLE IF NOT EXISTS `re_move_out_photos` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `company_id` int(11) NOT NULL,
  `move_out_id` int(11) NOT NULL,
  `photo_path` varchar(500) NOT NULL,
  `photo_description` varchar(255) DEFAULT NULL,
  `room_area` varchar(100) DEFAULT NULL,
  `is_damage` tinyint(1) NOT NULL DEFAULT 0,
  `damage_id` int(11) DEFAULT NULL,
  `uploaded_by` int(11) DEFAULT NULL COMMENT 'user_id',
  `uploaded_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_company` (`company_id`),
  KEY `idx_move_out` (`move_out_id`),
  KEY `idx_damage` (`damage_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- Table: re_overdue_rent_alerts
CREATE TABLE IF NOT EXISTS `re_overdue_rent_alerts` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `company_id` int(11) NOT NULL,
  `lease_id` int(11) NOT NULL,
  `installment_id` int(11) DEFAULT NULL,
  `billing_item_id` int(11) DEFAULT NULL,
  `invoice_id` int(11) DEFAULT NULL,
  `due_date` date NOT NULL,
  `amount` decimal(10,2) NOT NULL,
  `days_overdue` int(11) NOT NULL,
  `alert_sent_date` date NOT NULL,
  `alert_sent_to` text DEFAULT NULL COMMENT 'Comma-separated emails',
  `status` enum('pending','sent','resolved','cancelled') NOT NULL DEFAULT 'pending',
  `resolved_date` date DEFAULT NULL,
  `resolved_by` int(11) DEFAULT NULL COMMENT 'user_id',
  `notes` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_company` (`company_id`),
  KEY `idx_lease` (`lease_id`),
  KEY `idx_status` (`status`),
  KEY `idx_due_date` (`due_date`),
  KEY `idx_installment` (`installment_id`),
  KEY `idx_billing_item` (`billing_item_id`),
  KEY `idx_invoice` (`invoice_id`),
  KEY `resolved_by` (`resolved_by`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- Table: re_payments
CREATE TABLE IF NOT EXISTS `re_payments` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `company_id` int(11) NOT NULL,
  `lease_id` int(11) NOT NULL,
  `installment_id` int(11) DEFAULT NULL,
  `payment_date` date NOT NULL,
  `amount` decimal(12,2) NOT NULL,
  `payment_method` enum('cash','bank_transfer','cheque','auto_debit') NOT NULL,
  `reference_number` varchar(100) DEFAULT NULL,
  `receipt_number` varchar(50) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `created_by` int(11) DEFAULT NULL,
  `tax_amount` decimal(10,2) DEFAULT 0.00,
  `invoice_id` int(11) DEFAULT NULL,
  `cheque_id` int(11) DEFAULT NULL COMMENT 'Link to post-dated cheque if payment from cheque',
  PRIMARY KEY (`id`),
  KEY `idx_company` (`company_id`),
  KEY `idx_lease` (`lease_id`),
  KEY `idx_installment` (`installment_id`),
  KEY `idx_date` (`payment_date`),
  KEY `idx_created_by` (`created_by`),
  KEY `idx_invoice` (`invoice_id`),
  KEY `idx_cheque` (`cheque_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- Table: re_payment_notifications
CREATE TABLE IF NOT EXISTS `re_payment_notifications` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `company_id` int(11) NOT NULL,
  `payment_id` int(11) NOT NULL,
  `lease_id` int(11) NOT NULL,
  `notification_sent_date` datetime NOT NULL,
  `notification_sent_to` text DEFAULT NULL COMMENT 'Comma-separated emails',
  `notification_type` enum('payment_received','partial_payment','full_payment') NOT NULL DEFAULT 'payment_received',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_company` (`company_id`),
  KEY `idx_payment` (`payment_id`),
  KEY `idx_lease` (`lease_id`),
  KEY `idx_sent_date` (`notification_sent_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- Table: re_penalty_rules
CREATE TABLE IF NOT EXISTS `re_penalty_rules` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `company_id` int(11) NOT NULL,
  `rule_name` varchar(255) NOT NULL,
  `rule_description` text DEFAULT NULL,
  `penalty_type` enum('late_payment','bounced_cheque','violation','other') NOT NULL DEFAULT 'late_payment',
  `calculation_method` enum('fixed','percentage','per_day') NOT NULL DEFAULT 'fixed',
  `amount` decimal(10,2) DEFAULT 0.00,
  `percentage` decimal(5,2) DEFAULT 0.00 COMMENT 'Percentage of amount',
  `per_day_amount` decimal(10,2) DEFAULT 0.00 COMMENT 'Amount per day for late payment',
  `grace_period_days` int(11) DEFAULT 0 COMMENT 'Days before penalty applies',
  `max_penalty_amount` decimal(10,2) DEFAULT NULL COMMENT 'Maximum penalty cap',
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_company` (`company_id`),
  KEY `idx_penalty_type` (`penalty_type`),
  KEY `idx_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- Table: re_post_dated_cheques
CREATE TABLE IF NOT EXISTS `re_post_dated_cheques` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `company_id` int(11) NOT NULL,
  `lease_id` int(11) NOT NULL,
  `cheque_number` varchar(100) NOT NULL,
  `bank_name` varchar(255) DEFAULT NULL,
  `account_holder_name` varchar(255) DEFAULT NULL,
  `cheque_amount` decimal(10,2) NOT NULL,
  `cheque_date` date NOT NULL COMMENT 'Date on cheque',
  `received_date` date DEFAULT NULL COMMENT 'Date cheque was received',
  `status` enum('pending','deposited','cleared','bounced','cancelled') NOT NULL DEFAULT 'pending',
  `deposited_date` date DEFAULT NULL,
  `cleared_date` date DEFAULT NULL,
  `bounced_date` date DEFAULT NULL,
  `bounced_reason` text DEFAULT NULL,
  `billing_item_id` int(11) DEFAULT NULL COMMENT 'Linked billing item',
  `installment_id` int(11) DEFAULT NULL COMMENT 'Linked installment',
  `payment_id` int(11) DEFAULT NULL COMMENT 'Payment record if cleared',
  `notes` text DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL COMMENT 'user_id',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_company` (`company_id`),
  KEY `idx_lease` (`lease_id`),
  KEY `idx_cheque_date` (`cheque_date`),
  KEY `idx_status` (`status`),
  KEY `idx_billing_item` (`billing_item_id`),
  KEY `idx_installment` (`installment_id`),
  KEY `payment_id` (`payment_id`),
  KEY `created_by` (`created_by`),
  KEY `idx_cheque_status_date` (`status`,`cheque_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- Table: re_preventive_maintenance_history
CREATE TABLE IF NOT EXISTS `re_preventive_maintenance_history` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `company_id` int(11) NOT NULL,
  `task_id` int(11) NOT NULL,
  `schedule_id` int(11) NOT NULL,
  `asset_id` int(11) DEFAULT NULL,
  `maintenance_request_id` int(11) DEFAULT NULL,
  `performed_date` date NOT NULL,
  `performed_by` int(11) DEFAULT NULL,
  `duration_minutes` int(11) DEFAULT NULL,
  `cost` decimal(12,2) DEFAULT 0.00,
  `status_before` varchar(100) DEFAULT NULL COMMENT 'Condition before maintenance',
  `status_after` varchar(100) DEFAULT NULL COMMENT 'Condition after maintenance',
  `issues_found` text DEFAULT NULL,
  `parts_replaced` text DEFAULT NULL,
  `next_service_due` date DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_company` (`company_id`),
  KEY `idx_task` (`task_id`),
  KEY `idx_schedule` (`schedule_id`),
  KEY `idx_asset` (`asset_id`),
  KEY `idx_performed_date` (`performed_date`),
  KEY `maintenance_request_id` (`maintenance_request_id`),
  KEY `performed_by` (`performed_by`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- Table: re_preventive_maintenance_schedules
CREATE TABLE IF NOT EXISTS `re_preventive_maintenance_schedules` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `company_id` int(11) NOT NULL,
  `schedule_name` varchar(255) NOT NULL,
  `asset_id` int(11) DEFAULT NULL COMMENT 'NULL = applies to all assets of type',
  `asset_type` enum('ac_unit','elevator','fire_system','plumbing','electrical','hvac','generator','pump','security_system','other') DEFAULT NULL,
  `building_id` int(11) DEFAULT NULL COMMENT 'NULL = all buildings',
  `task_description` text NOT NULL,
  `frequency_type` enum('daily','weekly','monthly','quarterly','semi_annual','annual','custom') NOT NULL,
  `frequency_value` int(11) DEFAULT NULL COMMENT 'For custom: number of days',
  `frequency_day` int(11) DEFAULT NULL COMMENT 'Day of week (1-7) or day of month (1-31)',
  `frequency_month` int(11) DEFAULT NULL COMMENT 'Month (1-12) for annual',
  `estimated_duration_minutes` int(11) DEFAULT NULL,
  `estimated_cost` decimal(12,2) DEFAULT 0.00,
  `assigned_to` int(11) DEFAULT NULL COMMENT 'Default employee/team',
  `priority` enum('low','medium','high','urgent') NOT NULL DEFAULT 'medium',
  `category` varchar(100) DEFAULT NULL,
  `required_parts` text DEFAULT NULL COMMENT 'List of required parts/materials',
  `instructions` text DEFAULT NULL COMMENT 'Step-by-step instructions',
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `next_due_date` date DEFAULT NULL COMMENT 'Calculated next due date',
  `last_completed_date` date DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_company` (`company_id`),
  KEY `idx_asset` (`asset_id`),
  KEY `idx_asset_type` (`asset_type`),
  KEY `idx_building` (`building_id`),
  KEY `idx_frequency` (`frequency_type`),
  KEY `idx_next_due` (`next_due_date`),
  KEY `idx_active` (`is_active`),
  KEY `assigned_to` (`assigned_to`),
  KEY `created_by` (`created_by`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- Table: re_preventive_maintenance_tasks
CREATE TABLE IF NOT EXISTS `re_preventive_maintenance_tasks` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `company_id` int(11) NOT NULL,
  `schedule_id` int(11) NOT NULL,
  `asset_id` int(11) DEFAULT NULL,
  `building_id` int(11) DEFAULT NULL,
  `unit_id` int(11) DEFAULT NULL,
  `maintenance_request_id` int(11) DEFAULT NULL COMMENT 'Linked maintenance request if created',
  `task_name` varchar(255) NOT NULL,
  `task_description` text NOT NULL,
  `due_date` date NOT NULL,
  `scheduled_date` date DEFAULT NULL COMMENT 'Planned execution date',
  `completed_date` date DEFAULT NULL,
  `status` enum('pending','scheduled','in_progress','completed','skipped','cancelled') NOT NULL DEFAULT 'pending',
  `assigned_to` int(11) DEFAULT NULL,
  `completed_by` int(11) DEFAULT NULL,
  `actual_duration_minutes` int(11) DEFAULT NULL,
  `actual_cost` decimal(12,2) DEFAULT 0.00,
  `notes` text DEFAULT NULL,
  `completion_notes` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_company` (`company_id`),
  KEY `idx_schedule` (`schedule_id`),
  KEY `idx_asset` (`asset_id`),
  KEY `idx_building` (`building_id`),
  KEY `idx_unit` (`unit_id`),
  KEY `idx_due_date` (`due_date`),
  KEY `idx_status` (`status`),
  KEY `idx_assigned` (`assigned_to`),
  KEY `idx_maintenance_request` (`maintenance_request_id`),
  KEY `completed_by` (`completed_by`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- Table: re_service_agreements
CREATE TABLE IF NOT EXISTS `re_service_agreements` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `company_id` int(11) NOT NULL,
  `vendor_id` int(11) NOT NULL,
  `agreement_number` varchar(100) NOT NULL,
  `agreement_name` varchar(255) NOT NULL,
  `service_type` enum('maintenance','cleaning','security','landscaping','plumbing','electrical','hvac','pest_control','waste_management','other') NOT NULL,
  `start_date` date NOT NULL,
  `end_date` date DEFAULT NULL COMMENT 'NULL for ongoing agreements',
  `renewal_date` date DEFAULT NULL,
  `auto_renew` tinyint(1) NOT NULL DEFAULT 0,
  `billing_frequency` enum('one_time','monthly','quarterly','semi_annual','annual') NOT NULL DEFAULT 'monthly',
  `contract_value` decimal(15,2) NOT NULL DEFAULT 0.00,
  `currency` varchar(3) DEFAULT 'AED',
  `payment_terms` varchar(100) DEFAULT NULL,
  `scope_of_work` text DEFAULT NULL,
  `terms_and_conditions` text DEFAULT NULL,
  `sla_requirements` text DEFAULT NULL COMMENT 'Service level agreement requirements',
  `penalty_clauses` text DEFAULT NULL,
  `status` enum('draft','active','expired','terminated','renewed') NOT NULL DEFAULT 'draft',
  `signed_date` date DEFAULT NULL,
  `signed_by` varchar(255) DEFAULT NULL,
  `document_path` varchar(500) DEFAULT NULL COMMENT 'Path to signed agreement document',
  `notes` text DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_company_agreement_number` (`company_id`,`agreement_number`),
  KEY `idx_company` (`company_id`),
  KEY `idx_vendor` (`vendor_id`),
  KEY `idx_service_type` (`service_type`),
  KEY `idx_status` (`status`),
  KEY `idx_start_date` (`start_date`),
  KEY `idx_end_date` (`end_date`),
  KEY `created_by` (`created_by`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- Table: re_service_charges
CREATE TABLE IF NOT EXISTS `re_service_charges` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `company_id` int(11) NOT NULL,
  `lease_id` int(11) NOT NULL,
  `charge_type_id` int(11) DEFAULT NULL,
  `charge_name` varchar(255) NOT NULL,
  `charge_description` text DEFAULT NULL,
  `amount` decimal(10,2) NOT NULL,
  `charge_type` enum('fixed','per_unit','percentage','per_sqm') NOT NULL DEFAULT 'fixed',
  `is_recurring` tinyint(1) NOT NULL DEFAULT 1,
  `recurrence_type` enum('monthly','quarterly','annually','one_time') NOT NULL DEFAULT 'monthly',
  `start_date` date NOT NULL,
  `end_date` date DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_company` (`company_id`),
  KEY `idx_lease` (`lease_id`),
  KEY `idx_charge_type` (`charge_type_id`),
  KEY `idx_active` (`is_active`),
  KEY `idx_dates` (`start_date`,`end_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- Table: re_service_charge_types
CREATE TABLE IF NOT EXISTS `re_service_charge_types` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `company_id` int(11) NOT NULL,
  `charge_name` varchar(255) NOT NULL,
  `charge_description` text DEFAULT NULL,
  `charge_type` enum('fixed','per_unit','percentage','per_sqm') NOT NULL DEFAULT 'fixed',
  `default_amount` decimal(10,2) DEFAULT 0.00,
  `is_recurring` tinyint(1) NOT NULL DEFAULT 1,
  `recurrence_type` enum('monthly','quarterly','annually','one_time') NOT NULL DEFAULT 'monthly',
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_company` (`company_id`),
  KEY `idx_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- Table: re_sla_rules
CREATE TABLE IF NOT EXISTS `re_sla_rules` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `company_id` int(11) NOT NULL,
  `priority` enum('low','medium','high','urgent') NOT NULL,
  `category` varchar(100) DEFAULT NULL COMMENT 'NULL means applies to all categories',
  `response_time_minutes` int(11) NOT NULL COMMENT 'Target time to respond (in minutes)',
  `resolution_time_hours` int(11) NOT NULL COMMENT 'Target time to resolve (in hours)',
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_company_priority_category` (`company_id`,`priority`,`category`),
  KEY `idx_company` (`company_id`),
  KEY `idx_priority` (`priority`),
  KEY `idx_category` (`category`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- Table: re_sla_tracking
CREATE TABLE IF NOT EXISTS `re_sla_tracking` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `company_id` int(11) NOT NULL,
  `maintenance_request_id` int(11) NOT NULL,
  `sla_rule_id` int(11) DEFAULT NULL COMMENT 'SLA rule that was applied',
  `target_response_time` datetime DEFAULT NULL COMMENT 'Target time to respond',
  `actual_response_time` datetime DEFAULT NULL COMMENT 'Actual time when request was assigned/acknowledged',
  `target_resolution_time` datetime DEFAULT NULL COMMENT 'Target time to resolve',
  `actual_resolution_time` datetime DEFAULT NULL COMMENT 'Actual time when request was completed',
  `response_time_minutes` int(11) DEFAULT NULL COMMENT 'Actual response time in minutes',
  `resolution_time_hours` decimal(10,2) DEFAULT NULL COMMENT 'Actual resolution time in hours',
  `response_sla_met` tinyint(1) DEFAULT NULL COMMENT '1 = met, 0 = violated, NULL = not applicable',
  `resolution_sla_met` tinyint(1) DEFAULT NULL COMMENT '1 = met, 0 = violated, NULL = not applicable',
  `sla_violation_notified` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'Whether violation email was sent',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_company` (`company_id`),
  KEY `idx_maintenance_request` (`maintenance_request_id`),
  KEY `idx_sla_rule` (`sla_rule_id`),
  KEY `idx_response_sla` (`response_sla_met`),
  KEY `idx_resolution_sla` (`resolution_sla_met`),
  KEY `idx_violation_notified` (`sla_violation_notified`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- Table: re_tasks
CREATE TABLE IF NOT EXISTS `re_tasks` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `company_id` int(11) NOT NULL,
  `task_title` varchar(255) NOT NULL,
  `task_description` text DEFAULT NULL,
  `category_id` int(11) DEFAULT NULL,
  `task_type` enum('inspection','follow_up','approval','general','maintenance','compliance','documentation','other') NOT NULL DEFAULT 'general',
  `priority` enum('low','medium','high','urgent') NOT NULL DEFAULT 'medium',
  `status` enum('pending','in_progress','on_hold','completed','cancelled') NOT NULL DEFAULT 'pending',
  `assigned_to` int(11) DEFAULT NULL COMMENT 'employee_id',
  `created_by` int(11) DEFAULT NULL COMMENT 'user_id',
  `due_date` date DEFAULT NULL,
  `start_date` date DEFAULT NULL,
  `completed_date` date DEFAULT NULL,
  `estimated_hours` decimal(10,2) DEFAULT NULL,
  `actual_hours` decimal(10,2) DEFAULT NULL,
  `related_type` enum('building','unit','tenant','lease','maintenance_request','payment','document','other') DEFAULT NULL,
  `related_id` int(11) DEFAULT NULL COMMENT 'ID of related record',
  `building_id` int(11) DEFAULT NULL,
  `unit_id` int(11) DEFAULT NULL,
  `tenant_id` int(11) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `completion_notes` text DEFAULT NULL,
  `attachments` text DEFAULT NULL COMMENT 'JSON array of file paths',
  `is_recurring` tinyint(1) NOT NULL DEFAULT 0,
  `recurrence_pattern` varchar(100) DEFAULT NULL COMMENT 'daily, weekly, monthly, etc.',
  `recurrence_interval` int(11) DEFAULT NULL,
  `parent_task_id` int(11) DEFAULT NULL COMMENT 'For recurring tasks',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_company` (`company_id`),
  KEY `idx_category` (`category_id`),
  KEY `idx_assigned_to` (`assigned_to`),
  KEY `idx_created_by` (`created_by`),
  KEY `idx_status` (`status`),
  KEY `idx_priority` (`priority`),
  KEY `idx_due_date` (`due_date`),
  KEY `idx_related` (`related_type`,`related_id`),
  KEY `idx_building` (`building_id`),
  KEY `idx_unit` (`unit_id`),
  KEY `idx_tenant` (`tenant_id`),
  KEY `idx_parent_task` (`parent_task_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- Table: re_task_attachments
CREATE TABLE IF NOT EXISTS `re_task_attachments` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `company_id` int(11) NOT NULL,
  `task_id` int(11) NOT NULL,
  `file_name` varchar(255) NOT NULL,
  `file_path` varchar(500) NOT NULL,
  `file_size` int(11) NOT NULL,
  `mime_type` varchar(100) DEFAULT NULL,
  `uploaded_by` int(11) DEFAULT NULL,
  `uploaded_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_company` (`company_id`),
  KEY `idx_task` (`task_id`),
  KEY `uploaded_by` (`uploaded_by`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- Table: re_task_categories
CREATE TABLE IF NOT EXISTS `re_task_categories` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `company_id` int(11) NOT NULL,
  `category_name` varchar(100) NOT NULL,
  `description` text DEFAULT NULL,
  `color` varchar(7) DEFAULT '#007bff' COMMENT 'Hex color for UI',
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_company_category` (`company_id`,`category_name`),
  KEY `idx_company` (`company_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- Table: re_task_comments
CREATE TABLE IF NOT EXISTS `re_task_comments` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `company_id` int(11) NOT NULL,
  `task_id` int(11) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `comment` text NOT NULL,
  `is_internal` tinyint(1) NOT NULL DEFAULT 1 COMMENT 'Internal note vs visible to tenant',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_company` (`company_id`),
  KEY `idx_task` (`task_id`),
  KEY `idx_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- Table: re_task_history
CREATE TABLE IF NOT EXISTS `re_task_history` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `company_id` int(11) NOT NULL,
  `task_id` int(11) NOT NULL,
  `action` varchar(50) NOT NULL COMMENT 'created, assigned, status_changed, priority_changed, etc.',
  `old_value` varchar(255) DEFAULT NULL,
  `new_value` varchar(255) DEFAULT NULL,
  `changed_by` int(11) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_company` (`company_id`),
  KEY `idx_task` (`task_id`),
  KEY `idx_changed_by` (`changed_by`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- Table: re_task_templates
CREATE TABLE IF NOT EXISTS `re_task_templates` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `company_id` int(11) NOT NULL,
  `template_name` varchar(255) NOT NULL,
  `task_type` enum('inspection','follow_up','approval','general','maintenance','compliance','documentation','other') NOT NULL DEFAULT 'general',
  `category_id` int(11) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `default_priority` enum('low','medium','high','urgent') NOT NULL DEFAULT 'medium',
  `estimated_hours` decimal(10,2) DEFAULT NULL,
  `checklist_items` text DEFAULT NULL COMMENT 'JSON array',
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_company` (`company_id`),
  KEY `idx_category` (`category_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- Table: re_tenants
CREATE TABLE IF NOT EXISTS `re_tenants` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `company_id` int(11) NOT NULL,
  `tenant_type` enum('individual','company') NOT NULL DEFAULT 'individual',
  `company_name` varchar(200) DEFAULT NULL,
  `first_name` varchar(100) NOT NULL,
  `last_name` varchar(100) NOT NULL,
  `email` varchar(150) DEFAULT NULL,
  `phone` varchar(50) DEFAULT NULL,
  `phone_alt` varchar(50) DEFAULT NULL,
  `id_type` enum('emirates_id','passport','visa') NOT NULL,
  `id_number` varchar(100) NOT NULL,
  `visa_expiry_date` date DEFAULT NULL,
  `address` text DEFAULT NULL,
  `emergency_contact_name` varchar(200) DEFAULT NULL,
  `emergency_contact_phone` varchar(50) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_company` (`company_id`),
  KEY `idx_id_number` (`id_number`),
  KEY `idx_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- Table: re_tenant_issues_violations
CREATE TABLE IF NOT EXISTS `re_tenant_issues_violations` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `tenant_id` int(11) NOT NULL,
  `lease_id` int(11) DEFAULT NULL,
  `unit_id` int(11) DEFAULT NULL,
  `issue_type` enum('violation','complaint','warning','notice','other') NOT NULL DEFAULT 'other',
  `severity` enum('low','medium','high','critical') NOT NULL DEFAULT 'medium',
  `title` varchar(200) NOT NULL,
  `description` text NOT NULL,
  `reported_date` date NOT NULL,
  `resolved_date` date DEFAULT NULL,
  `resolution_notes` text DEFAULT NULL,
  `reported_by` int(11) DEFAULT NULL,
  `resolved_by` int(11) DEFAULT NULL,
  `status` enum('open','in_progress','resolved','closed') NOT NULL DEFAULT 'open',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_tenant` (`tenant_id`),
  KEY `idx_lease` (`lease_id`),
  KEY `idx_unit` (`unit_id`),
  KEY `idx_status` (`status`),
  KEY `idx_issue_type` (`issue_type`),
  KEY `idx_reported_by` (`reported_by`),
  KEY `resolved_by` (`resolved_by`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- Table: re_tenant_payment_behavior
CREATE TABLE IF NOT EXISTS `re_tenant_payment_behavior` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `tenant_id` int(11) NOT NULL,
  `lease_id` int(11) DEFAULT NULL,
  `period_start` date NOT NULL,
  `period_end` date NOT NULL,
  `total_due` decimal(12,2) NOT NULL DEFAULT 0.00,
  `total_paid` decimal(12,2) NOT NULL DEFAULT 0.00,
  `on_time_payments` int(11) NOT NULL DEFAULT 0,
  `late_payments` int(11) NOT NULL DEFAULT 0,
  `missed_payments` int(11) NOT NULL DEFAULT 0,
  `average_days_late` decimal(5,2) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_tenant` (`tenant_id`),
  KEY `idx_lease` (`lease_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- Table: re_tenant_unit_history
CREATE TABLE IF NOT EXISTS `re_tenant_unit_history` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `tenant_id` int(11) NOT NULL,
  `unit_id` int(11) NOT NULL,
  `lease_id` int(11) DEFAULT NULL,
  `move_in_date` date NOT NULL,
  `move_out_date` date DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_tenant` (`tenant_id`),
  KEY `idx_unit` (`unit_id`),
  KEY `idx_lease` (`lease_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- Table: re_units
CREATE TABLE IF NOT EXISTS `re_units` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `company_id` int(11) NOT NULL,
  `building_id` int(11) NOT NULL,
  `floor_id` int(11) DEFAULT NULL,
  `unit_number` varchar(50) NOT NULL,
  `premises_number` varchar(50) DEFAULT NULL,
  `unit_type` enum('studio','1br','2br','3br','4br','penthouse','commercial') NOT NULL,
  `area_sqm` decimal(10,2) DEFAULT NULL,
  `status` enum('vacant','occupied','maintenance','reserved') NOT NULL DEFAULT 'vacant',
  `furniture_status` enum('furnished','unfurnished','semi_furnished') DEFAULT NULL,
  `blocked_reason` text DEFAULT NULL,
  `annual_rent` decimal(12,2) DEFAULT NULL COMMENT 'Annual rent amount in AED',
  `parking_slot` varchar(50) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_building_unit` (`building_id`,`unit_number`),
  UNIQUE KEY `uq_premises_number` (`premises_number`),
  KEY `idx_status` (`status`),
  KEY `idx_company` (`company_id`),
  KEY `idx_building` (`building_id`),
  KEY `idx_floor` (`floor_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- Table: re_unit_legal_status
CREATE TABLE IF NOT EXISTS `re_unit_legal_status` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `company_id` int(11) NOT NULL,
  `unit_id` int(11) NOT NULL,
  `legal_status` enum('compliant','non_compliant','pending_review','at_risk') NOT NULL DEFAULT 'pending_review',
  `compliance_score` int(11) DEFAULT NULL COMMENT '0-100 compliance score',
  `last_review_date` date DEFAULT NULL,
  `next_review_date` date DEFAULT NULL,
  `review_notes` text DEFAULT NULL,
  `reviewed_by` int(11) DEFAULT NULL COMMENT 'user_id',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_unit` (`company_id`,`unit_id`),
  KEY `idx_company` (`company_id`),
  KEY `idx_unit` (`unit_id`),
  KEY `idx_legal_status` (`legal_status`),
  KEY `reviewed_by` (`reviewed_by`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- Table: re_unit_status_history
CREATE TABLE IF NOT EXISTS `re_unit_status_history` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `unit_id` int(11) NOT NULL,
  `old_status` varchar(50) DEFAULT NULL,
  `new_status` varchar(50) DEFAULT NULL,
  `changed_by` int(11) DEFAULT NULL,
  `changed_at` datetime NOT NULL DEFAULT current_timestamp(),
  `reason` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_unit` (`unit_id`),
  KEY `idx_date` (`changed_at`),
  KEY `idx_changed_by` (`changed_by`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- Table: re_upcoming_due_alerts
CREATE TABLE IF NOT EXISTS `re_upcoming_due_alerts` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `company_id` int(11) NOT NULL,
  `lease_id` int(11) NOT NULL,
  `installment_id` int(11) DEFAULT NULL,
  `billing_item_id` int(11) DEFAULT NULL,
  `invoice_id` int(11) DEFAULT NULL,
  `due_date` date NOT NULL,
  `amount` decimal(10,2) NOT NULL,
  `days_before_due` int(11) NOT NULL COMMENT 'Days before due date when alert was sent',
  `alert_sent_date` date NOT NULL,
  `alert_sent_to` text DEFAULT NULL COMMENT 'Comma-separated emails',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_company` (`company_id`),
  KEY `idx_lease` (`lease_id`),
  KEY `idx_due_date` (`due_date`),
  KEY `idx_installment` (`installment_id`),
  KEY `idx_billing_item` (`billing_item_id`),
  KEY `idx_invoice` (`invoice_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- Table: re_vendors
CREATE TABLE IF NOT EXISTS `re_vendors` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `company_id` int(11) NOT NULL,
  `vendor_name` varchar(255) NOT NULL,
  `vendor_type` enum('contractor','supplier','service_provider','maintenance','cleaning','security','other') NOT NULL DEFAULT 'contractor',
  `contact_person` varchar(255) DEFAULT NULL,
  `email` varchar(255) DEFAULT NULL,
  `phone` varchar(50) DEFAULT NULL,
  `mobile` varchar(50) DEFAULT NULL,
  `address` text DEFAULT NULL,
  `city` varchar(100) DEFAULT NULL,
  `state` varchar(100) DEFAULT NULL,
  `country` varchar(100) DEFAULT NULL,
  `postal_code` varchar(20) DEFAULT NULL,
  `tax_id` varchar(100) DEFAULT NULL,
  `license_number` varchar(100) DEFAULT NULL,
  `license_expiry` date DEFAULT NULL,
  `insurance_provider` varchar(255) DEFAULT NULL,
  `insurance_policy_number` varchar(100) DEFAULT NULL,
  `insurance_expiry` date DEFAULT NULL,
  `payment_terms` varchar(100) DEFAULT NULL COMMENT 'e.g., Net 30, COD, etc.',
  `bank_name` varchar(255) DEFAULT NULL,
  `bank_account_number` varchar(100) DEFAULT NULL,
  `bank_iban` varchar(100) DEFAULT NULL,
  `rating` decimal(3,2) DEFAULT NULL COMMENT 'Average rating 0-5',
  `total_jobs` int(11) NOT NULL DEFAULT 0,
  `total_spent` decimal(15,2) NOT NULL DEFAULT 0.00,
  `status` enum('active','inactive','suspended','blacklisted') NOT NULL DEFAULT 'active',
  `notes` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_company` (`company_id`),
  KEY `idx_vendor_type` (`vendor_type`),
  KEY `idx_status` (`status`),
  KEY `idx_rating` (`rating`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- Table: re_vendor_documents
CREATE TABLE IF NOT EXISTS `re_vendor_documents` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `company_id` int(11) NOT NULL,
  `vendor_id` int(11) DEFAULT NULL,
  `agreement_id` int(11) DEFAULT NULL,
  `document_type` enum('license','insurance','contract','invoice','certificate','other') NOT NULL,
  `document_name` varchar(255) NOT NULL,
  `file_name` varchar(255) NOT NULL,
  `file_path` varchar(500) NOT NULL,
  `file_size` int(11) NOT NULL,
  `mime_type` varchar(100) DEFAULT NULL,
  `expiry_date` date DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `uploaded_by` int(11) DEFAULT NULL,
  `uploaded_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_company` (`company_id`),
  KEY `idx_vendor` (`vendor_id`),
  KEY `idx_agreement` (`agreement_id`),
  KEY `idx_document_type` (`document_type`),
  KEY `idx_expiry_date` (`expiry_date`),
  KEY `uploaded_by` (`uploaded_by`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- Table: re_vendor_invoices
CREATE TABLE IF NOT EXISTS `re_vendor_invoices` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `company_id` int(11) NOT NULL,
  `vendor_id` int(11) NOT NULL,
  `agreement_id` int(11) DEFAULT NULL,
  `invoice_number` varchar(100) NOT NULL,
  `invoice_date` date NOT NULL,
  `due_date` date NOT NULL,
  `subtotal` decimal(15,2) NOT NULL DEFAULT 0.00,
  `tax_amount` decimal(15,2) NOT NULL DEFAULT 0.00,
  `discount_amount` decimal(15,2) NOT NULL DEFAULT 0.00,
  `total_amount` decimal(15,2) NOT NULL DEFAULT 0.00,
  `currency` varchar(3) DEFAULT 'AED',
  `status` enum('pending','approved','paid','rejected','cancelled') NOT NULL DEFAULT 'pending',
  `payment_date` date DEFAULT NULL,
  `payment_method` varchar(50) DEFAULT NULL,
  `payment_reference` varchar(100) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `document_path` varchar(500) DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_company_invoice_number` (`company_id`,`invoice_number`),
  KEY `idx_company` (`company_id`),
  KEY `idx_vendor` (`vendor_id`),
  KEY `idx_agreement` (`agreement_id`),
  KEY `idx_status` (`status`),
  KEY `idx_invoice_date` (`invoice_date`),
  KEY `idx_due_date` (`due_date`),
  KEY `created_by` (`created_by`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- Table: re_vendor_invoice_items
CREATE TABLE IF NOT EXISTS `re_vendor_invoice_items` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `company_id` int(11) NOT NULL,
  `invoice_id` int(11) NOT NULL,
  `service_name` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `quantity` decimal(10,2) NOT NULL DEFAULT 1.00,
  `unit_price` decimal(12,2) NOT NULL DEFAULT 0.00,
  `total_price` decimal(15,2) NOT NULL DEFAULT 0.00,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_company` (`company_id`),
  KEY `idx_invoice` (`invoice_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- Table: re_vendor_performance
CREATE TABLE IF NOT EXISTS `re_vendor_performance` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `company_id` int(11) NOT NULL,
  `vendor_id` int(11) NOT NULL,
  `agreement_id` int(11) DEFAULT NULL,
  `maintenance_request_id` int(11) DEFAULT NULL,
  `task_id` int(11) DEFAULT NULL,
  `performance_date` date NOT NULL,
  `rating` int(11) NOT NULL COMMENT '1-5 rating',
  `quality_score` int(11) DEFAULT NULL COMMENT '1-10 quality score',
  `timeliness_score` int(11) DEFAULT NULL COMMENT '1-10 timeliness score',
  `communication_score` int(11) DEFAULT NULL COMMENT '1-10 communication score',
  `cost_effectiveness_score` int(11) DEFAULT NULL COMMENT '1-10 cost effectiveness score',
  `overall_score` decimal(5,2) DEFAULT NULL COMMENT 'Calculated average score',
  `comments` text DEFAULT NULL,
  `issues_encountered` text DEFAULT NULL,
  `recommendations` text DEFAULT NULL,
  `reviewed_by` int(11) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_company` (`company_id`),
  KEY `idx_vendor` (`vendor_id`),
  KEY `idx_agreement` (`agreement_id`),
  KEY `idx_maintenance_request` (`maintenance_request_id`),
  KEY `idx_task` (`task_id`),
  KEY `idx_performance_date` (`performance_date`),
  KEY `idx_rating` (`rating`),
  KEY `reviewed_by` (`reviewed_by`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- Table: re_vendor_services
CREATE TABLE IF NOT EXISTS `re_vendor_services` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `company_id` int(11) NOT NULL,
  `agreement_id` int(11) NOT NULL,
  `service_name` varchar(255) NOT NULL,
  `service_description` text DEFAULT NULL,
  `unit_price` decimal(12,2) NOT NULL DEFAULT 0.00,
  `quantity` decimal(10,2) DEFAULT 1.00,
  `unit` varchar(50) DEFAULT NULL COMMENT 'e.g., hour, sqm, unit, etc.',
  `frequency` varchar(100) DEFAULT NULL COMMENT 'e.g., daily, weekly, monthly',
  `total_amount` decimal(15,2) NOT NULL DEFAULT 0.00,
  `notes` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_company` (`company_id`),
  KEY `idx_agreement` (`agreement_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- Table: role_departments
CREATE TABLE IF NOT EXISTS `role_departments` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `role_id` int(11) NOT NULL,
  `module` varchar(50) NOT NULL COMMENT 'cleaning or realestate',
  `department` varchar(50) NOT NULL COMMENT 'Department code (e.g., cleaning_operations, hr)',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_role_module_department` (`role_id`,`module`,`department`),
  KEY `idx_role_id` (`role_id`),
  KEY `idx_module` (`module`),
  KEY `idx_department` (`department`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- Table: role_modules
CREATE TABLE IF NOT EXISTS `role_modules` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `role_id` int(11) NOT NULL,
  `module` varchar(50) NOT NULL,
  `permissions` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL COMMENT 'Module-specific permissions' CHECK (json_valid(`permissions`)),
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_role_module` (`role_id`,`module`),
  KEY `idx_role_id` (`role_id`),
  KEY `idx_module` (`module`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- Table: user_companies
CREATE TABLE IF NOT EXISTS `user_companies` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `company_id` int(11) NOT NULL,
  `is_primary` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_user_company` (`user_id`,`company_id`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_company_id` (`company_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- ============================================================================
-- PART 2: STRUCTURE CHANGES (Missing Columns & Indexes)
-- ============================================================================


-- ============================================================================
-- Table: attendance
-- ============================================================================
-- Missing columns: 1
-- Add column: company_id
ALTER TABLE `attendance` ADD COLUMN `company_id` int(11) NOT NULL DEFAULT 1;

-- Missing indexes: 1
-- Add index: idx_company_id
ALTER TABLE `attendance` ADD INDEX `idx_company_id` (`company_id`);


-- ============================================================================
-- Table: chart_of_accounts
-- ============================================================================
-- Missing columns: 1
-- Add column: company_id
ALTER TABLE `chart_of_accounts` ADD COLUMN `company_id` int(11) NOT NULL DEFAULT 1;

-- Missing indexes: 1
-- Add index: idx_company_id
ALTER TABLE `chart_of_accounts` ADD INDEX `idx_company_id` (`company_id`);


-- ============================================================================
-- Table: client
-- ============================================================================
-- Missing columns: 1
-- Add column: company_id
ALTER TABLE `client` ADD COLUMN `company_id` int(11) NOT NULL DEFAULT 1;

-- Missing indexes: 1
-- Add index: idx_company_id
ALTER TABLE `client` ADD INDEX `idx_company_id` (`company_id`);


-- ============================================================================
-- Table: company_settings
-- ============================================================================
-- Missing columns: 1
-- Add column: company_id
ALTER TABLE `company_settings` ADD COLUMN `company_id` int(11) NOT NULL DEFAULT 1;

-- Missing indexes: 1
-- Add index: uq_company_settings
ALTER TABLE `company_settings` ADD INDEX `uq_company_settings` (`company_id`);


-- ============================================================================
-- Table: credit_notes
-- ============================================================================
-- Missing columns: 1
-- Add column: company_id
ALTER TABLE `credit_notes` ADD COLUMN `company_id` int(11) NOT NULL DEFAULT 1;

-- Missing indexes: 1
-- Add index: idx_company_id
ALTER TABLE `credit_notes` ADD INDEX `idx_company_id` (`company_id`);


-- ============================================================================
-- Table: employees
-- ============================================================================
-- Missing columns: 1
-- Add column: company_id
ALTER TABLE `employees` ADD COLUMN `company_id` int(11) NOT NULL DEFAULT 1;

-- Missing indexes: 1
-- Add index: idx_company_id
ALTER TABLE `employees` ADD INDEX `idx_company_id` (`company_id`);


-- ============================================================================
-- Table: employee_documents
-- ============================================================================
-- Missing columns: 1
-- Add column: company_id
ALTER TABLE `employee_documents` ADD COLUMN `company_id` int(11) NOT NULL DEFAULT 1;

-- Missing indexes: 1
-- Add index: idx_company_id
ALTER TABLE `employee_documents` ADD INDEX `idx_company_id` (`company_id`);


-- ============================================================================
-- Table: expenses
-- ============================================================================
-- Missing columns: 1
-- Add column: company_id
ALTER TABLE `expenses` ADD COLUMN `company_id` int(11) NOT NULL DEFAULT 1;

-- Missing indexes: 1
-- Add index: idx_company_id
ALTER TABLE `expenses` ADD INDEX `idx_company_id` (`company_id`);


-- ============================================================================
-- Table: gl_journals
-- ============================================================================
-- Missing columns: 1
-- Add column: company_id
ALTER TABLE `gl_journals` ADD COLUMN `company_id` int(11) NOT NULL DEFAULT 1;

-- Missing indexes: 1
-- Add index: idx_company_id
ALTER TABLE `gl_journals` ADD INDEX `idx_company_id` (`company_id`);


-- ============================================================================
-- Table: invoices
-- ============================================================================
-- Missing columns: 1
-- Add column: company_id
ALTER TABLE `invoices` ADD COLUMN `company_id` int(11) NOT NULL DEFAULT 1;

-- Missing indexes: 1
-- Add index: idx_company_id
ALTER TABLE `invoices` ADD INDEX `idx_company_id` (`company_id`);


-- ============================================================================
-- Table: leave_requests
-- ============================================================================
-- Missing columns: 1
-- Add column: company_id
ALTER TABLE `leave_requests` ADD COLUMN `company_id` int(11) NOT NULL DEFAULT 1;

-- Missing indexes: 1
-- Add index: idx_company_id
ALTER TABLE `leave_requests` ADD INDEX `idx_company_id` (`company_id`);


-- ============================================================================
-- Table: make_order
-- ============================================================================
-- Missing columns: 1
-- Add column: company_id
ALTER TABLE `make_order` ADD COLUMN `company_id` int(11) NOT NULL DEFAULT 1;

-- Missing indexes: 1
-- Add index: idx_company_id
ALTER TABLE `make_order` ADD INDEX `idx_company_id` (`company_id`);


-- ============================================================================
-- Table: overtime_entries
-- ============================================================================
-- Missing columns: 1
-- Add column: company_id
ALTER TABLE `overtime_entries` ADD COLUMN `company_id` int(11) NOT NULL DEFAULT 1;

-- Missing indexes: 1
-- Add index: idx_company_id
ALTER TABLE `overtime_entries` ADD INDEX `idx_company_id` (`company_id`);


-- ============================================================================
-- Table: payroll_runs
-- ============================================================================
-- Missing columns: 1
-- Add column: company_id
ALTER TABLE `payroll_runs` ADD COLUMN `company_id` int(11) NOT NULL DEFAULT 1;

-- Missing indexes: 1
-- Add index: idx_company_id
ALTER TABLE `payroll_runs` ADD INDEX `idx_company_id` (`company_id`);


-- ============================================================================
-- Table: receipts
-- ============================================================================
-- Missing columns: 1
-- Add column: company_id
ALTER TABLE `receipts` ADD COLUMN `company_id` int(11) NOT NULL DEFAULT 1;

-- Missing indexes: 1
-- Add index: idx_company_id
ALTER TABLE `receipts` ADD INDEX `idx_company_id` (`company_id`);


-- ============================================================================
-- Table: refunds
-- ============================================================================
-- Missing columns: 1
-- Add column: company_id
ALTER TABLE `refunds` ADD COLUMN `company_id` int(11) NOT NULL DEFAULT 1;

-- Missing indexes: 1
-- Add index: idx_company_id
ALTER TABLE `refunds` ADD INDEX `idx_company_id` (`company_id`);


-- ============================================================================
-- Table: roles
-- ============================================================================
-- Missing columns: 2
-- Add column: module
ALTER TABLE `roles` ADD COLUMN `module` varchar(50) DEFAULT NULL COMMENT 'cleaning, realestate, hr, finance, core';

-- Add column: is_system
ALTER TABLE `roles` ADD COLUMN `is_system` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'System roles cannot be deleted';

-- Missing indexes: 1
-- Add index: idx_module
ALTER TABLE `roles` ADD INDEX `idx_module` (`module`);


-- ============================================================================
-- Table: services
-- ============================================================================
-- Missing columns: 1
-- Add column: company_id
ALTER TABLE `services` ADD COLUMN `company_id` int(11) NOT NULL DEFAULT 1;

-- Missing indexes: 1
-- Add index: idx_company_id
ALTER TABLE `services` ADD INDEX `idx_company_id` (`company_id`);


-- ============================================================================
-- Table: user
-- ============================================================================
-- Missing columns: 2
-- Add column: default_company_id
ALTER TABLE `user` ADD COLUMN `default_company_id` int(11) DEFAULT NULL;

-- Add column: company_id
ALTER TABLE `user` ADD COLUMN `company_id` int(11) NOT NULL DEFAULT 1;

-- Missing indexes: 2
-- Add index: idx_default_company
ALTER TABLE `user` ADD INDEX `idx_default_company` (`default_company_id`);

-- Add index: idx_company_id
ALTER TABLE `user` ADD INDEX `idx_company_id` (`company_id`);


-- ============================================================================
-- Table: vendors
-- ============================================================================
-- Missing columns: 1
-- Add column: company_id
ALTER TABLE `vendors` ADD COLUMN `company_id` int(11) NOT NULL DEFAULT 1;

-- Missing indexes: 1
-- Add index: idx_company_id
ALTER TABLE `vendors` ADD INDEX `idx_company_id` (`company_id`);

SET FOREIGN_KEY_CHECKS = 1;

-- ============================================================================
-- VERIFICATION
-- ============================================================================
-- Run these queries after migration to verify:

-- Check all tables exist
-- SELECT table_name 
-- FROM information_schema.tables 
-- WHERE table_schema = DATABASE() 
-- AND table_name IN ('companies', 're_billing_items', 're_bounced_cheque_alerts', 're_buildings', 're_building_common_areas', 're_building_floor_plans', 're_collections_alerts_config', 're_compliance_status', 're_contract_templates', 're_documents', 're_document_access_log', 're_document_expiry_alerts', 're_document_favorites', 're_document_sharing', 're_document_tags', 're_document_tag_relations', 're_document_types', 're_document_versions', 're_ejari_tracking', 're_email_logs', 're_email_notifications', 're_floors', 're_invoices', 're_invoice_items', 're_invoice_sequences', 're_leases', 're_lease_cheques', 're_lease_expiry_reminders', 're_lease_installments', 're_lease_renewal_workflows', 're_maintenance_assets', 're_maintenance_photos', 're_maintenance_requests', 're_maintenance_templates', 're_meter_readings', 're_missing_documents', 're_move_ins', 're_move_in_checklist_items', 're_move_in_checklist_templates', 're_move_in_photos', 're_move_operations', 're_move_outs', 're_move_out_damages', 're_move_out_notices', 're_move_out_photos', 're_overdue_rent_alerts', 're_payments', 're_payment_notifications', 're_penalty_rules', 're_post_dated_cheques', 're_preventive_maintenance_history', 're_preventive_maintenance_schedules', 're_preventive_maintenance_tasks', 're_service_agreements', 're_service_charges', 're_service_charge_types', 're_sla_rules', 're_sla_tracking', 're_tasks', 're_task_attachments', 're_task_categories', 're_task_comments', 're_task_history', 're_task_templates', 're_tenants', 're_tenant_issues_violations', 're_tenant_payment_behavior', 're_tenant_unit_history', 're_units', 're_unit_legal_status', 're_unit_status_history', 're_upcoming_due_alerts', 're_vendors', 're_vendor_documents', 're_vendor_invoices', 're_vendor_invoice_items', 're_vendor_performance', 're_vendor_services', 'role_departments', 'role_modules', 'user_companies', 'app_email_settings', 'app_email_templates', 'app_reminder_log', 'app_reminder_logs', 'app_reminder_recipients', 'app_reminder_schedules', 'app_settings', 'attendance', 'audit_log', 'billing_batches', 'billing_batch_orders', 'booking_events', 'business', 'calendar_tokens', 'cars', 'cash_advances', 'cash_advance_employee_limits', 'cash_advance_policy', 'chart_of_accounts', 'client', 'client_billing', 'client_communications', 'client_documents', 'client_notes', 'client_quality_ratings', 'client_service_preferences', 'client_sites', 'client_tasks', 'company_settings', 'comp_sa', 'coupons', 'coupon_usages', 'credit_notes', 'credit_note_allocations', 'credit_note_gl_postings', 'credit_note_items', 'customers', 'dashboard_cache', 'departments', 'discounts', 'document_types', 'doc_counters', 'driver', 'email_log', 'email_queue', 'email_queue_items', 'email_queue_settings', 'email_templates', 'emergency_contacts', 'employees', 'employee_activity_log', 'employee_assets', 'employee_awards', 'employee_award_checklists', 'employee_deductions', 'employee_documents', 'employee_emergency_contacts', 'employee_expenses', 'employee_goals', 'employee_kudos', 'employee_leaves', 'employee_notes', 'employee_pay_groups', 'employee_shifts', 'employee_skills_certifications', 'employee_time_off', 'employee_training', 'employee_work_schedules', 'employment_history', 'expenses', 'expense_attachments', 'expense_lines', 'frequency_discounts', 'fromtime', 'gl_account_balances', 'gl_journals', 'gl_journal_lines', 'holidays', 'hr_perf_config', 'invoices', 'invoice_items', 'invoice_templates', 'leave_balances', 'leave_policies', 'leave_requests', 'leave_types', 'locations', 'make_order', 'mobile_user', 'mobile_user_addresses', 'mobile_user_wallet', 'mobile_wallet_topup_packages', 'mobile_wallet_transactions', 'notification_templates', 'online_bookings', 'online_booking_items', 'order_audit', 'order_payment', 'order_services', 'order_templates', 'order_workers', 'organize', 'outbox_messages', 'overtime_entries', 'overtime_rules', 'payroll_items', 'payroll_runs', 'payroll_settings', 'pay_groups', 'perf_feedback', 'perf_month_targets', 'perf_worker_targets', 'pricing_rules', 'promotional_banners', 'receipts', 'receipt_allocations', 'recurring_adjustments', 'refunds', 'report_cache', 'roles', 'saved_report_configs', 'saved_search_filters', 'scheduled_reports', 'scheduled_report_recipients', 'scheduled_report_runs', 'search_history', 'search_suggestions', 'services', 'service_categories', 'service_items', 'service_item_groups', 'service_options', 'settings', 'shift_templates', 'system_settings', 'test', 'user', 'user_roles', 'user_tokens', 'vendors', 'v_ar_ageing', 'v_ar_ageing_by_client', 'v_ar_ageing_optimized', 'v_ar_client_credit', 'v_ar_client_statement', 'v_ar_client_summary', 'v_ar_invoices_open', 'v_ar_invoices_open_optimized', 'v_ar_open_invoices', 'v_ar_overall', 'v_ar_summary', 'v_ar_summary_optimized', 'v_attendance_daily_emp', 'v_attendance_period_emp', 'v_bookable_workers', 'v_categories_tree', 'v_client_unbilled_summary', 'v_driver', 'v_index_usage', 'v_leave_balances_current', 'v_online_bookings_summary', 'v_profit_and_loss', 'v_services_enhanced', 'v_slow_queries', 'v_table_sizes', 'v_trial_balance', 'v_workers', 'v_worker_daily_perf', 'v_worker_employee_map', 'v_worker_order_contrib', 'workers', 'worker_unavailability')
-- ORDER BY table_name;

-- ============================================================================
-- MIGRATION COMPLETE
-- ============================================================================

SELECT '✅ Comprehensive migration completed successfully!' AS status;
SELECT 'Please verify all tables and columns were created correctly.' AS reminder;
