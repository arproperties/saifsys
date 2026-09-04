-- Construction Shop Rental Phase 1.75 — multi-shop contracts + control center foundation.
-- Madar Al Wadi / Construction only. Additive. Does not touch RE lease tables or posting engines.
-- Rollback intent: DROP TABLE co_shop_rental_contract_shops after backup (legacy shop_unit_id remains).

CREATE TABLE IF NOT EXISTS `co_shop_rental_contract_shops` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `company_id` INT(11) NOT NULL,
  `contract_id` INT(11) NOT NULL,
  `shop_unit_id` INT(11) NOT NULL,
  `is_primary` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_co_shop_contract_shop` (`contract_id`, `shop_unit_id`),
  KEY `idx_company_shop` (`company_id`, `shop_unit_id`),
  KEY `idx_contract_primary` (`contract_id`, `is_primary`),
  CONSTRAINT `fk_co_shop_cshop_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_co_shop_cshop_contract` FOREIGN KEY (`contract_id`) REFERENCES `co_shop_rental_contracts` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_co_shop_cshop_unit` FOREIGN KEY (`shop_unit_id`) REFERENCES `co_shop_units` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Backfill existing one-shop contracts (idempotent via UNIQUE).
INSERT IGNORE INTO `co_shop_rental_contract_shops` (`company_id`, `contract_id`, `shop_unit_id`, `is_primary`)
SELECT `company_id`, `id`, `shop_unit_id`, 1
FROM `co_shop_rental_contracts`
WHERE `shop_unit_id` IS NOT NULL AND `shop_unit_id` > 0;
