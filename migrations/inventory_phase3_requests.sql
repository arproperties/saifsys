-- Phase 3: Material requests (approve + issue in one transaction) + operational context on documents.
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS inv_request_headers (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  company_id INT NOT NULL,
  request_no VARCHAR(50) NOT NULL,
  request_date DATE NOT NULL DEFAULT (CURRENT_DATE),
  request_type VARCHAR(20) NOT NULL DEFAULT 'issue',
  source_module VARCHAR(40) NOT NULL,
  source_table VARCHAR(80) NULL,
  source_id INT NULL,
  location_from_id INT NULL COMMENT 'Optional at create; set at approve if omitted',
  status VARCHAR(20) NOT NULL DEFAULT 'pending',
  requested_by INT NULL,
  approved_by INT NULL,
  approved_at DATETIME NULL,
  rejected_by INT NULL,
  rejected_at DATETIME NULL,
  rejection_reason VARCHAR(255) NULL,
  issue_doc_id INT NULL,
  notes TEXT NULL,
  context_building_id INT NULL,
  context_unit_id INT NULL,
  context_project_id INT NULL,
  context_booking_id INT NULL,
  context_work_order_id INT NULL,
  context_housekeeping_id INT NULL,
  context_cleaning_job_id INT NULL,
  context_json JSON NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL,
  UNIQUE KEY uq_inv_req_company_no (company_id, request_no),
  KEY idx_inv_req_company_status (company_id, status),
  KEY idx_inv_req_module (company_id, source_module, created_at),
  KEY idx_inv_req_building (company_id, context_building_id),
  KEY idx_inv_req_unit (company_id, context_unit_id),
  KEY idx_inv_req_project (company_id, context_project_id),
  KEY idx_inv_req_booking (company_id, context_booking_id),
  KEY idx_inv_req_issue_doc (issue_doc_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS inv_request_lines (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  header_id BIGINT NOT NULL,
  company_id INT NOT NULL,
  item_id INT NOT NULL,
  uom_id INT NOT NULL,
  requested_qty DECIMAL(18,4) NOT NULL,
  approved_qty DECIMAL(18,4) NULL,
  lot_number VARCHAR(80) NULL,
  expiry_date DATE NULL,
  serial_number VARCHAR(120) NULL,
  sort_order INT NOT NULL DEFAULT 0,
  line_notes VARCHAR(255) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_inv_req_line_header (header_id),
  KEY idx_inv_req_line_item (company_id, item_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Operational context + emergency manual issue audit on inventory documents
ALTER TABLE inv_doc_headers
  ADD COLUMN context_building_id INT NULL AFTER notes,
  ADD COLUMN context_unit_id INT NULL AFTER context_building_id,
  ADD COLUMN context_project_id INT NULL AFTER context_unit_id,
  ADD COLUMN context_booking_id INT NULL AFTER context_project_id,
  ADD COLUMN context_work_order_id INT NULL AFTER context_booking_id,
  ADD COLUMN context_housekeeping_id INT NULL AFTER context_work_order_id,
  ADD COLUMN context_cleaning_job_id INT NULL AFTER context_housekeeping_id,
  ADD COLUMN is_emergency_issue TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Owner/Admin manual issue only' AFTER context_cleaning_job_id;
