-- Phase 4 — POS preparation: pricing (VAT), flags, images, POS sales, indexes.
-- sale_price = VAT-exclusive; vat_rate = percentage (e.g. 5.000 = 5%).
SET NAMES utf8mb4;

ALTER TABLE inv_items
  ADD COLUMN vat_rate DECIMAL(6,3) NOT NULL DEFAULT 0 COMMENT 'VAT %; sale_price is ex VAT' AFTER sale_price,
  ADD COLUMN is_sellable TINYINT(1) NOT NULL DEFAULT 1 AFTER is_active,
  ADD COLUMN is_purchasable TINYINT(1) NOT NULL DEFAULT 1,
  ADD COLUMN is_consumable TINYINT(1) NOT NULL DEFAULT 1,
  ADD COLUMN is_service TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'POS: no stock movement',
  ADD KEY idx_inv_items_company_sellable (company_id, is_active, is_sellable),
  ADD KEY idx_inv_items_company_code_name (company_id, item_code, name);

-- Optional (faster search): ALTER TABLE inv_items ADD FULLTEXT INDEX ft_inv_items_name_code (name, item_code);

UPDATE inv_items SET is_service = 1 WHERE LOWER(item_type) = 'service';

CREATE TABLE IF NOT EXISTS inv_item_images (
  id INT AUTO_INCREMENT PRIMARY KEY,
  company_id INT NOT NULL,
  item_id INT NOT NULL,
  path_original VARCHAR(500) NOT NULL,
  path_thumb VARCHAR(500) NOT NULL,
  mime_type VARCHAR(100) NULL,
  sort_order INT NOT NULL DEFAULT 0,
  is_primary TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_inv_item_images_company_item (company_id, item_id),
  KEY idx_inv_item_images_primary (company_id, item_id, is_primary),
  CONSTRAINT fk_inv_item_images_item FOREIGN KEY (item_id) REFERENCES inv_items(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE inv_items
  ADD COLUMN primary_image_id INT NULL COMMENT 'FK inv_item_images.id' AFTER updated_at,
  ADD CONSTRAINT fk_inv_items_primary_image FOREIGN KEY (primary_image_id) REFERENCES inv_item_images(id) ON DELETE SET NULL;

CREATE TABLE IF NOT EXISTS pos_sales (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  company_id INT NOT NULL,
  sale_no VARCHAR(50) NOT NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'draft' COMMENT 'draft|posted|void',
  location_id INT NULL COMMENT 'inv_locations: stock deducted from here for non-service lines',
  subtotal_excl DECIMAL(18,4) NOT NULL DEFAULT 0,
  tax_total DECIMAL(18,4) NOT NULL DEFAULT 0,
  discount_total DECIMAL(18,4) NOT NULL DEFAULT 0,
  grand_total_incl DECIMAL(18,4) NOT NULL DEFAULT 0 COMMENT 'VAT-inclusive total for display',
  inv_doc_id INT NULL COMMENT 'NULL if sale was service-only',
  source_module VARCHAR(40) NULL DEFAULT 'pos_api',
  notes VARCHAR(500) NULL,
  posted_by INT NULL,
  posted_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_pos_sales_company_no (company_id, sale_no),
  KEY idx_pos_sales_company_status (company_id, status, created_at),
  KEY idx_pos_sales_inv_doc (inv_doc_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS pos_sale_lines (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  company_id INT NOT NULL,
  header_id BIGINT NOT NULL,
  line_no INT NOT NULL DEFAULT 0,
  item_id INT NOT NULL,
  uom_id INT NOT NULL,
  qty DECIMAL(18,4) NOT NULL,
  unit_price_excl DECIMAL(18,4) NOT NULL,
  vat_rate DECIMAL(6,3) NOT NULL DEFAULT 0,
  line_net_excl DECIMAL(18,4) NOT NULL,
  line_tax DECIMAL(18,4) NOT NULL,
  line_total_incl DECIMAL(18,4) NOT NULL,
  is_service TINYINT(1) NOT NULL DEFAULT 0,
  inv_doc_line_id INT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_pos_sale_lines_header (header_id),
  KEY idx_pos_sale_lines_company_item (company_id, item_id),
  CONSTRAINT fk_pos_sale_lines_header FOREIGN KEY (header_id) REFERENCES pos_sales(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
