-- Tenant in-app notifications (mobile app + tenant portal)
-- In-app only. No push/email is driven by this table.

CREATE TABLE IF NOT EXISTS re_tenant_notifications (
  id INT(11) NOT NULL AUTO_INCREMENT,
  company_id INT(11) NOT NULL,
  tenant_id INT(11) DEFAULT NULL,
  lease_id INT(11) DEFAULT NULL,
  tenant_portal_user_id INT(11) DEFAULT NULL,
  type VARCHAR(50) NOT NULL COMMENT 'invoice_issued, payment_received, payment_due, maintenance_status, cleaning_status, pest_status, extra_service_status, renewal_notice, renewal_status, document_available',
  entity_type VARCHAR(40) DEFAULT NULL COMMENT 'invoice, payment, maintenance, cleaning, pest_control, extra_service, renewal, document',
  entity_id INT(11) DEFAULT NULL,
  title VARCHAR(200) NOT NULL,
  body VARCHAR(500) DEFAULT NULL,
  is_read TINYINT(1) NOT NULL DEFAULT 0,
  read_at DATETIME DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (id),
  KEY idx_recipient (company_id, tenant_id, is_read),
  KEY idx_tpu (tenant_portal_user_id),
  KEY idx_lease (lease_id),
  KEY idx_dedup (type, entity_type, entity_id, lease_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
