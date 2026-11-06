-- Store — what is in each store, and every time something goes in or out,
-- recorded by the store keeper on the staff app (/staff/ → Store).
--
-- Several stores (Main store, Camp store, …), one item list for all of them.
-- Quantities only, no money. Not linked to Purchase, and nothing from the old
-- inventory (inv_*). No approval: the office sees and fixes the history.
--
-- Who may use it: HR → employee → Mobile App PIN → Store (staff_app_access,
-- app 'store'). Rules: modules/operations/includes/ops_store.php.
-- Files live in uploads/store/ (not web-readable).
--
-- Safe to run more than once.

CREATE TABLE IF NOT EXISTS `st_stores` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(80) NOT NULL,
  `active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_st_stores_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `st_items` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(120) NOT NULL,
  `unit` VARCHAR(20) NULL COMMENT 'pcs, box, litre …',
  `min_qty` DECIMAL(14,3) NULL COMMENT 'Below this in a store = low stock',
  `photo_path` VARCHAR(255) NULL COMMENT 'Relative to the app root',
  `active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL,
  `created_by` INT(11) NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_st_items_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- How many of each item are in each store now. Kept by the rules on every
-- entry, in the same transaction, and never below 0.
CREATE TABLE IF NOT EXISTS `st_stock` (
  `store_id` INT(11) NOT NULL,
  `item_id` INT(11) NOT NULL,
  `qty` DECIMAL(14,3) NOT NULL DEFAULT 0.000,
  PRIMARY KEY (`store_id`, `item_id`),
  KEY `idx_st_stock_item` (`item_id`),
  CONSTRAINT `fk_st_stock_store` FOREIGN KEY (`store_id`) REFERENCES `st_stores`(`id`),
  CONSTRAINT `fk_st_stock_item` FOREIGN KEY (`item_id`) REFERENCES `st_items`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- One entry: goods in, goods out, a move to another store, or a count correction.
CREATE TABLE IF NOT EXISTS `st_moves` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `kind` ENUM('in','out','move','count') NOT NULL,
  `store_id` INT(11) NOT NULL,
  `to_store_id` INT(11) NULL COMMENT 'move only',
  `user_id` INT(11) NOT NULL COMMENT 'The store keeper (user.id)',
  `person_name` VARCHAR(120) NOT NULL,
  `given_to_employee_id` INT(11) NULL COMMENT 'out only: who took it',
  `given_to_name` VARCHAR(120) NULL,
  `for_note` VARCHAR(160) NULL COMMENT 'out only: building, unit or job',
  `source` VARCHAR(120) NULL COMMENT 'in only: shop, returned …',
  `reason` ENUM('lost','damaged','counted') NULL COMMENT 'count only',
  `notes` VARCHAR(500) NULL,
  `photo_path` VARCHAR(255) NULL,
  `created_day` DATE NOT NULL COMMENT 'The store keeper may change it on this day only',
  `client_ref` VARCHAR(64) NULL COMMENT 'The phone''s id for one save',
  `created_at` DATETIME NOT NULL,
  `updated_at` DATETIME NOT NULL,
  `updated_by_name` VARCHAR(120) NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_st_moves_ref` (`user_id`, `client_ref`),
  KEY `idx_st_moves_store` (`store_id`, `created_at`),
  KEY `idx_st_moves_user` (`user_id`, `id`),
  CONSTRAINT `fk_st_moves_store` FOREIGN KEY (`store_id`) REFERENCES `st_stores`(`id`),
  CONSTRAINT `fk_st_moves_to_store` FOREIGN KEY (`to_store_id`) REFERENCES `st_stores`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- The items of an entry. qty is what changed in store_id: positive for in, a
-- move's or an out's amount (taken away), the difference for a count.
CREATE TABLE IF NOT EXISTS `st_move_lines` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `move_id` INT(11) NOT NULL,
  `item_id` INT(11) NOT NULL,
  `qty` DECIMAL(14,3) NOT NULL,
  `counted` DECIMAL(14,3) NULL COMMENT 'count only: the real number found',
  PRIMARY KEY (`id`),
  KEY `idx_st_lines_move` (`move_id`),
  KEY `idx_st_lines_item` (`item_id`),
  CONSTRAINT `fk_st_lines_move` FOREIGN KEY (`move_id`) REFERENCES `st_moves`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_st_lines_item` FOREIGN KEY (`item_id`) REFERENCES `st_items`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- A first store, so the app has somewhere to start. Rename it in Operations → Store → Setup.
INSERT INTO `st_stores` (`name`, `active`, `created_at`)
SELECT 'Main store', 1, NOW() FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `st_stores`);

-- Inactive instead of deleted (added later; runs on a database that already has
-- the tables, adds only the missing columns, changes no rows). The store
-- keeper's Delete makes an entry inactive ("Tried deleting on same day") and
-- puts its stock back; Owner / Admin / HR inactivate and activate in
-- Operations → Store → History.
ALTER TABLE `st_moves`
    ADD COLUMN IF NOT EXISTS `is_active` TINYINT(1) NOT NULL DEFAULT 1 COMMENT '0 = inactive: stock put back, hidden from the store keeper',
    ADD COLUMN IF NOT EXISTS `inactive_at` DATETIME NULL,
    ADD COLUMN IF NOT EXISTS `inactive_by` INT(11) NULL COMMENT 'user.id',
    ADD COLUMN IF NOT EXISTS `inactive_by_name` VARCHAR(120) NULL,
    ADD COLUMN IF NOT EXISTS `inactive_reason` VARCHAR(255) NULL;
