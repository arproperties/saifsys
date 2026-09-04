-- Property Sharing V1 — opaque public share codes (Find Your Home first).
-- Additive only. Rollback: DROP TABLE IF EXISTS re_property_share_refs;
-- App config columns are also ensured at runtime by app_mobile_config_ensure_schema().

CREATE TABLE IF NOT EXISTS `re_property_share_refs` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `company_id` INT(11) NOT NULL,
  `share_code` CHAR(36) NOT NULL,
  `property_type` VARCHAR(40) NOT NULL DEFAULT 'long_term_rental',
  `entity_id` INT(11) NOT NULL COMMENT 'For long_term_rental = re_units.id',
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_share_code` (`share_code`),
  UNIQUE KEY `uq_company_type_entity` (`company_id`, `property_type`, `entity_id`),
  KEY `idx_company_active` (`company_id`, `is_active`),
  KEY `idx_entity` (`property_type`, `entity_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
