-- Allow material requests without a warehouse at creation; approver chooses at approve & issue.
-- Run once if inv_request_headers.location_from_id is still NOT NULL.

ALTER TABLE inv_request_headers
  MODIFY COLUMN location_from_id INT NULL COMMENT 'Set at approval if omitted at request';
