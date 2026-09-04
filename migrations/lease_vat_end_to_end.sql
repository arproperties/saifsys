-- Lease VAT (end-to-end): rent + extra charges, stored on lease & workflow, installments, recognition split.
-- Run on each environment after backup.

-- ---------------------------------------------------------------------------
-- re_leases
-- ---------------------------------------------------------------------------
ALTER TABLE re_leases
  ADD COLUMN IF NOT EXISTS vat_applicable_on_rent TINYINT(1) NOT NULL DEFAULT 0
    COMMENT '1 = VAT on annual rent (typ. commercial/shop)',
  ADD COLUMN IF NOT EXISTS vat_applicable_on_extra_charges TINYINT(1) NOT NULL DEFAULT 1
    COMMENT '1 = VAT on admin/chiller/parking/store bundle (matches renewal vat_enabled)',
  ADD COLUMN IF NOT EXISTS lease_vat_rate DECIMAL(5,2) NOT NULL DEFAULT 5.00
    COMMENT 'VAT % applied when calculating rent/extra VAT amounts',
  ADD COLUMN IF NOT EXISTS rent_vat_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00
    COMMENT 'Annual VAT on rent (excl. fees)',
  ADD COLUMN IF NOT EXISTS extra_services_vat_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00
    COMMENT 'Annual VAT on extra charges (admin, chiller, parking, stores)',
  ADD COLUMN IF NOT EXISTS total_vat_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  ADD COLUMN IF NOT EXISTS vat_distribution_type ENUM('first_installment','split_installments','separate_payment')
    NOT NULL DEFAULT 'first_installment',
  ADD COLUMN IF NOT EXISTS vat_notes TEXT NULL;

-- ---------------------------------------------------------------------------
-- re_lease_renewal_workflows
-- ---------------------------------------------------------------------------
ALTER TABLE re_lease_renewal_workflows
  ADD COLUMN IF NOT EXISTS vat_applicable_on_rent TINYINT(1) NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS rent_vat_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  ADD COLUMN IF NOT EXISTS vat_distribution_type ENUM('first_installment','split_installments','separate_payment')
    NOT NULL DEFAULT 'first_installment',
  ADD COLUMN IF NOT EXISTS vat_notes TEXT NULL;

-- ---------------------------------------------------------------------------
-- re_lease_installments — VAT component for deferred revenue recognition split
-- ---------------------------------------------------------------------------
ALTER TABLE re_lease_installments
  ADD COLUMN IF NOT EXISTS vat_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00
    COMMENT 'Output VAT included in this installment amount (for 2310 split on recognition)';
