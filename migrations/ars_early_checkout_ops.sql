-- ARS early checkout (operational) — additive only.
-- Run once (or rely on ars_early_checkout_ensure_schema at runtime).
-- Confirmed rule: early checkout does not refund unused stay nights.

ALTER TABLE ars_bookings
  ADD COLUMN actual_check_out DATE NULL COMMENT 'Actual departure date when checked out' AFTER check_out;

ALTER TABLE ars_bookings
  ADD COLUMN is_early_checkout TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1 if actual_check_out < planned check_out' AFTER actual_check_out;
