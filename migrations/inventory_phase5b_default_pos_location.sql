-- Default selling location for Retail POS (per company).
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS inv_company_settings (
  company_id INT NOT NULL PRIMARY KEY,
  default_pos_location_id INT NULL COMMENT 'Retail POS: stock deducted from this location when set',
  updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_inv_company_settings_loc (default_pos_location_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
