-- ============================================================================
-- Tenant Portal ↔ Lease Renewal Workflow (Phase 1)
-- Run after lease_renewal_automation_schema.sql and renewal_notice_unified_template.sql
-- ============================================================================

SET NAMES utf8mb4;

-- ---------------------------------------------------------------------------
-- 1) Extend workflow status (add portal lifecycle values; keep existing)
-- ---------------------------------------------------------------------------
-- If this fails (ENUM mismatch), adjust to match your current column definition.
ALTER TABLE re_lease_renewal_workflows
  MODIFY COLUMN status ENUM(
    'initiated',
    'notice_sent',
    'viewed_by_tenant',
    'acknowledged',
    'pending_response',
    'negotiation',
    'accepted',
    'rejected',
    'approved',
    'contract_ready',
    'signed',
    'completed',
    'converted'
  ) NOT NULL DEFAULT 'initiated';

-- ---------------------------------------------------------------------------
-- 2) Denormalized portal fields on workflow (fast UI; full audit in events)
-- ---------------------------------------------------------------------------
ALTER TABLE re_lease_renewal_workflows
  ADD COLUMN IF NOT EXISTS tenant_first_viewed_at DATETIME DEFAULT NULL COMMENT 'First time tenant opened notice in portal',
  ADD COLUMN IF NOT EXISTS tenant_acknowledged_at DATETIME DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS tenant_acknowledged_ip VARCHAR(45) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS tenant_acknowledged_by_tpu_id INT(11) DEFAULT NULL COMMENT 'tenant_portal_users.id',
  ADD COLUMN IF NOT EXISTS tenant_portal_decision ENUM('accept','negotiate','reject') DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS tenant_portal_decision_at DATETIME DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS tenant_portal_decision_ip VARCHAR(45) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS tenant_portal_decision_by_tpu_id INT(11) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS contract_ready_at DATETIME DEFAULT NULL COMMENT 'Admin marked draft ready for tenant e-sign',
  ADD COLUMN IF NOT EXISTS contract_ready_by INT(11) DEFAULT NULL COMMENT 'user.id';

-- ---------------------------------------------------------------------------
-- 3) Append-only audit log (all tenant portal actions)
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS re_renewal_portal_events (
  id INT(11) NOT NULL AUTO_INCREMENT,
  workflow_id INT(11) NOT NULL,
  company_id INT(11) NOT NULL,
  event_type VARCHAR(64) NOT NULL COMMENT 'notice_opened, acknowledged, decision_accept, decision_negotiate, decision_reject, document_uploaded, contract_opened, electronic_sign',
  message TEXT DEFAULT NULL,
  tenant_portal_user_id INT(11) DEFAULT NULL,
  legacy_user_id INT(11) DEFAULT NULL COMMENT 'Main user.id when legacy portal login',
  ip_address VARCHAR(45) DEFAULT NULL,
  user_agent VARCHAR(512) DEFAULT NULL,
  meta_json TEXT DEFAULT NULL COMMENT 'Optional JSON payload',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_workflow (workflow_id),
  KEY idx_company (company_id),
  KEY idx_created (created_at),
  KEY idx_tpu (tenant_portal_user_id),
  CONSTRAINT fk_rpe_workflow FOREIGN KEY (workflow_id) REFERENCES re_lease_renewal_workflows (id) ON DELETE CASCADE,
  CONSTRAINT fk_rpe_company FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE CASCADE,
  CONSTRAINT fk_rpe_tpu FOREIGN KEY (tenant_portal_user_id) REFERENCES tenant_portal_users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ---------------------------------------------------------------------------
-- 4) Tenant-uploaded renewal documents
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS re_renewal_tenant_uploads (
  id INT(11) NOT NULL AUTO_INCREMENT,
  workflow_id INT(11) NOT NULL,
  company_id INT(11) NOT NULL,
  tenant_portal_user_id INT(11) DEFAULT NULL,
  legacy_user_id INT(11) DEFAULT NULL,
  document_type VARCHAR(32) NOT NULL COMMENT 'passport, visa, trade_license, poa, other',
  original_filename VARCHAR(255) NOT NULL,
  stored_path VARCHAR(500) NOT NULL COMMENT 'Relative to project root',
  mime_type VARCHAR(120) DEFAULT NULL,
  size_bytes INT(11) DEFAULT NULL,
  ip_address VARCHAR(45) DEFAULT NULL,
  uploaded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  reviewed_by INT(11) DEFAULT NULL,
  reviewed_at DATETIME DEFAULT NULL,
  review_notes VARCHAR(500) DEFAULT NULL,
  PRIMARY KEY (id),
  KEY idx_workflow (workflow_id),
  KEY idx_company (company_id),
  CONSTRAINT fk_rtu_workflow FOREIGN KEY (workflow_id) REFERENCES re_lease_renewal_workflows (id) ON DELETE CASCADE,
  CONSTRAINT fk_rtu_company FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE CASCADE,
  CONSTRAINT fk_rtu_tpu FOREIGN KEY (tenant_portal_user_id) REFERENCES tenant_portal_users (id) ON DELETE SET NULL,
  CONSTRAINT fk_rtu_reviewer FOREIGN KEY (reviewed_by) REFERENCES user (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ---------------------------------------------------------------------------
-- 5) Electronic signature (MVP — typed name + acceptance)
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS re_renewal_electronic_signatures (
  id INT(11) NOT NULL AUTO_INCREMENT,
  workflow_id INT(11) NOT NULL,
  company_id INT(11) NOT NULL,
  new_lease_id INT(11) NOT NULL COMMENT 'Draft lease being signed',
  typed_full_name VARCHAR(200) NOT NULL,
  terms_accepted TINYINT(1) NOT NULL DEFAULT 0,
  tenant_portal_user_id INT(11) DEFAULT NULL,
  legacy_user_id INT(11) DEFAULT NULL,
  ip_address VARCHAR(45) DEFAULT NULL,
  user_agent VARCHAR(512) DEFAULT NULL,
  signed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_workflow_sign (workflow_id),
  KEY idx_new_lease (new_lease_id),
  CONSTRAINT fk_res_workflow FOREIGN KEY (workflow_id) REFERENCES re_lease_renewal_workflows (id) ON DELETE CASCADE,
  CONSTRAINT fk_res_company FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE CASCADE,
  CONSTRAINT fk_res_lease FOREIGN KEY (new_lease_id) REFERENCES re_leases (id) ON DELETE CASCADE,
  CONSTRAINT fk_res_tpu FOREIGN KEY (tenant_portal_user_id) REFERENCES tenant_portal_users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

SELECT 'tenant_portal_renewal_phase1 applied.' AS status;
