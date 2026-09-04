-- Add per-fee "separate installment" flags to re_leases
-- These complement the existing split_* boolean columns.
-- split_*=1 → fee is split evenly across all installments
-- sep_*=1   → fee gets its own dedicated separate installment (cheque)
-- both 0    → fee is added to the 1st installment (or combined fees installment)

ALTER TABLE re_leases
    ADD COLUMN IF NOT EXISTS sep_chiller_fees      TINYINT(1) NOT NULL DEFAULT 0 AFTER split_chiller_fees,
    ADD COLUMN IF NOT EXISTS sep_ejari_fees        TINYINT(1) NOT NULL DEFAULT 0 AFTER split_ejari_fees,
    ADD COLUMN IF NOT EXISTS sep_admin_fees        TINYINT(1) NOT NULL DEFAULT 0 AFTER split_admin_fees,
    ADD COLUMN IF NOT EXISTS sep_commission_fees   TINYINT(1) NOT NULL DEFAULT 0 AFTER split_commission_fees,
    ADD COLUMN IF NOT EXISTS sep_amc_fees          TINYINT(1) NOT NULL DEFAULT 1 AFTER split_amc_fees,
    ADD COLUMN IF NOT EXISTS sep_additional_parking TINYINT(1) NOT NULL DEFAULT 0 AFTER split_additional_parking,
    ADD COLUMN IF NOT EXISTS sep_additional_store  TINYINT(1) NOT NULL DEFAULT 0 AFTER split_additional_store;

-- For existing leases where split_amc_fees=0, AMC was always separate.
-- The DEFAULT 1 on sep_amc_fees ensures backward compatibility.
-- If split_amc_fees=1, the sep_amc_fees value is irrelevant (split takes precedence).
