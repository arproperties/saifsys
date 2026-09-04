-- Barber Shop POS — Phase B1 schema + seed company (Two Bhai Barbershop LLC)
-- Safe to re-run where noted (INSERT IGNORE / IF NOT EXISTS).
-- Run barber_module_rbac.sql after this for role access.

SET NAMES utf8mb4;

-- Extend companies.business_type for barbershop (add value if not already present)
-- Full enum list so MODIFY works on installs that already extended business_type (construction, ARS, etc.)
ALTER TABLE `companies`
  MODIFY COLUMN `business_type`
  ENUM('cleaning','realestate','supermarket','restaurant','construction','short_term_rental','barbershop')
  NOT NULL;

INSERT IGNORE INTO `companies` (`name`, `code`, `business_type`, `is_active`)
VALUES ('Two Bhai Barbershop LLC', 'TWO_BHAI_BARBERS', 'barbershop', 1);

SET @barber_company_id := (SELECT `id` FROM `companies` WHERE `code` = 'TWO_BHAI_BARBERS' LIMIT 1);

-- Grant Owner/Admin users access to the new company (optional; adjust if you use different role names)
INSERT IGNORE INTO `user_companies` (`user_id`, `company_id`, `is_primary`)
SELECT DISTINCT `ur`.`user_id`, @barber_company_id, 0
FROM `user_roles` `ur`
JOIN `roles` `r` ON `r`.`id` = `ur`.`role_id`
WHERE `r`.`name` IN ('Owner', 'Admin');

CREATE TABLE IF NOT EXISTS `barber_staff` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `company_id` int(11) NOT NULL,
  `display_name` varchar(120) NOT NULL,
  `user_id` int(11) DEFAULT NULL COMMENT 'If set, POS defaults to this barber when user logs in',
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_barber_staff_company` (`company_id`),
  KEY `idx_barber_staff_user` (`user_id`),
  CONSTRAINT `fk_barber_staff_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_barber_staff_user` FOREIGN KEY (`user_id`) REFERENCES `user` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `barber_services` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `company_id` int(11) NOT NULL,
  `name` varchar(160) NOT NULL,
  `price` decimal(10,2) NOT NULL DEFAULT 0.00,
  `sort_order` int(11) NOT NULL DEFAULT 0 COMMENT 'Lower = earlier in POS grid (favorites ordering later)',
  `is_favorite` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'Reserved for future quick/favorite UI',
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_barber_services_company` (`company_id`),
  CONSTRAINT `fk_barber_services_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `barber_sale_seq` (
  `company_id` int(11) NOT NULL,
  `last_num` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`company_id`),
  CONSTRAINT `fk_barber_sale_seq_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `barber_sales` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `company_id` int(11) NOT NULL,
  `sale_number` varchar(32) DEFAULT NULL,
  `barber_staff_id` int(11) NOT NULL,
  `sale_at` datetime NOT NULL DEFAULT current_timestamp(),
  `payment_method` enum('cash','card') NOT NULL,
  `subtotal` decimal(10,2) NOT NULL DEFAULT 0.00,
  `total` decimal(10,2) NOT NULL DEFAULT 0.00,
  `created_by` int(11) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_barber_sale_company_number` (`company_id`,`sale_number`),
  KEY `idx_barber_sales_company_time` (`company_id`,`sale_at`),
  KEY `idx_barber_sales_barber` (`barber_staff_id`),
  CONSTRAINT `fk_barber_sales_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_barber_sales_barber` FOREIGN KEY (`barber_staff_id`) REFERENCES `barber_staff` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_barber_sales_user` FOREIGN KEY (`created_by`) REFERENCES `user` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `barber_sale_lines` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `sale_id` int(11) NOT NULL,
  `service_id` int(11) NOT NULL,
  `service_name_snapshot` varchar(160) NOT NULL,
  `unit_price` decimal(10,2) NOT NULL,
  `qty` decimal(10,2) NOT NULL DEFAULT 1.00,
  `line_total` decimal(10,2) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_barber_sale_lines_sale` (`sale_id`),
  KEY `idx_barber_sale_lines_service` (`service_id`),
  CONSTRAINT `fk_barber_sale_lines_sale` FOREIGN KEY (`sale_id`) REFERENCES `barber_sales` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_barber_sale_lines_service` FOREIGN KEY (`service_id`) REFERENCES `barber_services` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Seed five barbers once per company (rename in Back office → Team)
