-- Unified Renewal Notice Settings + Workflow fields
-- Run after lease_renewal_automation_schema.sql

CREATE TABLE IF NOT EXISTS re_renewal_notice_building_settings (
  id INT(11) NOT NULL AUTO_INCREMENT,
  company_id INT(11) NOT NULL,
  building_id INT(11) NOT NULL,
  template_code VARCHAR(100) NOT NULL DEFAULT 'renewal_notice_unified_v1',
  show_chiller_row TINYINT(1) NOT NULL DEFAULT 0,
  show_chiller_term TINYINT(1) NOT NULL DEFAULT 0,
  admin_fee_label VARCHAR(120) NOT NULL DEFAULT 'Lease Renewal Charges',
  default_parking_fee DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  default_small_store_fee DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  default_big_store_fee DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  vat_enabled TINYINT(1) NOT NULL DEFAULT 1,
  vat_rate DECIMAL(5,2) NOT NULL DEFAULT 5.00,
  rera_charges DECIMAL(12,2) NOT NULL DEFAULT 300.00,
  community_name VARCHAR(120) DEFAULT 'Jumeirah Village Circle',
  location_name VARCHAR(160) DEFAULT 'Al Barsha South Fourth, Dubai',
  terms_text MEDIUMTEXT DEFAULT NULL,
  required_documents_text MEDIUMTEXT DEFAULT NULL,
  show_grand_total_row TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY ux_company_building (company_id, building_id),
  KEY idx_building (building_id),
  KEY idx_company (company_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

ALTER TABLE re_lease_renewal_workflows
  ADD COLUMN IF NOT EXISTS building_id INT(11) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS template_code VARCHAR(100) DEFAULT 'renewal_notice_unified_v1',
  ADD COLUMN IF NOT EXISTS show_chiller_row TINYINT(1) NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS show_chiller_term TINYINT(1) NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS admin_fee_label VARCHAR(120) DEFAULT 'Lease Renewal Charges',
  ADD COLUMN IF NOT EXISTS vat_enabled TINYINT(1) NOT NULL DEFAULT 1,
  ADD COLUMN IF NOT EXISTS vat_rate DECIMAL(5,2) NOT NULL DEFAULT 5.00,
  ADD COLUMN IF NOT EXISTS additional_parking_enabled TINYINT(1) NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS additional_parking_fee DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  ADD COLUMN IF NOT EXISTS small_store_enabled TINYINT(1) NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS small_store_fee DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  ADD COLUMN IF NOT EXISTS big_store_enabled TINYINT(1) NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS big_store_fee DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  ADD COLUMN IF NOT EXISTS number_of_cheques INT(11) NOT NULL DEFAULT 4,
  ADD COLUMN IF NOT EXISTS terms_text MEDIUMTEXT DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS required_documents_text MEDIUMTEXT DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS stamp_image_path VARCHAR(500) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS show_grand_total_row TINYINT(1) NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS grand_total_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00;

