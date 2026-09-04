-- Phase 2: Purchasing & receiving (PO + GRN). Links to re_vendors and inventory receipt posting.
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS inv_purchase_orders (
  id INT AUTO_INCREMENT PRIMARY KEY,
  company_id INT NOT NULL,
  vendor_id INT NOT NULL,
  po_no VARCHAR(50) NOT NULL,
  po_date DATE NOT NULL,
  expected_date DATE NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'draft',
  notes TEXT NULL,
  created_by INT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL,
  UNIQUE KEY uq_inv_po_company_no (company_id, po_no),
  KEY idx_inv_po_vendor (company_id, vendor_id),
  KEY idx_inv_po_status (company_id, status),
  KEY idx_inv_po_date (company_id, po_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS inv_purchase_order_lines (
  id INT AUTO_INCREMENT PRIMARY KEY,
  header_id INT NOT NULL,
  company_id INT NOT NULL,
  item_id INT NOT NULL,
  uom_id INT NOT NULL,
  qty_ordered DECIMAL(18,4) NOT NULL,
  unit_price DECIMAL(18,4) NOT NULL DEFAULT 0,
  qty_received DECIMAL(18,4) NOT NULL DEFAULT 0,
  sort_order INT NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_inv_pol_header (header_id),
  KEY idx_inv_pol_item (company_id, item_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS inv_goods_receipts (
  id INT AUTO_INCREMENT PRIMARY KEY,
  company_id INT NOT NULL,
  vendor_id INT NOT NULL,
  po_id INT NULL,
  grn_no VARCHAR(50) NOT NULL,
  reference_no VARCHAR(120) NULL COMMENT 'Supplier delivery note / invoice ref',
  receipt_date DATE NOT NULL,
  location_to_id INT NOT NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'draft',
  allow_over_receipt TINYINT(1) NOT NULL DEFAULT 0,
  inventory_doc_id INT NULL COMMENT 'Posted inv_doc_headers id (receipt)',
  notes TEXT NULL,
  posted_by INT NULL,
  posted_at DATETIME NULL,
  created_by INT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL,
  UNIQUE KEY uq_inv_grn_company_no (company_id, grn_no),
  KEY idx_inv_grn_company_vendor (company_id, vendor_id),
  KEY idx_inv_grn_po (company_id, po_id),
  KEY idx_inv_grn_date (company_id, receipt_date),
  KEY idx_inv_grn_status (company_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS inv_goods_receipt_lines (
  id INT AUTO_INCREMENT PRIMARY KEY,
  header_id INT NOT NULL,
  company_id INT NOT NULL,
  po_line_id INT NULL,
  item_id INT NOT NULL,
  uom_id INT NOT NULL,
  qty_received DECIMAL(18,4) NOT NULL,
  unit_cost DECIMAL(18,4) NOT NULL,
  lot_number VARCHAR(80) NULL,
  expiry_date DATE NULL,
  serial_number VARCHAR(120) NULL,
  location_to_id INT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_inv_grnl_header (header_id),
  KEY idx_inv_grnl_po_line (po_line_id),
  KEY idx_inv_grnl_item (company_id, item_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
