-- Email Templates
CREATE TABLE email_templates (
  id INT PRIMARY KEY AUTO_INCREMENT,
  template_name VARCHAR(100) NOT NULL,
  template_type VARCHAR(50),
  subject VARCHAR(255),
  body_html TEXT,
  body_plain TEXT,
  is_active BOOLEAN DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- Email Log
CREATE TABLE email_log (
  id INT PRIMARY KEY AUTO_INCREMENT,
  recipient_email VARCHAR(255),
  subject VARCHAR(255),
  template_id INT,
  entity_type VARCHAR(50),
  entity_id INT,
  status ENUM('pending','sent','failed'),
  sent_at TIMESTAMP NULL,
  error_message TEXT,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  created_by INT
);

-- Insert default email templates
INSERT INTO email_templates (template_name, template_type, subject, body_html, body_plain, is_active) VALUES
('Invoice Default', 'invoice', 'Invoice {{invoice_no}} from {{company_name}}', 
'<html><body><h2>Invoice {{invoice_no}}</h2><p>Dear {{client_name}},</p><p>Please find attached your invoice for {{total}} AED.</p><p>Due Date: {{due_date}}</p><p>Thank you for your business!</p><p>{{company_name}}<br>{{company_address}}<br>{{company_phone}}</p></body></html>',
'Invoice {{invoice_no}}\n\nDear {{client_name}},\n\nPlease find attached your invoice for {{total}} AED.\nDue Date: {{due_date}}\n\nThank you for your business!\n\n{{company_name}}\n{{company_address}}\n{{company_phone}}', 1),

('Statement Default', 'statement', 'Account Statement from {{company_name}}', 
'<html><body><h2>Account Statement</h2><p>Dear {{client_name}},</p><p>Please find attached your account statement for the period {{from_date}} to {{to_date}}.</p><p>Current Balance: {{current_balance}} AED</p><p>Thank you for your business!</p><p>{{company_name}}<br>{{company_address}}<br>{{company_phone}}</p></body></html>',
'Account Statement\n\nDear {{client_name}},\n\nPlease find attached your account statement for the period {{from_date}} to {{to_date}}.\nCurrent Balance: {{current_balance}} AED\n\nThank you for your business!\n\n{{company_name}}\n{{company_address}}\n{{company_phone}}', 1),

('Payment Receipt', 'receipt', 'Payment Receipt {{receipt_no}} from {{company_name}}', 
'<html><body><h2>Payment Receipt {{receipt_no}}</h2><p>Dear {{client_name}},</p><p>Thank you for your payment of {{amount}} AED received on {{payment_date}}.</p><p>Payment Method: {{payment_method}}</p><p>Thank you for your business!</p><p>{{company_name}}<br>{{company_address}}<br>{{company_phone}}</p></body></html>',
'Payment Receipt {{receipt_no}}\n\nDear {{client_name}},\n\nThank you for your payment of {{amount}} AED received on {{payment_date}}.\nPayment Method: {{payment_method}}\n\nThank you for your business!\n\n{{company_name}}\n{{company_address}}\n{{company_phone}}', 1);
