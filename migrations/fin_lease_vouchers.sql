-- Finance > Lease Vouchers: lease contract bookings get their own voucher type
-- (lv, numbered LV-YYYY-NNNN) instead of a Journal Voucher. New postings from
-- Lease Posting become Lease Vouchers. Lease Journal Vouchers already posted are
-- not touched here.
-- Run after fin_fixed_assets.sql and fin_lease_posting.sql. Safe to re-run.
ALTER TABLE `fin_vouchers`
  MODIFY `type` enum('jv','pc','pv','rv','si','fj','aj','lv') NOT NULL;
