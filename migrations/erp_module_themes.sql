-- ERP module appearance themes (company-scoped).
-- First consumer: Construction (module_key = 'construction').
-- Future modules reuse the same table with their own module_key.
-- theme_json holds CSS token overrides so new colors do not require ALTER TABLE.
--
-- Human approval required before running on production.

CREATE TABLE IF NOT EXISTS erp_module_themes (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  company_id INT NOT NULL,
  module_key VARCHAR(32) NOT NULL COMMENT 'e.g. construction, realestate, cleaning',
  theme_json JSON NOT NULL,
  updated_by INT NULL,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_company_module (company_id, module_key),
  KEY idx_module (module_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
