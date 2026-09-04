-- Tenant Portal: tenant uploads scanned signed tenancy contract (PDF) after offline signing.
-- Admin sees it on Lease → Documents; final fully-executed PDF still uploaded as normal re_documents.

CREATE TABLE IF NOT EXISTS `re_tenancy_contract_tenant_uploads` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `company_id` int(11) NOT NULL,
  `lease_id` int(11) NOT NULL,
  `stored_path` varchar(500) NOT NULL COMMENT 'Relative path from project root',
  `original_filename` varchar(255) NOT NULL,
  `file_size` int(11) NOT NULL DEFAULT 0,
  `mime_type` varchar(100) DEFAULT NULL,
  `uploaded_at` datetime NOT NULL DEFAULT current_timestamp(),
  `tenant_portal_user_id` int(11) DEFAULT NULL,
  `legacy_user_id` int(11) DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` varchar(500) DEFAULT NULL,
  `superseded_at` datetime DEFAULT NULL COMMENT 'Previous scan when tenant uploads again',
  PRIMARY KEY (`id`),
  KEY `idx_lease_active` (`company_id`,`lease_id`,`superseded_at`),
  KEY `idx_lease_id` (`lease_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
