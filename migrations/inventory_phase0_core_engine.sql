-- Inventory Engine (Shared) - Phase 0 Core
-- One inventory backbone for all companies and modules.
-- Costing method (MVP): Weighted Average Cost (company-wide per item).
--
-- Notes:
-- - All tables are company_id scoped.
-- - Stock changes only via inv_stock_moves (append-only) created when documents are posted.
-- - inv_onhand is a cache updated during posting (for fast queries).

SET NAMES utf8mb4;

-- ----------------------------
-- Master data
-- ----------------------------

CREATE TABLE IF NOT EXISTS inv_uoms (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(50) NOT NULL,
  code VARCHAR(20) NOT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_inv_uoms_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS inv_item_categories (
  id INT AUTO_INCREMENT PRIMARY KEY,
  company_id INT NOT NULL,
  parent_id INT NULL,
  name VARCHAR(120) NOT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_inv_item_categories_company_name (company_id, name),
  KEY idx_inv_item_categories_company_parent (company_id, parent_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS inv_locations (
  id INT AUTO_INCREMENT PRIMARY KEY,
  company_id INT NOT NULL,
  code VARCHAR(30) NOT NULL,
  name VARCHAR(120) NOT NULL,
  location_type VARCHAR(30) NOT NULL DEFAULT 'warehouse',
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_inv_locations_company_code (company_id, code),
  KEY idx_inv_locations_company (company_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS inv_items (
  id INT AUTO_INCREMENT PRIMARY KEY,
  company_id INT NOT NULL,
  item_code VARCHAR(50) NOT NULL,
  barcode VARCHAR(80) NULL,
  name VARCHAR(200) NOT NULL,
  category_id INT NULL,
  item_type VARCHAR(30) NOT NULL DEFAULT 'stock_item',
  base_uom_id INT NOT NULL,
  tax_code_id INT NULL,
  reorder_level DECIMAL(18,4) NOT NULL DEFAULT 0,
  default_purchase_price DECIMAL(18,4) NOT NULL DEFAULT 0,
  sale_price DECIMAL(18,4) NOT NULL DEFAULT 0,
  track_lot TINYINT(1) NOT NULL DEFAULT 0,
  track_expiry TINYINT(1) NOT NULL DEFAULT 0,
  track_serial TINYINT(1) NOT NULL DEFAULT 0,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL,
  UNIQUE KEY uq_inv_items_company_item_code (company_id, item_code),
  UNIQUE KEY uq_inv_items_company_barcode (company_id, barcode),
  KEY idx_inv_items_company_active (company_id, is_active),
  KEY idx_inv_items_company_category (company_id, category_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS inv_uom_conversions (
  id INT AUTO_INCREMENT PRIMARY KEY,
  company_id INT NOT NULL,
  item_id INT NULL,
  from_uom_id INT NOT NULL,
  to_uom_id INT NOT NULL,
  factor DECIMAL(18,8) NOT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_inv_uom_conv (company_id, item_id, from_uom_id, to_uom_id),
  KEY idx_inv_uom_conv_company (company_id),
  KEY idx_inv_uom_conv_item (company_id, item_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ----------------------------
-- Lot / serial tracking
-- ----------------------------

CREATE TABLE IF NOT EXISTS inv_lots (
  id INT AUTO_INCREMENT PRIMARY KEY,
  company_id INT NOT NULL,
  item_id INT NOT NULL,
  lot_number VARCHAR(80) NOT NULL,
  expiry_date DATE NULL,
  meta_json JSON NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_inv_lots_company_item_lot (company_id, item_id, lot_number),
  KEY idx_inv_lots_company_item (company_id, item_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS inv_serials (
  id INT AUTO_INCREMENT PRIMARY KEY,
  company_id INT NOT NULL,
  item_id INT NOT NULL,
  serial_number VARCHAR(120) NOT NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'in_stock',
  location_id INT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_inv_serials_company_serial (company_id, serial_number),
  KEY idx_inv_serials_company_item (company_id, item_id),
  KEY idx_inv_serials_company_status (company_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ----------------------------
-- Inventory documents (headers + lines)
-- ----------------------------

CREATE TABLE IF NOT EXISTS inv_doc_headers (
  id INT AUTO_INCREMENT PRIMARY KEY,
  company_id INT NOT NULL,
  doc_type VARCHAR(30) NOT NULL,
  doc_no VARCHAR(50) NOT NULL,
  doc_date DATE NOT NULL,
  status VARCHAR(15) NOT NULL DEFAULT 'draft',
  location_from_id INT NULL,
  location_to_id INT NULL,
  vendor_id INT NULL,
  customer_id INT NULL,
  source_module VARCHAR(40) NULL,
  source_table VARCHAR(80) NULL,
  source_id INT NULL,
  notes TEXT NULL,
  created_by INT NULL,
  posted_by INT NULL,
  posted_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL,
  UNIQUE KEY uq_inv_doc_headers_company_docno (company_id, doc_no),
  KEY idx_inv_doc_headers_company_date (company_id, doc_date),
  KEY idx_inv_doc_headers_company_type (company_id, doc_type),
  KEY idx_inv_doc_headers_company_status (company_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS inv_doc_lines (
  id INT AUTO_INCREMENT PRIMARY KEY,
  header_id INT NOT NULL,
  company_id INT NOT NULL,
  item_id INT NOT NULL,
  uom_id INT NOT NULL,
  qty DECIMAL(18,4) NOT NULL,
  unit_cost DECIMAL(18,4) NULL,
  unit_price DECIMAL(18,4) NULL,
  lot_id INT NULL,
  serial_id INT NULL,
  lot_number VARCHAR(80) NULL,
  serial_number VARCHAR(120) NULL,
  expiry_date DATE NULL,
  location_from_id INT NULL,
  location_to_id INT NULL,
  source_table VARCHAR(80) NULL,
  source_line_id INT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_inv_doc_lines_header (header_id),
  KEY idx_inv_doc_lines_company_item (company_id, item_id),
  KEY idx_inv_doc_lines_company_lot (company_id, lot_id),
  KEY idx_inv_doc_lines_company_serial (company_id, serial_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ----------------------------
-- Stock movement ledger (append-only)
-- ----------------------------

CREATE TABLE IF NOT EXISTS inv_stock_moves (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  company_id INT NOT NULL,
  move_date DATETIME NOT NULL,
  doc_type VARCHAR(30) NOT NULL,
  doc_id INT NOT NULL,
  doc_line_id INT NOT NULL,
  item_id INT NOT NULL,
  location_id INT NOT NULL,
  qty_in DECIMAL(18,4) NOT NULL DEFAULT 0,
  qty_out DECIMAL(18,4) NOT NULL DEFAULT 0,
  base_uom_id INT NOT NULL,
  qty_base DECIMAL(18,4) NOT NULL,
  lot_id INT NOT NULL DEFAULT 0 COMMENT '0 = no lot',
  serial_id INT NULL,
  unit_cost_base DECIMAL(18,4) NOT NULL DEFAULT 0,
  value_in DECIMAL(18,4) NOT NULL DEFAULT 0,
  value_out DECIMAL(18,4) NOT NULL DEFAULT 0,
  wac_before DECIMAL(18,4) NULL,
  wac_after DECIMAL(18,4) NULL,
  source_module VARCHAR(40) NULL,
  source_table VARCHAR(80) NULL,
  source_id INT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_inv_moves_company_item_loc_date (company_id, item_id, location_id, move_date),
  KEY idx_inv_moves_company_doc (company_id, doc_type, doc_id),
  KEY idx_inv_moves_company_source (company_id, source_module, source_table, source_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ----------------------------
-- Performance caches
-- ----------------------------

CREATE TABLE IF NOT EXISTS inv_onhand (
  company_id INT NOT NULL,
  item_id INT NOT NULL,
  location_id INT NOT NULL,
  lot_id INT NOT NULL DEFAULT 0 COMMENT '0 = no lot',
  qty_on_hand DECIMAL(18,4) NOT NULL DEFAULT 0,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (company_id, item_id, location_id, lot_id),
  KEY idx_inv_onhand_company_loc (company_id, location_id),
  KEY idx_inv_onhand_company_item (company_id, item_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS inv_item_cost_state (
  company_id INT NOT NULL,
  item_id INT NOT NULL,
  avg_cost DECIMAL(18,4) NOT NULL DEFAULT 0,
  qty_valued DECIMAL(18,4) NOT NULL DEFAULT 0,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (company_id, item_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ----------------------------
-- Document numbering sequences (safe concurrent doc_no generation)
-- ----------------------------

CREATE TABLE IF NOT EXISTS inv_doc_sequences (
  company_id INT NOT NULL,
  doc_prefix VARCHAR(30) NOT NULL,
  next_seq INT NOT NULL DEFAULT 1,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (company_id, doc_prefix)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