INSERT INTO `barber_staff` (`company_id`, `display_name`, `user_id`, `sort_order`, `is_active`)
SELECT @barber_company_id, 'Barber 1', NULL, 1, 1 FROM (SELECT 1 AS `_`) `_`
WHERE @barber_company_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM `barber_staff` WHERE `company_id` = @barber_company_id AND `sort_order` = 1);
INSERT INTO `barber_staff` (`company_id`, `display_name`, `user_id`, `sort_order`, `is_active`)
SELECT @barber_company_id, 'Barber 2', NULL, 2, 1 FROM (SELECT 1 AS `_`) `_`
WHERE @barber_company_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM `barber_staff` WHERE `company_id` = @barber_company_id AND `sort_order` = 2);
INSERT INTO `barber_staff` (`company_id`, `display_name`, `user_id`, `sort_order`, `is_active`)
SELECT @barber_company_id, 'Barber 3', NULL, 3, 1 FROM (SELECT 1 AS `_`) `_`
WHERE @barber_company_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM `barber_staff` WHERE `company_id` = @barber_company_id AND `sort_order` = 3);
INSERT INTO `barber_staff` (`company_id`, `display_name`, `user_id`, `sort_order`, `is_active`)
SELECT @barber_company_id, 'Barber 4', NULL, 4, 1 FROM (SELECT 1 AS `_`) `_`
WHERE @barber_company_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM `barber_staff` WHERE `company_id` = @barber_company_id AND `sort_order` = 4);
INSERT INTO `barber_staff` (`company_id`, `display_name`, `user_id`, `sort_order`, `is_active`)
SELECT @barber_company_id, 'Barber 5', NULL, 5, 1 FROM (SELECT 1 AS `_`) `_`
WHERE @barber_company_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM `barber_staff` WHERE `company_id` = @barber_company_id AND `sort_order` = 5);

-- Default services (once per company)
INSERT INTO `barber_services` (`company_id`, `name`, `price`, `sort_order`, `is_favorite`, `is_active`)
SELECT @barber_company_id, 'Haircut', 35.00, 10, 0, 1 FROM (SELECT 1 AS `_`) `_`
WHERE @barber_company_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM `barber_services` WHERE `company_id` = @barber_company_id AND `name` = 'Haircut');
INSERT INTO `barber_services` (`company_id`, `name`, `price`, `sort_order`, `is_favorite`, `is_active`)
SELECT @barber_company_id, 'Beard trim', 25.00, 20, 0, 1 FROM (SELECT 1 AS `_`) `_`
WHERE @barber_company_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM `barber_services` WHERE `company_id` = @barber_company_id AND `name` = 'Beard trim');
INSERT INTO `barber_services` (`company_id`, `name`, `price`, `sort_order`, `is_favorite`, `is_active`)
SELECT @barber_company_id, 'Haircut + beard', 50.00, 30, 0, 1 FROM (SELECT 1 AS `_`) `_`
WHERE @barber_company_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM `barber_services` WHERE `company_id` = @barber_company_id AND `name` = 'Haircut + beard');
INSERT INTO `barber_services` (`company_id`, `name`, `price`, `sort_order`, `is_favorite`, `is_active`)
SELECT @barber_company_id, 'Kids haircut', 30.00, 40, 0, 1 FROM (SELECT 1 AS `_`) `_`
WHERE @barber_company_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM `barber_services` WHERE `company_id` = @barber_company_id AND `name` = 'Kids haircut');
INSERT INTO `barber_services` (`company_id`, `name`, `price`, `sort_order`, `is_favorite`, `is_active`)
SELECT @barber_company_id, 'Shave', 40.00, 50, 0, 1 FROM (SELECT 1 AS `_`) `_`
WHERE @barber_company_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM `barber_services` WHERE `company_id` = @barber_company_id AND `name` = 'Shave');
INSERT INTO `barber_services` (`company_id`, `name`, `price`, `sort_order`, `is_favorite`, `is_active`)
SELECT @barber_company_id, 'Facial', 45.00, 60, 0, 1 FROM (SELECT 1 AS `_`) `_`
WHERE @barber_company_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM `barber_services` WHERE `company_id` = @barber_company_id AND `name` = 'Facial');
