-- ============================================================================
-- Real Estate: Bulk Tenant Email (campaigns, recipients, templates)
-- ============================================================================

CREATE TABLE IF NOT EXISTS `re_bulk_email_templates` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `company_id` INT(11) NOT NULL,
  `name` VARCHAR(150) NOT NULL,
  `subject` VARCHAR(255) NOT NULL,
  `body_html` MEDIUMTEXT NOT NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_by` INT(11) NULL DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_re_bulk_email_templates_company` (`company_id`, `is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `re_bulk_email_campaigns` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `company_id` INT(11) NOT NULL,
  `subject` VARCHAR(255) NOT NULL,
  `body_html` MEDIUMTEXT NOT NULL,
  `attachment_path` VARCHAR(500) NULL DEFAULT NULL,
  `attachment_name` VARCHAR(255) NULL DEFAULT NULL,
  `attachment_mime` VARCHAR(120) NULL DEFAULT NULL,
  `attachment_size` INT(11) NULL DEFAULT NULL,
  `filters_json` JSON NULL DEFAULT NULL,
  `selected_count` INT(11) NOT NULL DEFAULT 0,
  `unique_email_count` INT(11) NOT NULL DEFAULT 0,
  `excluded_no_email_count` INT(11) NOT NULL DEFAULT 0,
  `sent_count` INT(11) NOT NULL DEFAULT 0,
  `failed_count` INT(11) NOT NULL DEFAULT 0,
  `pending_count` INT(11) NOT NULL DEFAULT 0,
  `status` ENUM('draft','queued','sending','completed','completed_with_errors','cancelled') NOT NULL DEFAULT 'draft',
  `created_by` INT(11) NULL DEFAULT NULL,
  `confirmed_at` DATETIME NULL DEFAULT NULL,
  `started_at` DATETIME NULL DEFAULT NULL,
  `finished_at` DATETIME NULL DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_re_bulk_email_campaigns_company` (`company_id`, `created_at`),
  KEY `idx_re_bulk_email_campaigns_status` (`company_id`, `status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `re_bulk_email_recipients` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `campaign_id` INT(11) NOT NULL,
  `company_id` INT(11) NOT NULL,
  `tenant_id` INT(11) NOT NULL,
  `lease_id` INT(11) NOT NULL,
  `building_id` INT(11) NOT NULL,
  `unit_id` INT(11) NOT NULL,
  `tenant_name` VARCHAR(255) NOT NULL DEFAULT '',
  `building_name` VARCHAR(255) NOT NULL DEFAULT '',
  `unit_number` VARCHAR(100) NOT NULL DEFAULT '',
  `email` VARCHAR(190) NOT NULL,
  `email_normalized` VARCHAR(190) NOT NULL,
  `status` ENUM('pending','sending','sent','failed','skipped_duplicate','excluded_invalid') NOT NULL DEFAULT 'pending',
  `error_message` VARCHAR(500) NULL DEFAULT NULL,
  `sent_at` DATETIME NULL DEFAULT NULL,
  `attempts` INT(11) NOT NULL DEFAULT 0,
  `last_attempt_at` DATETIME NULL DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_re_bulk_email_campaign_email` (`campaign_id`, `email_normalized`),
  KEY `idx_re_bulk_email_recipients_campaign_status` (`campaign_id`, `status`),
  KEY `idx_re_bulk_email_recipients_company` (`company_id`, `campaign_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
