-- Credit Notes and Allocations System
-- This migration adds tables for managing credit notes, allocations, and refunds

-- Credit Notes
CREATE TABLE credit_notes (
  id INT PRIMARY KEY AUTO_INCREMENT,
  credit_note_number VARCHAR(50) UNIQUE NOT NULL,
  client_id INT NOT NULL,
  invoice_id INT NULL, -- NULL for standalone credit notes
  credit_note_date DATE NOT NULL,
  reference VARCHAR(100),
  reason ENUM('return','discount','error','adjustment','other') NOT NULL,
  reason_description TEXT,
  subtotal DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  tax_amount DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  total_amount DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  status ENUM('draft','issued','allocated','refunded','void') DEFAULT 'draft',
  notes TEXT,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  created_by INT NOT NULL,
  issued_at TIMESTAMP NULL,
  voided_at TIMESTAMP NULL,
  voided_by INT NULL,
  void_reason TEXT,
  INDEX idx_client_id (client_id),
  INDEX idx_invoice_id (invoice_id),
  INDEX idx_credit_note_number (credit_note_number),
  INDEX idx_status (status),
  INDEX idx_credit_note_date (credit_note_date),
  INDEX idx_created_at (created_at)
);

-- Credit Note Items
CREATE TABLE credit_note_items (
  id INT PRIMARY KEY AUTO_INCREMENT,
  credit_note_id INT NOT NULL,
  description VARCHAR(255) NOT NULL,
  quantity DECIMAL(10,3) NOT NULL DEFAULT 1.000,
  unit_price DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  line_total DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  tax_rate DECIMAL(5,2) DEFAULT 0.00,
  tax_amount DECIMAL(15,2) DEFAULT 0.00,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_credit_note_id (credit_note_id)
);

-- Credit Note Allocations (how credit is applied to invoices)
CREATE TABLE credit_note_allocations (
  id INT PRIMARY KEY AUTO_INCREMENT,
  credit_note_id INT NOT NULL,
  invoice_id INT NOT NULL,
  allocated_amount DECIMAL(15,2) NOT NULL,
  allocation_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  created_by INT NOT NULL,
  notes TEXT,
  INDEX idx_credit_note_id (credit_note_id),
  INDEX idx_invoice_id (invoice_id),
  INDEX idx_allocation_date (allocation_date)
);

-- Refunds (cash/check refunds for credit notes)
CREATE TABLE refunds (
  id INT PRIMARY KEY AUTO_INCREMENT,
  credit_note_id INT NOT NULL,
  refund_number VARCHAR(50) UNIQUE NOT NULL,
  refund_date DATE NOT NULL,
  refund_amount DECIMAL(15,2) NOT NULL,
  refund_method ENUM('cash','check','bank_transfer','other') NOT NULL,
  refund_reference VARCHAR(100),
  status ENUM('pending','processed','cancelled') DEFAULT 'pending',
  processed_at TIMESTAMP NULL,
  processed_by INT NULL,
  notes TEXT,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  created_by INT NOT NULL,
  INDEX idx_credit_note_id (credit_note_id),
  INDEX idx_refund_number (refund_number),
  INDEX idx_refund_date (refund_date),
  INDEX idx_status (status)
);

-- Credit Note GL Postings (for audit trail)
CREATE TABLE credit_note_gl_postings (
  id INT PRIMARY KEY AUTO_INCREMENT,
  credit_note_id INT NOT NULL,
  gl_account_id INT NOT NULL,
  debit_amount DECIMAL(15,2) DEFAULT 0.00,
  credit_amount DECIMAL(15,2) DEFAULT 0.00,
  description VARCHAR(255),
  posting_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  created_by INT NOT NULL,
  INDEX idx_credit_note_id (credit_note_id),
  INDEX idx_gl_account_id (gl_account_id),
  INDEX idx_posting_date (posting_date)
);

-- Insert default GL accounts for credit notes (if they don't exist)
INSERT IGNORE INTO chart_of_accounts (account_no, name, type, normal_balance, is_active, is_header) VALUES
('CN-001', 'Credit Notes Receivable', 'Asset', 'debit', 1, 0),
('CN-002', 'Credit Notes Payable', 'Liability', 'credit', 1, 0),
('CN-003', 'Refunds Payable', 'Liability', 'credit', 1, 0);

-- Create storage directory for credit note PDFs
-- Note: This will be created via PHP mkdir() in the application
