-- Inventory Phase 1: alternate barcodes (grocery POS–ready), optional UoM link.
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS inv_item_barcodes (
  id INT AUTO_INCREMENT PRIMARY KEY,
  company_id INT NOT NULL,
  item_id INT NOT NULL,
  barcode VARCHAR(80) NOT NULL,
  uom_id INT NULL,
  is_primary TINYINT(1) NOT NULL DEFAULT 0,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_inv_item_barcodes_company_barcode (company_id, barcode),
  KEY idx_inv_item_barcodes_company_item (company_id, item_id),
  KEY idx_inv_item_barcodes_uom (company_id, uom_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
