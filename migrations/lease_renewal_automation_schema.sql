-- Lease Renewal Automation: schema upgrades
-- Adds draft-lease linkage (parent_lease_id) and enriches renewal workflow fields
-- plus rent-change audit history.

-- Link draft/renewed lease back to the original lease
ALTER TABLE re_leases
  ADD COLUMN IF NOT EXISTS parent_lease_id INT(11) DEFAULT NULL
  COMMENT 'Original lease this renewal was created from';

-- Extend renewal workflow with automation fields
ALTER TABLE re_lease_renewal_workflows
  ADD COLUMN IF NOT EXISTS status ENUM('initiated','notice_sent','pending_response','negotiation','approved','rejected','converted')
    NOT NULL DEFAULT 'initiated',

  -- Denormalized convenience fields (optional but required by spec)
  ADD COLUMN IF NOT EXISTS tenant_id INT(11) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS unit_id INT(11) DEFAULT NULL,

  -- Rent calculation fields
  ADD COLUMN IF NOT EXISTS current_rent DECIMAL(12,2) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS increase_type ENUM('percentage','fixed') NOT NULL DEFAULT 'percentage',
  ADD COLUMN IF NOT EXISTS increase_value DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  ADD COLUMN IF NOT EXISTS calculated_new_rent DECIMAL(12,2) DEFAULT NULL,

  -- Extra charges (Park Place / fees) + VAT on extras (excluding rent + ejari)
  ADD COLUMN IF NOT EXISTS chiller_charges DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  ADD COLUMN IF NOT EXISTS admin_fees DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  ADD COLUMN IF NOT EXISTS vat_extra_charges DECIMAL(12,2) NOT NULL DEFAULT 0.00,

  -- Audit & timestamps
  ADD COLUMN IF NOT EXISTS created_by INT(11) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS notice_sent_at DATETIME DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS notice_sent_by INT(11) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS approved_by INT(11) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS rejected_by INT(11) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS converted_by INT(11) DEFAULT NULL,

  -- PDF tracking
  ADD COLUMN IF NOT EXISTS renewal_notice_pdf_path VARCHAR(500) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS renewal_notice_generated_at DATETIME DEFAULT NULL,

  -- Ensure proposed_rent can hold the final override value
  MODIFY proposed_rent DECIMAL(12,2) DEFAULT NULL;

-- Rent changes history (for audit & tracking)
CREATE TABLE IF NOT EXISTS re_lease_renewal_rent_history (
  id INT(11) NOT NULL AUTO_INCREMENT,
  company_id INT(11) NOT NULL,
  workflow_id INT(11) NOT NULL,
  lease_id INT(11) NOT NULL,
  old_rent DECIMAL(12,2) NOT NULL,
  increase_type ENUM('percentage','fixed') NOT NULL DEFAULT 'percentage',
  increase_value DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  calculated_new_rent DECIMAL(12,2) DEFAULT NULL,
  override_new_rent DECIMAL(12,2) DEFAULT NULL,
  changed_by INT(11) DEFAULT NULL,
  changed_at DATETIME NOT NULL DEFAULT current_timestamp(),
  notes TEXT DEFAULT NULL,
  PRIMARY KEY (id),
  KEY idx_workflow (workflow_id),
  KEY idx_lease (lease_id),
  KEY idx_changed_by (changed_by)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

