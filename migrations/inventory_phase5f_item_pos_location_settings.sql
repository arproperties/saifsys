-- Per-store Retail POS overrides for favorites & offers (full override when row exists; else item master).
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS inv_item_pos_location_settings (
  id INT AUTO_INCREMENT PRIMARY KEY,
  company_id INT NOT NULL,
  item_id INT NOT NULL,
  location_id INT NOT NULL,
  is_favorite TINYINT(1) NOT NULL DEFAULT 0,
  favorite_sort_order INT NOT NULL DEFAULT 0,
  is_offer TINYINT(1) NOT NULL DEFAULT 0,
  offer_price DECIMAL(18,4) NULL DEFAULT NULL,
  offer_start DATE NULL DEFAULT NULL,
  offer_end DATE NULL DEFAULT NULL,
  offer_note VARCHAR(255) NULL DEFAULT NULL,
  offer_badge VARCHAR(24) NULL DEFAULT NULL COMMENT 'OFFER|DISCOUNT|EXPIRY_SOON',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL DEFAULT NULL,
  UNIQUE KEY uq_inv_item_pos_loc (company_id, item_id, location_id),
  KEY idx_inv_item_pos_loc_lookup (company_id, location_id, item_id),
  KEY idx_inv_item_pos_li_company_fav (company_id, location_id, is_favorite),
  KEY idx_inv_item_pos_loc_company_offer (company_id, location_id, is_offer)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
