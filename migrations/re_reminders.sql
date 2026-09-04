-- Real Estate Reminders
-- Adds admin/user-managed reminders, in-app notifications, and delivery logs.

CREATE TABLE IF NOT EXISTS `re_reminders` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `company_id` INT(11) NOT NULL,
  `reminder_name` VARCHAR(255) NOT NULL,
  `description` TEXT DEFAULT NULL,
  `module_type` ENUM('invoice','payment','contract','client','employee','custom') NOT NULL DEFAULT 'custom',
  `related_record_id` INT(11) DEFAULT NULL,
  `due_date` DATE NOT NULL,
  `reminder_days_before` INT(11) NOT NULL DEFAULT 0,
  `reminder_time` TIME NOT NULL DEFAULT '09:00:00',
  `recipients` TEXT DEFAULT NULL COMMENT 'JSON: {"user_ids":[1,2],"emails":["a@example.com"]}',
  `channel` ENUM('in_app','email','both') NOT NULL DEFAULT 'in_app',
  `repeat_type` ENUM('none','daily','weekly','monthly','yearly') NOT NULL DEFAULT 'none',
  `status` ENUM('active','inactive','completed') NOT NULL DEFAULT 'active',
  `last_sent_at` DATETIME DEFAULT NULL,
  `next_run_at` DATETIME DEFAULT NULL,
  `processing_started_at` DATETIME DEFAULT NULL,
  `processing_token` VARCHAR(64) DEFAULT NULL,
  `created_by` INT(11) DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_company_status_next` (`company_id`, `status`, `next_run_at`),
  KEY `idx_created_by` (`created_by`),
  KEY `idx_due_date` (`due_date`),
  KEY `idx_processing` (`processing_token`, `processing_started_at`),
  CONSTRAINT `fk_re_reminders_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_re_reminders_created_by` FOREIGN KEY (`created_by`) REFERENCES `user` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `re_reminder_logs` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `company_id` INT(11) NOT NULL,
  `reminder_id` INT(11) NOT NULL,
  `run_at` DATETIME NOT NULL,
  `channel` ENUM('in_app','email') NOT NULL,
  `recipient` VARCHAR(255) DEFAULT NULL,
  `status` ENUM('sent','failed','skipped') NOT NULL,
  `message` TEXT DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_company_reminder` (`company_id`, `reminder_id`),
  KEY `idx_status_created` (`status`, `created_at`),
  CONSTRAINT `fk_re_reminder_logs_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_re_reminder_logs_reminder` FOREIGN KEY (`reminder_id`) REFERENCES `re_reminders` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `re_in_app_notifications` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `company_id` INT(11) NOT NULL,
  `user_id` INT(11) NOT NULL,
  `title` VARCHAR(255) NOT NULL,
  `body` TEXT DEFAULT NULL,
  `related_type` VARCHAR(50) DEFAULT NULL,
  `related_id` INT(11) DEFAULT NULL,
  `is_read` TINYINT(1) NOT NULL DEFAULT 0,
  `read_at` DATETIME DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_user_read_created` (`user_id`, `is_read`, `created_at`),
  KEY `idx_company` (`company_id`),
  CONSTRAINT `fk_re_in_app_notifications_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_re_in_app_notifications_user` FOREIGN KEY (`user_id`) REFERENCES `user` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
