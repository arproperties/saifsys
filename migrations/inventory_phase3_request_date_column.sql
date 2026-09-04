-- Run once if you already applied inventory_phase3_requests.sql before request_date existed.
-- If the column already exists, skip this file (MySQL will error on duplicate column).

ALTER TABLE inv_request_headers
  ADD COLUMN request_date DATE NOT NULL DEFAULT (CURRENT_DATE) AFTER request_no;
