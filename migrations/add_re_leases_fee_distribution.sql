-- ---------------------------------------------------------------------------
-- Fee Distribution per-lease columns
-- Run ONCE per environment (live + local).
-- Replaces the never-run split_chiller_admin_in_installments column with
-- individual per-fee split flags plus a renewal-lease flag.
-- ---------------------------------------------------------------------------

ALTER TABLE `re_leases`
  ADD COLUMN `is_renewal_lease`        TINYINT(1) NOT NULL DEFAULT 0
      COMMENT '1 = renewal; Security Deposit is NOT collected again',
  ADD COLUMN `split_chiller_fees`      TINYINT(1) NOT NULL DEFAULT 0
      COMMENT '1 = Chiller Fees are split across all installments',
  ADD COLUMN `split_ejari_fees`        TINYINT(1) NOT NULL DEFAULT 0
      COMMENT '1 = Ejari Fees are split across all installments',
  ADD COLUMN `split_admin_fees`        TINYINT(1) NOT NULL DEFAULT 0
      COMMENT '1 = Admin Fees are split across all installments',
  ADD COLUMN `split_commission_fees`   TINYINT(1) NOT NULL DEFAULT 0
      COMMENT '1 = Commission Fees are split across all installments',
  ADD COLUMN `split_additional_parking` TINYINT(1) NOT NULL DEFAULT 0
      COMMENT '1 = Additional Parking annual cost split across all installments',
  ADD COLUMN `split_additional_store`  TINYINT(1) NOT NULL DEFAULT 0
      COMMENT '1 = Additional Store annual cost split across all installments';
