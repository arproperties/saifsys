-- Link ERP expense headers to Construction suppliers (co_suppliers) when source_module = 'construction'.
-- re_vendors / vendor_id remain for Real Estate & ARS. Run once after erp_expenses / phase2.

SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'erp_expense_headers' AND COLUMN_NAME = 'co_supplier_id');
SET @sql := IF(@col = 0,
  'ALTER TABLE `erp_expense_headers` ADD COLUMN `co_supplier_id` int(11) DEFAULT NULL COMMENT ''co_suppliers.id (construction)'' AFTER `vendor_id`',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @idx := (SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'erp_expense_headers' AND INDEX_NAME = 'idx_erp_exp_co_supplier');
SET @sql := IF(@idx = 0,
  'ALTER TABLE `erp_expense_headers` ADD KEY `idx_erp_exp_co_supplier` (`co_supplier_id`)',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
