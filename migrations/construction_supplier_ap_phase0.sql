-- Construction Suppliers / AP — Phase 0 foundation
-- Audit trail only. Does NOT modify RE vendor tables or accounting_engine.
-- Human-applied. Idempotent CREATE IF NOT EXISTS.

CREATE TABLE IF NOT EXISTS `co_supplier_ap_audit` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `company_id` INT(11) NOT NULL,
  `supplier_id` INT(11) DEFAULT NULL,
  `supplier_invoice_id` INT(11) DEFAULT NULL,
  `supplier_payment_id` INT(11) DEFAULT NULL,
  `action_type` VARCHAR(80) NOT NULL,
  `old_value` TEXT DEFAULT NULL,
  `new_value` TEXT DEFAULT NULL,
  `amount` DECIMAL(15,2) DEFAULT NULL,
  `reason` VARCHAR(255) DEFAULT NULL,
  `changed_by` INT(11) DEFAULT NULL,
  `changed_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `source` VARCHAR(80) NOT NULL DEFAULT 'system',
  `related_journal_id` INT(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_co_supplier_ap_audit_company` (`company_id`, `changed_at`),
  KEY `idx_co_supplier_ap_audit_invoice` (`company_id`, `supplier_invoice_id`, `changed_at`),
  KEY `idx_co_supplier_ap_audit_payment` (`company_id`, `supplier_payment_id`, `changed_at`),
  KEY `idx_co_supplier_ap_audit_action` (`company_id`, `action_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Audit trail for Construction Suppliers/AP actions';
