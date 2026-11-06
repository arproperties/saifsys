-- Purchases — what the buyer bought, recorded on the staff app (/staff/ → Purchase).
--
-- Two ways to buy: cash (paid at the shop, photo of the bill, status paid) or an
-- order from a supplier (status ordered, then received with a photo of the
-- delivery note). No approval, no stock, nothing posted to the accounts: a
-- record only. Its own tables on purpose — not the old inventory (inv_*).
--
-- Who may use it: HR → employee → Mobile App PIN → Purchase (staff_app_access,
-- app 'purchase'). Rules: modules/operations/includes/ops_purchases.php.
-- Files live in uploads/purchases/{purchase_id}/ (not web-readable).
--
-- Safe to run more than once.

CREATE TABLE IF NOT EXISTS `pur_suppliers` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(80) NOT NULL COMMENT 'Shop or supplier, as the buyer typed it first',
  `created_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_pur_suppliers_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `pur_purchases` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `user_id` INT(11) NOT NULL COMMENT 'The buyer (user.id)',
  `person_name` VARCHAR(120) NOT NULL COMMENT 'The buyer''s name when it was made',
  `kind` ENUM('cash','order') NOT NULL,
  `status` ENUM('paid','ordered','received') NOT NULL,
  `supplier_id` INT(11) NOT NULL,
  `bought_on` DATE NOT NULL,
  `received_on` DATE NULL,
  `created_day` DATE NOT NULL COMMENT 'The buyer may change it on this day only',
  `total` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `notes` VARCHAR(500) NULL,
  `client_ref` VARCHAR(64) NULL COMMENT 'The phone''s id for one save: a resend makes one purchase',
  `created_at` DATETIME NOT NULL,
  `updated_at` DATETIME NOT NULL,
  `updated_by` INT(11) NULL,
  `updated_by_name` VARCHAR(120) NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_pur_purchases_ref` (`user_id`, `client_ref`),
  KEY `idx_pur_purchases_bought` (`bought_on`),
  KEY `idx_pur_purchases_user` (`user_id`, `id`),
  CONSTRAINT `fk_pur_purchases_supplier` FOREIGN KEY (`supplier_id`) REFERENCES `pur_suppliers`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `pur_purchase_lines` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `purchase_id` INT(11) NOT NULL,
  `position` SMALLINT(5) NOT NULL DEFAULT 0,
  `name` VARCHAR(120) NOT NULL,
  `qty` DECIMAL(14,3) NOT NULL,
  `unit` VARCHAR(20) NULL,
  `price` DECIMAL(14,2) NOT NULL COMMENT 'For one',
  `amount` DECIMAL(14,2) NOT NULL COMMENT 'qty x price, worked out on the server',
  PRIMARY KEY (`id`),
  KEY `idx_pur_lines_purchase` (`purchase_id`),
  CONSTRAINT `fk_pur_lines_purchase` FOREIGN KEY (`purchase_id`) REFERENCES `pur_purchases`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `pur_purchase_files` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `purchase_id` INT(11) NOT NULL,
  `kind` ENUM('bill','delivery') NOT NULL,
  `file_path` VARCHAR(255) NOT NULL COMMENT 'Relative to the app root',
  `created_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_pur_files_purchase` (`purchase_id`),
  CONSTRAINT `fk_pur_files_purchase` FOREIGN KEY (`purchase_id`) REFERENCES `pur_purchases`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Inactive instead of deleted (added later; runs on a database that already has
-- the tables, adds only the missing columns, changes no rows). The buyer's
-- Delete makes a purchase inactive ("Tried deleting on same day"); Owner /
-- Admin / HR inactivate and activate in Operations → Purchases.
ALTER TABLE `pur_purchases`
    ADD COLUMN IF NOT EXISTS `is_active` TINYINT(1) NOT NULL DEFAULT 1 COMMENT '0 = inactive: hidden from the buyer, kept for the office',
    ADD COLUMN IF NOT EXISTS `inactive_at` DATETIME NULL,
    ADD COLUMN IF NOT EXISTS `inactive_by` INT(11) NULL COMMENT 'user.id',
    ADD COLUMN IF NOT EXISTS `inactive_by_name` VARCHAR(120) NULL,
    ADD COLUMN IF NOT EXISTS `inactive_reason` VARCHAR(255) NULL;
