-- Per-register Retail POS profiles: URL code + default selling location (multi-store).
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS inv_pos_retail_profiles (
  id INT NOT NULL AUTO_INCREMENT,
  company_id INT NOT NULL,
  profile_code VARCHAR(32) NOT NULL COMMENT 'URL slug, e.g. store1 — use pos_retail.php?pos=store1',
  label VARCHAR(120) NOT NULL COMMENT 'Display name e.g. Store 1 register',
  default_location_id INT NOT NULL COMMENT 'inv_locations.id — stock sold from this store',
  sort_order INT NOT NULL DEFAULT 0,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uk_pos_profile_company_code (company_id, profile_code),
  KEY idx_pos_profile_company (company_id),
  KEY idx_pos_profile_loc (company_id, default_location_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
