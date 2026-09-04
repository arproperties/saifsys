-- Email Queue Management Tables
-- This migration adds tables for managing bulk email operations with queue processing

-- Email Queue
CREATE TABLE email_queue (
  id INT PRIMARY KEY AUTO_INCREMENT,
  queue_name VARCHAR(100) NOT NULL,
  entity_type VARCHAR(50) NOT NULL,
  entity_ids JSON NOT NULL,
  template_id INT,
  recipient_emails JSON,
  custom_message TEXT,
  status ENUM('pending','processing','completed','failed','cancelled') DEFAULT 'pending',
  total_items INT NOT NULL DEFAULT 0,
  processed_items INT NOT NULL DEFAULT 0,
  failed_items INT NOT NULL DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  started_at TIMESTAMP NULL,
  completed_at TIMESTAMP NULL,
  created_by INT NOT NULL,
  error_message TEXT,
  INDEX idx_status (status),
  INDEX idx_created_at (created_at),
  INDEX idx_entity_type (entity_type)
);

-- Email Queue Items (individual email tracking)
CREATE TABLE email_queue_items (
  id INT PRIMARY KEY AUTO_INCREMENT,
  queue_id INT NOT NULL,
  entity_id INT NOT NULL,
  recipient_email VARCHAR(255) NOT NULL,
  status ENUM('pending','sent','failed','skipped') DEFAULT 'pending',
  sent_at TIMESTAMP NULL,
  error_message TEXT,
  retry_count INT DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_queue_id (queue_id),
  INDEX idx_status (status),
  INDEX idx_entity_id (entity_id)
);

-- Email Queue Settings (for configuration)
CREATE TABLE email_queue_settings (
  id INT PRIMARY KEY AUTO_INCREMENT,
  setting_key VARCHAR(100) UNIQUE NOT NULL,
  setting_value TEXT,
  description TEXT,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- Insert default settings
INSERT INTO email_queue_settings (setting_key, setting_value, description) VALUES
('max_batch_size', '50', 'Maximum number of emails to process in one batch'),
('retry_attempts', '3', 'Number of retry attempts for failed emails'),
('retry_delay_minutes', '5', 'Delay in minutes between retry attempts'),
('queue_processing_enabled', '1', 'Whether queue processing is enabled (1=yes, 0=no)'),
('email_rate_limit', '100', 'Maximum emails per hour'),
('cleanup_days', '30', 'Days to keep completed queue records');
