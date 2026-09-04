-- Phase 2: expense_number, paid_via (cash|bank|credit|accounts_payable), status (draft|posted|cancelled)
-- Run on same database as erp_expenses.sql. Review before production.

CREATE TABLE IF NOT EXISTS `erp_expense_seq` (
  `company_id` int(11) NOT NULL,
  `last_num` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`company_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- expense_number column
SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'erp_expense_headers' AND COLUMN_NAME = 'expense_number');
SET @sql := IF(@col = 0,
  'ALTER TABLE `erp_expense_headers` ADD COLUMN `expense_number` varchar(32) DEFAULT NULL COMMENT ''Display id'' AFTER `source_module`',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @idx := (SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'erp_expense_headers' AND INDEX_NAME = 'uq_erp_expense_company_number');
SET @sql := IF(@idx = 0,
  'ALTER TABLE `erp_expense_headers` ADD UNIQUE KEY `uq_erp_expense_company_number` (`company_id`,`expense_number`)',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- paid_via: widen then migrate
ALTER TABLE `erp_expense_headers`
  MODIFY COLUMN `paid_via` enum('bank','cash','ap','credit','accounts_payable') NOT NULL DEFAULT 'bank';

UPDATE `erp_expense_headers` SET `paid_via` = 'accounts_payable' WHERE `paid_via` = 'ap';

ALTER TABLE `erp_expense_headers`
  MODIFY COLUMN `paid_via` enum('cash','bank','credit','accounts_payable') NOT NULL DEFAULT 'bank';

-- status: widen then migrate
ALTER TABLE `erp_expense_headers`
  MODIFY COLUMN `status` enum('draft','posted','void','cancelled') NOT NULL DEFAULT 'draft';

UPDATE `erp_expense_headers` SET `status` = 'cancelled' WHERE `status` = 'void';

UPDATE `erp_expense_headers` SET `status` = 'posted' WHERE `journal_id` IS NOT NULL AND `status` <> 'cancelled';

UPDATE `erp_expense_headers` SET `status` = 'draft' WHERE `journal_id` IS NULL AND `status` NOT IN ('cancelled','posted');

ALTER TABLE `erp_expense_headers`
  MODIFY COLUMN `status` enum('draft','posted','cancelled') NOT NULL DEFAULT 'draft';
