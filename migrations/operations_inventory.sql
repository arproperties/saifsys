-- Operations — simple stock tracking.
-- Deliberately standalone: no link to the inv_* / inventory module tables.
-- Two tables: what you hold, and every movement in or out.

-- ============================================================================
-- 1. Stock items
-- ============================================================================
CREATE TABLE IF NOT EXISTS `ops_items` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `company_id` INT(11) NOT NULL,
  `name` VARCHAR(255) NOT NULL COMMENT 'e.g. Floor cleaner, Gloves, AC filter',
  `unit` VARCHAR(50) DEFAULT NULL COMMENT 'pcs, litre, box, roll ...',
  `current_qty` DECIMAL(12,3) NOT NULL DEFAULT 0.000,
  `min_qty` DECIMAL(12,3) NOT NULL DEFAULT 0.000 COMMENT 'Warn when stock drops to or below this',
  `notes` VARCHAR(500) DEFAULT NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_by` INT(11) DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_ops_items_company` (`company_id`, `is_active`),
  KEY `idx_ops_items_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ============================================================================
-- 2. Stock movements — the history behind every quantity
-- ============================================================================
CREATE TABLE IF NOT EXISTS `ops_stock_moves` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `company_id` INT(11) NOT NULL,
  `item_id` INT(11) NOT NULL,
  `qty_change` DECIMAL(12,3) NOT NULL COMMENT 'Positive = in, negative = out',
  `reason` ENUM('in','out','adjust') NOT NULL DEFAULT 'in',
  `job_id` INT(11) DEFAULT NULL COMMENT 'Set when the stock went out on a job',
  `note` VARCHAR(500) DEFAULT NULL,
  `created_by` INT(11) DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_ops_moves_item` (`item_id`, `created_at`),
  KEY `idx_ops_moves_job` (`job_id`),
  CONSTRAINT `fk_ops_moves_item` FOREIGN KEY (`item_id`) REFERENCES `ops_items`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_ops_moves_job` FOREIGN KEY (`job_id`) REFERENCES `ops_jobs`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Stock is not tied to a job. ops_stock_moves.job_id is there for the day one
-- is, but nothing sets it: the office takes a thing off the shelf by hand after
-- handing it to whoever asked in the job conversation.
