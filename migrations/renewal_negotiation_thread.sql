-- Negotiation thread: back-and-forth messages between tenant (portal) and admin (staff)
-- Run on each environment after tenant_portal_renewal_phase1.sql

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS re_renewal_negotiation_messages (
  id INT(11) NOT NULL AUTO_INCREMENT,
  workflow_id INT(11) NOT NULL,
  company_id INT(11) NOT NULL,
  author_role ENUM('tenant','admin') NOT NULL,
  body TEXT NOT NULL,
  tenant_portal_user_id INT(11) DEFAULT NULL,
  legacy_tenant_user_id INT(11) DEFAULT NULL COMMENT 'Main user.id when legacy portal login',
  admin_user_id INT(11) DEFAULT NULL COMMENT 'Staff user.id',
  ip_address VARCHAR(45) DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_workflow_created (workflow_id, created_at),
  KEY idx_company (company_id),
  CONSTRAINT fk_rnm_workflow FOREIGN KEY (workflow_id) REFERENCES re_lease_renewal_workflows (id) ON DELETE CASCADE,
  CONSTRAINT fk_rnm_company FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE CASCADE,
  CONSTRAINT fk_rnm_tpu FOREIGN KEY (tenant_portal_user_id) REFERENCES tenant_portal_users (id) ON DELETE SET NULL,
  CONSTRAINT fk_rnm_admin FOREIGN KEY (admin_user_id) REFERENCES user (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

SELECT 'renewal_negotiation_thread applied.' AS status;
 