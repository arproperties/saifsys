-- Finance > Lease Posting: a journal voucher raised from a lease remembers the
-- lease, so the same lease is not posted twice while that voucher stands.
-- Run after fin_vouchers.sql. Safe to re-run.
ALTER TABLE `fin_vouchers`
  ADD COLUMN IF NOT EXISTS `lease_id` int(11) DEFAULT NULL AFTER `narration`,
  ADD KEY IF NOT EXISTS `idx_fin_voucher_lease` (`company_id`,`lease_id`);
