-- ============================================================
-- AMC (Annual Maintenance Contract) Fee Support
-- Adds annual AMC fee field to re_leases with two distribution
-- options:
--   split     → amount is divided evenly across all installments
--   separate  → a dedicated AMC installment is created on the
--               lease start date (outside the normal rent cycle)
-- ============================================================

-- 1. Add AMC columns to re_leases
ALTER TABLE re_leases
    ADD COLUMN IF NOT EXISTS amc_amount DECIMAL(15,2) NOT NULL DEFAULT 0.00
    AFTER commission_fees;

ALTER TABLE re_leases
    ADD COLUMN IF NOT EXISTS split_amc_fees TINYINT(1) NOT NULL DEFAULT 0
    COMMENT '1 = split across installments, 0 = separate dedicated installment'
    AFTER amc_amount;

-- 2. Add installment_type to re_lease_installments for clear categorisation
--    Values: rent (default) | fees (deposit/admin) | amc (AMC fee)
ALTER TABLE re_lease_installments
    ADD COLUMN IF NOT EXISTS installment_type VARCHAR(20) NOT NULL DEFAULT 'rent'
    COMMENT 'rent | fees | amc'
    AFTER status;
