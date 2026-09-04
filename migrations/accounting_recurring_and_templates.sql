-- Recurring journals and journal templates (Nice-to-have enhancements)

-- Recurring journal definitions (auto-generate journals by schedule)
CREATE TABLE IF NOT EXISTS `re_recurring_journals` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `company_id` INT(11) NOT NULL,
  `name` VARCHAR(255) NOT NULL,
  `description` VARCHAR(500) DEFAULT NULL,
  `frequency` ENUM('weekly', 'monthly') NOT NULL DEFAULT 'monthly',
  `day_of_month` TINYINT(2) DEFAULT 1 COMMENT '1-28 for monthly',
  `next_run_date` DATE NOT NULL,
  `lines_json` TEXT NOT NULL COMMENT 'JSON array of {account_id, debit, credit, description}',
  `is_active` TINYINT(1) DEFAULT 1,
  `last_generated_at` DATETIME DEFAULT NULL,
  `created_by` INT(11) DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_company` (`company_id`),
  KEY `idx_next_run` (`next_run_date`),
  KEY `idx_active` (`is_active`),
  FOREIGN KEY (`company_id`) REFERENCES `companies`(`id`) ON DELETE RESTRICT,
  FOREIGN KEY (`created_by`) REFERENCES `user`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Journal templates (save/load common manual entry lines)
CREATE TABLE IF NOT EXISTS `re_journal_templates` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `company_id` INT(11) NOT NULL,
  `name` VARCHAR(255) NOT NULL,
  `description` VARCHAR(500) DEFAULT NULL,
  `lines_json` TEXT NOT NULL COMMENT 'JSON array of {account_id, debit, credit, description}',
  `created_by` INT(11) DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_company` (`company_id`),
  FOREIGN KEY (`company_id`) REFERENCES `companies`(`id`) ON DELETE RESTRICT,
  FOREIGN KEY (`created_by`) REFERENCES `user`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
