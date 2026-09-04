-- Enhanced Clients Page Database Migrations
-- Created: 2025-01-14

-- Client enhancements
ALTER TABLE client ADD COLUMN client_status ENUM('active','inactive','vip','at_risk') DEFAULT 'active';
ALTER TABLE client ADD COLUMN last_order_date DATE NULL;
ALTER TABLE client ADD COLUMN last_payment_date DATE NULL;
ALTER TABLE client ADD COLUMN health_score INT DEFAULT 50;
ALTER TABLE client ADD COLUMN preferred_workers TEXT NULL;
ALTER TABLE client ADD COLUMN service_preferences JSON NULL;
ALTER TABLE client ADD COLUMN special_instructions TEXT NULL;

-- Client documents
CREATE TABLE IF NOT EXISTS client_documents (
  id INT PRIMARY KEY AUTO_INCREMENT,
  client_id INT NOT NULL,
  doc_type VARCHAR(50),
  file_name VARCHAR(255),
  file_path VARCHAR(500),
  file_size INT,
  expires_at DATE NULL,
  uploaded_by INT,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (client_id) REFERENCES client(id) ON DELETE CASCADE
);

-- Client notes
CREATE TABLE IF NOT EXISTS client_notes (
  id INT PRIMARY KEY AUTO_INCREMENT,
  client_id INT NOT NULL,
  note_type ENUM('general','important','task') DEFAULT 'general',
  content TEXT,
  due_date DATE NULL,
  priority ENUM('low','medium','high') DEFAULT 'medium',
  completed_at DATETIME NULL,
  assigned_to INT NULL,
  created_by INT,
  updated_by INT NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (client_id) REFERENCES client(id) ON DELETE CASCADE
);

-- Client communication log
CREATE TABLE IF NOT EXISTS client_communications (
  id INT PRIMARY KEY AUTO_INCREMENT,
  client_id INT NOT NULL,
  type ENUM('email','sms','whatsapp','call') NOT NULL,
  direction ENUM('inbound','outbound') DEFAULT 'outbound',
  subject VARCHAR(255),
  body TEXT,
  status ENUM('sent','delivered','failed','opened') DEFAULT 'sent',
  sent_by INT,
  sent_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (client_id) REFERENCES client(id) ON DELETE CASCADE
);

-- Client sites (for multiple locations)
CREATE TABLE IF NOT EXISTS client_sites (
  id INT PRIMARY KEY AUTO_INCREMENT,
  client_id INT NOT NULL,
  site_name VARCHAR(255),
  address VARCHAR(500),
  contact_person VARCHAR(255),
  contact_phone VARCHAR(50),
  notes TEXT,
  is_primary BOOLEAN DEFAULT 0,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (client_id) REFERENCES client(id) ON DELETE CASCADE
);

-- Client service preferences
CREATE TABLE IF NOT EXISTS client_service_preferences (
  id INT PRIMARY KEY AUTO_INCREMENT,
  client_id INT NOT NULL,
  preference_type ENUM('time_slot','worker','frequency','materials','instructions') NOT NULL,
  preference_value TEXT,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (client_id) REFERENCES client(id) ON DELETE CASCADE
);

-- Client quality ratings
CREATE TABLE IF NOT EXISTS client_quality_ratings (
  id INT PRIMARY KEY AUTO_INCREMENT,
  client_id INT NOT NULL,
  order_id INT NULL,
  quality_score INT NOT NULL CHECK (quality_score >= 1 AND quality_score <= 5),
  timeliness_score INT NOT NULL CHECK (timeliness_score >= 1 AND timeliness_score <= 5),
  professionalism_score INT NOT NULL CHECK (professionalism_score >= 1 AND professionalism_score <= 5),
  feedback TEXT,
  rated_by INT,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (client_id) REFERENCES client(id) ON DELETE CASCADE,
  FOREIGN KEY (order_id) REFERENCES make_order(id) ON DELETE SET NULL
);

-- Client tasks/reminders
CREATE TABLE IF NOT EXISTS client_tasks (
  id INT PRIMARY KEY AUTO_INCREMENT,
  client_id INT NOT NULL,
  task_type ENUM('follow_up','call','visit','payment','document','other') NOT NULL,
  title VARCHAR(255) NOT NULL,
  description TEXT,
  due_date DATETIME,
  status ENUM('pending','in_progress','completed','cancelled') DEFAULT 'pending',
  assigned_to INT,
  created_by INT,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  completed_at DATETIME NULL,
  FOREIGN KEY (client_id) REFERENCES client(id) ON DELETE CASCADE
);

-- Create indexes for better performance
CREATE INDEX idx_client_status ON client(client_status);
CREATE INDEX idx_client_last_order ON client(last_order_date);
CREATE INDEX idx_client_last_payment ON client(last_payment_date);
CREATE INDEX idx_client_health_score ON client(health_score);

CREATE INDEX idx_client_docs_client ON client_documents(client_id);
CREATE INDEX idx_client_docs_type ON client_documents(doc_type);
CREATE INDEX idx_client_docs_expires ON client_documents(expires_at);

CREATE INDEX idx_client_notes_client ON client_notes(client_id);
CREATE INDEX idx_client_notes_type ON client_notes(note_type);
CREATE INDEX idx_client_notes_created ON client_notes(created_at);

CREATE INDEX idx_client_comms_client ON client_communications(client_id);
CREATE INDEX idx_client_comms_type ON client_communications(type);
CREATE INDEX idx_client_comms_sent ON client_communications(sent_at);

CREATE INDEX idx_client_sites_client ON client_sites(client_id);
CREATE INDEX idx_client_sites_primary ON client_sites(is_primary);

CREATE INDEX idx_client_prefs_client ON client_service_preferences(client_id);
CREATE INDEX idx_client_prefs_type ON client_service_preferences(preference_type);

CREATE INDEX idx_client_ratings_client ON client_quality_ratings(client_id);
CREATE INDEX idx_client_ratings_order ON client_quality_ratings(order_id);

CREATE INDEX idx_client_tasks_client ON client_tasks(client_id);
CREATE INDEX idx_client_tasks_status ON client_tasks(status);
CREATE INDEX idx_client_tasks_due ON client_tasks(due_date);
CREATE INDEX idx_client_tasks_assigned ON client_tasks(assigned_to);

-- Update existing client data with calculated fields
UPDATE client SET last_order_date = (
  SELECT MAX(mo.service_date) 
  FROM make_order mo 
  WHERE mo.client_id = client.id AND mo.service_date IS NOT NULL
);

UPDATE client SET last_payment_date = (
  SELECT MAX(r.receipt_date)
  FROM receipts r
  WHERE r.client_id = client.id
);

-- Calculate initial health scores based on payment behavior and order frequency
UPDATE client SET health_score = 
  CASE 
    WHEN last_payment_date IS NULL THEN 25
    WHEN last_payment_date < DATE_SUB(CURDATE(), INTERVAL 60 DAY) THEN 30
    WHEN last_payment_date < DATE_SUB(CURDATE(), INTERVAL 30 DAY) THEN 60
    WHEN last_order_date < DATE_SUB(CURDATE(), INTERVAL 90 DAY) THEN 40
    WHEN last_order_date < DATE_SUB(CURDATE(), INTERVAL 30 DAY) THEN 70
    ELSE 80
  END;
