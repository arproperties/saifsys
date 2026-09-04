-- Real Estate lease lifecycle safeguards
-- Adds soft-delete metadata so draft leases can be archived/restored instead of destroyed.

ALTER TABLE re_leases
  ADD COLUMN IF NOT EXISTS deleted_at DATETIME DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS deleted_by INT(11) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS delete_reason VARCHAR(255) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS restored_at DATETIME DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS restored_by INT(11) DEFAULT NULL;

CREATE INDEX IF NOT EXISTS idx_re_leases_deleted_at ON re_leases (deleted_at);
CREATE INDEX IF NOT EXISTS idx_re_leases_company_deleted_status ON re_leases (company_id, deleted_at, status);
