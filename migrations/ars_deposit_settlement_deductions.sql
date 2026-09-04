-- ARS deposit settlement with deductions (BR-ARS-OPS-002) — additive only.
-- Prefer runtime ars_deposit_ensure_schema(); this file is for explicit migration runs.

ALTER TABLE ars_bookings
  ADD COLUMN deposit_forfeited_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00
    COMMENT 'Cumulative deposit forfeited to income (no VAT)' AFTER deposit_refunded_amount;

ALTER TABLE ars_bookings
  ADD COLUMN deposit_settlement_journal_id INT NULL DEFAULT NULL
    COMMENT 'Last combined deposit settlement journal id' AFTER deposit_refund_journal_id;

ALTER TABLE ars_bookings
  MODIFY deposit_status ENUM('none','pending','received','partially_refunded','refunded','forfeited')
  NOT NULL DEFAULT 'none';
