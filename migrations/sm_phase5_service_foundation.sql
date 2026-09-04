-- Service Management Phase 5 — service category foundation (Cleaning v1 default).
-- Safe to re-run: uses IF NOT EXISTS / information_schema checks via sm_apply_phase5_schema.php.

CREATE TABLE IF NOT EXISTS sm_service_categories (
  id INT NOT NULL AUTO_INCREMENT,
  company_id INT NOT NULL,
  code VARCHAR(32) NOT NULL,
  name VARCHAR(100) NOT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  icon VARCHAR(50) NULL,
  sort_order INT NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_sm_svc_cat_company_code (company_id, code),
  KEY idx_sm_svc_cat_company_active (company_id, is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS sm_field_definitions (
  id INT NOT NULL AUTO_INCREMENT,
  service_category_id INT NOT NULL,
  field_key VARCHAR(64) NOT NULL,
  field_label VARCHAR(128) NOT NULL,
  field_type ENUM('text','number','date','select','boolean','textarea') NOT NULL DEFAULT 'text',
  options_json JSON NULL,
  is_required TINYINT(1) NOT NULL DEFAULT 0,
  show_on_booking TINYINT(1) NOT NULL DEFAULT 1,
  sort_order INT NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_sm_field_cat_key (service_category_id, field_key),
  KEY idx_sm_field_cat (service_category_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
