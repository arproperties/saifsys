-- Tenant Communication Center — announcements reusable by apps, website, AI.
-- Categories are company-configurable (not hardcoded in application logic).

CREATE TABLE IF NOT EXISTS `re_announcement_categories` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `company_id` INT(11) NOT NULL,
  `code` VARCHAR(50) NOT NULL,
  `name` VARCHAR(100) NOT NULL,
  `sort_order` INT(11) NOT NULL DEFAULT 0,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_re_ann_cat_company_code` (`company_id`, `code`),
  KEY `idx_re_ann_cat_active` (`company_id`, `is_active`, `sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `re_announcements` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `company_id` INT(11) NOT NULL,
  `category_id` INT(11) DEFAULT NULL,
  `title` VARCHAR(200) NOT NULL,
  `body` MEDIUMTEXT NOT NULL,
  `priority` ENUM('critical','high','normal','information') NOT NULL DEFAULT 'normal',
  `status` ENUM('draft','scheduled','published','archived') NOT NULL DEFAULT 'draft',
  `publish_at` DATETIME DEFAULT NULL,
  `expire_at` DATETIME DEFAULT NULL,
  `target_scope` ENUM('company','buildings','units','tenants','group') NOT NULL DEFAULT 'company'
    COMMENT 'group reserved for future targeting groups',
  `image_path` VARCHAR(500) DEFAULT NULL,
  `image_mime` VARCHAR(120) DEFAULT NULL,
  `send_in_app` TINYINT(1) NOT NULL DEFAULT 1,
  `send_push` TINYINT(1) NOT NULL DEFAULT 0,
  `push_batch_id` INT(11) DEFAULT NULL,
  `created_by` INT(11) DEFAULT NULL,
  `published_by` INT(11) DEFAULT NULL,
  `published_at` DATETIME DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_re_ann_company_status` (`company_id`, `status`, `publish_at`),
  KEY `idx_re_ann_priority` (`company_id`, `priority`, `status`),
  KEY `idx_re_ann_category` (`category_id`),
  KEY `idx_re_ann_expire` (`company_id`, `expire_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `re_announcement_targets` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `company_id` INT(11) NOT NULL,
  `announcement_id` INT(11) NOT NULL,
  `target_type` ENUM('building','unit','tenant','tenant_portal_user','group') NOT NULL,
  `target_id` INT(11) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_re_ann_target` (`announcement_id`, `target_type`, `target_id`),
  KEY `idx_re_ann_target_lookup` (`company_id`, `target_type`, `target_id`),
  KEY `idx_re_ann_target_ann` (`announcement_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `re_announcement_attachments` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `company_id` INT(11) NOT NULL,
  `announcement_id` INT(11) NOT NULL,
  `file_path` VARCHAR(500) NOT NULL,
  `file_name` VARCHAR(255) NOT NULL,
  `mime_type` VARCHAR(120) DEFAULT NULL,
  `file_size` INT(11) DEFAULT NULL,
  `uploaded_by` INT(11) DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_re_ann_attach` (`announcement_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
