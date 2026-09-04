-- 2025-11-11: Employee recognition tables for awards, checklists, and kudos

CREATE TABLE IF NOT EXISTS `employee_awards` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `award_type` VARCHAR(50) NOT NULL DEFAULT 'employee_of_month',
  `employee_id` INT NOT NULL,
  `award_month` DATE NOT NULL,
  `headline` VARCHAR(255) DEFAULT NULL,
  `message` TEXT,
  `target_hours` DECIMAL(8,2) DEFAULT NULL,
  `progress_hours` DECIMAL(8,2) DEFAULT NULL,
  `created_by` INT DEFAULT NULL,
  `updated_by` INT DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_award_type_month` (`award_type`, `award_month`),
  KEY `idx_employee_awards_employee` (`employee_id`),
  CONSTRAINT `fk_employee_awards_employee`
    FOREIGN KEY (`employee_id`) REFERENCES `employees`(`id`)
    ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_employee_awards_created_by`
    FOREIGN KEY (`created_by`) REFERENCES `user`(`id`)
    ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_employee_awards_updated_by`
    FOREIGN KEY (`updated_by`) REFERENCES `user`(`id`)
    ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `employee_award_checklists` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `award_id` INT NOT NULL,
  `item_label` VARCHAR(255) NOT NULL,
  `is_done` TINYINT(1) NOT NULL DEFAULT 0,
  `updated_by` INT DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_award_checklist_award` (`award_id`),
  CONSTRAINT `fk_award_checklist_award`
    FOREIGN KEY (`award_id`) REFERENCES `employee_awards`(`id`)
    ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_award_checklist_updated_by`
    FOREIGN KEY (`updated_by`) REFERENCES `user`(`id`)
    ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `employee_kudos` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `employee_id` INT NOT NULL,
  `author_id` INT DEFAULT NULL,
  `message` TEXT NOT NULL,
  `visibility` ENUM('public','private') NOT NULL DEFAULT 'public',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_employee_kudos_employee` (`employee_id`),
  KEY `idx_employee_kudos_author` (`author_id`),
  CONSTRAINT `fk_employee_kudos_employee`
    FOREIGN KEY (`employee_id`) REFERENCES `employees`(`id`)
    ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_employee_kudos_author`
    FOREIGN KEY (`author_id`) REFERENCES `user`(`id`)
    ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

