-- POS Retail: favorites (manual sort), promotional offers (date-bounded VAT-excl price), top-selling support.
SET NAMES utf8mb4;

ALTER TABLE inv_items
  ADD COLUMN is_favorite TINYINT(1) NOT NULL DEFAULT 0,
  ADD COLUMN favorite_sort_order INT NOT NULL DEFAULT 0,
  ADD COLUMN is_offer TINYINT(1) NOT NULL DEFAULT 0,
  ADD COLUMN offer_price DECIMAL(18,4) NULL DEFAULT NULL,
  ADD COLUMN offer_start DATE NULL DEFAULT NULL,
  ADD COLUMN offer_end DATE NULL DEFAULT NULL,
  ADD COLUMN offer_note VARCHAR(255) NULL DEFAULT NULL,
  ADD COLUMN offer_badge VARCHAR(24) NULL DEFAULT NULL COMMENT 'OFFER|DISCOUNT|EXPIRY_SOON';

ALTER TABLE inv_items
  ADD KEY idx_inv_items_company_favorite (company_id, is_favorite, is_active, is_sellable),
  ADD KEY idx_inv_items_company_offer (company_id, is_offer, is_active, is_sellable);

-- Speed up location-scoped top-selling (last 30 days) on posted sales
ALTER TABLE pos_sales
  ADD KEY idx_pos_sales_company_loc_status_time (company_id, location_id, status, posted_at, created_at);
