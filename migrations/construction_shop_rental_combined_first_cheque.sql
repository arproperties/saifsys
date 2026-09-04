-- Opt-in: first rent cheque face includes separate VAT (if any) + tenant commission.
-- Operational layer only — accounting invoices/schedules remain independent (BR-CO-SHOP-007).
-- Run against Madar Al Wadi / construction company DB as needed.

ALTER TABLE co_shop_rental_contracts
  ADD COLUMN combined_first_cheque TINYINT(1) NOT NULL DEFAULT 0
  COMMENT '1 = first rent cheque is combined (1st rent + separate VAT + commission)'
  AFTER deposit_cheque_count;
