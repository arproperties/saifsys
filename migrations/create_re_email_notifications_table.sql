-- ============================================================================
-- Real Estate Email Notifications Settings Table
-- ============================================================================
-- Stores email addresses for different Real Estate notification types
-- ============================================================================

CREATE TABLE IF NOT EXISTS `re_email_notifications` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `company_id` INT(11) NOT NULL,
  `notification_type` VARCHAR(100) NOT NULL COMMENT 'e.g., maintenance_request, lease_expiry, payment_overdue',
  `recipient_email` VARCHAR(255) NOT NULL COMMENT 'Email address to receive notifications',
  `is_enabled` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_company_type_email` (`company_id`, `notification_type`, `recipient_email`),
  KEY `idx_company` (`company_id`),
  KEY `idx_type` (`notification_type`),
  KEY `idx_enabled` (`is_enabled`),
  FOREIGN KEY (`company_id`) REFERENCES `companies`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Insert default notification types for reference
-- These are just examples, actual data will be inserted via UI
-- INSERT INTO re_email_notifications (company_id, notification_type, recipient_email) VALUES
-- (1, 'maintenance_request', 'maintenance@example.com'),
-- (1, 'lease_expiry', 'leasing@example.com'),
-- (1, 'payment_overdue', 'finance@example.com');

