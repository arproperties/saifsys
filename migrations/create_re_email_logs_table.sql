-- ============================================================================
-- Real Estate Email Logs Table
-- ============================================================================
-- Tracks all email notification attempts for debugging and auditing
-- ============================================================================

CREATE TABLE IF NOT EXISTS `re_email_logs` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `company_id` INT(11) NOT NULL,
  `notification_type` VARCHAR(100) NOT NULL,
  `recipient_email` VARCHAR(255) NOT NULL,
  `subject` VARCHAR(500) DEFAULT NULL,
  `status` ENUM('pending', 'sent', 'failed', 'skipped') NOT NULL DEFAULT 'pending',
  `error_message` TEXT DEFAULT NULL,
  `related_id` INT(11) DEFAULT NULL COMMENT 'e.g., maintenance_request_id',
  `related_type` VARCHAR(50) DEFAULT NULL COMMENT 'e.g., maintenance_request',
  `sent_at` DATETIME DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_company` (`company_id`),
  KEY `idx_notification_type` (`notification_type`),
  KEY `idx_status` (`status`),
  KEY `idx_created_at` (`created_at`),
  KEY `idx_related` (`related_type`, `related_id`),
  FOREIGN KEY (`company_id`) REFERENCES `companies`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

