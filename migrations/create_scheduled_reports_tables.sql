-- Scheduled Reports System
-- This migration adds tables for managing scheduled report generation and delivery

-- Scheduled Reports
CREATE TABLE scheduled_reports (
  id INT PRIMARY KEY AUTO_INCREMENT,
  report_name VARCHAR(100) NOT NULL,
  report_type VARCHAR(50) NOT NULL,
  frequency VARCHAR(20) NOT NULL,
  parameters JSON,
  email_recipients TEXT,
  export_format VARCHAR(10) DEFAULT 'pdf',
  is_active BOOLEAN DEFAULT 1,
  last_run_at TIMESTAMP NULL,
  next_run_at TIMESTAMP NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  created_by INT,
  INDEX idx_active (is_active),
  INDEX idx_next_run (next_run_at),
  INDEX idx_report_type (report_type)
);

-- Scheduled Report Runs (execution history)
CREATE TABLE scheduled_report_runs (
  id INT PRIMARY KEY AUTO_INCREMENT,
  scheduled_report_id INT NOT NULL,
  started_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  completed_at TIMESTAMP NULL,
  status ENUM('running','completed','failed') DEFAULT 'running',
  file_path VARCHAR(500),
  file_size INT,
  recipients_count INT DEFAULT 0,
  error_message TEXT,
  created_by INT,
  INDEX idx_scheduled_report_id (scheduled_report_id),
  INDEX idx_started_at (started_at),
  INDEX idx_status (status)
);

-- Scheduled Report Recipients (individual email tracking)
CREATE TABLE scheduled_report_recipients (
  id INT PRIMARY KEY AUTO_INCREMENT,
  run_id INT NOT NULL,
  email_address VARCHAR(255) NOT NULL,
  sent_at TIMESTAMP NULL,
  status ENUM('pending','sent','failed') DEFAULT 'pending',
  error_message TEXT,
  INDEX idx_run_id (run_id),
  INDEX idx_status (status)
);

-- Insert default scheduled reports
INSERT INTO scheduled_reports (report_name, report_type, frequency, parameters, email_recipients, export_format, is_active, created_by) VALUES
('Monthly AR Summary', 'ar_summary', 'monthly', '{"include_charts": true, "include_aging": true}', 'admin@bmsystem.com', 'pdf', 1, 1),
('Weekly Payment Report', 'payments', 'weekly', '{"date_range": "last_week", "include_trends": true}', 'finance@bmsystem.com', 'excel', 1, 1),
('Quarterly P&L Report', 'pnl', 'quarterly', '{"include_comparison": true, "include_forecast": false}', 'management@bmsystem.com', 'pdf', 1, 1),
('Daily Overdue Invoices', 'overdue_invoices', 'daily', '{"include_client_details": true, "include_contact_info": true}', 'collections@bmsystem.com', 'csv', 1, 1);
