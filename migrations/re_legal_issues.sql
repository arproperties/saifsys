-- ============================================================================
-- Legal Department - Issues register (legal_issues.php) + notification bell
-- An issue = a problem logged against a unit (and the tenant / contract on it),
-- with photo evidence and a status timeline.
-- Idempotent: CREATE TABLE IF NOT EXISTS. Safe to re-run.
-- ============================================================================

CREATE TABLE IF NOT EXISTS `re_legal_issues` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `company_id` INT(11) NOT NULL,
    `issue_number` VARCHAR(30) NOT NULL,
    `title` VARCHAR(255) NOT NULL,
    `issue_type` VARCHAR(40) NOT NULL DEFAULT 'other',
    `priority` ENUM('low','medium','high','urgent') NOT NULL DEFAULT 'medium',
    `status` ENUM('open','in_progress','resolved','closed') NOT NULL DEFAULT 'open',
    `issue_date` DATE NOT NULL,
    `building_id` INT(11) DEFAULT NULL,
    `unit_id` INT(11) DEFAULT NULL,
    `lease_id` INT(11) DEFAULT NULL,
    `tenant_id` INT(11) DEFAULT NULL,
    `description` TEXT DEFAULT NULL,
    `case_id` INT(11) DEFAULT NULL,
    `resolved_at` DATETIME DEFAULT NULL,
    `created_by` INT(11) DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT current_timestamp(),
    `updated_at` DATETIME NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
    `deleted_at` DATETIME DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_legal_issue_number` (`company_id`, `issue_number`),
    KEY `idx_legal_issue_status` (`company_id`, `status`),
    KEY `idx_legal_issue_unit` (`unit_id`),
    KEY `idx_legal_issue_lease` (`lease_id`),
    KEY `idx_legal_issue_tenant` (`tenant_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
  COMMENT='Legal issues logged against a unit / tenant / contract.';

CREATE TABLE IF NOT EXISTS `re_legal_issue_files` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `company_id` INT(11) NOT NULL,
    `issue_id` INT(11) NOT NULL,
    `file_name` VARCHAR(255) NOT NULL,
    `file_path` VARCHAR(500) NOT NULL,
    `mime_type` VARCHAR(100) DEFAULT NULL,
    `file_size` INT(11) DEFAULT NULL,
    `uploaded_by` INT(11) DEFAULT NULL,
    `uploaded_at` DATETIME NOT NULL DEFAULT current_timestamp(),
    PRIMARY KEY (`id`),
    KEY `idx_legal_issue_files` (`company_id`, `issue_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
  COMMENT='Evidence photos / files for legal issues.';

CREATE TABLE IF NOT EXISTS `re_legal_issue_updates` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `company_id` INT(11) NOT NULL,
    `issue_id` INT(11) NOT NULL,
    `kind` ENUM('created','status','note','evidence') NOT NULL DEFAULT 'note',
    `old_status` VARCHAR(20) DEFAULT NULL,
    `new_status` VARCHAR(20) DEFAULT NULL,
    `note` TEXT DEFAULT NULL,
    `created_by` INT(11) DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT current_timestamp(),
    PRIMARY KEY (`id`),
    KEY `idx_legal_issue_updates` (`company_id`, `issue_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
  COMMENT='Status changes and notes on legal issues.';

-- Notification bell: when each user last opened the bell (anything newer shows as unread).
CREATE TABLE IF NOT EXISTS `re_legal_notif_seen` (
    `user_id` INT(11) NOT NULL,
    `company_id` INT(11) NOT NULL,
    `last_seen_at` DATETIME NOT NULL,
    PRIMARY KEY (`user_id`, `company_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
  COMMENT='Legal notification bell - last time each user opened it.';
