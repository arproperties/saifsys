-- ============================================================================
-- Quick Paid Expenses: optional building on expense lines (additive)
-- ----------------------------------------------------------------------------
-- Used by Real Estate Building Expenses report (UNION with vendor bill lines).
-- ============================================================================

SET @col := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'erp_expense_lines'
      AND COLUMN_NAME = 'building_id'
);
SET @sql := IF(@col = 0,
    'ALTER TABLE `erp_expense_lines` ADD COLUMN `building_id` INT(11) NULL DEFAULT NULL AFTER `account_id`, ADD KEY `idx_erp_expense_lines_building` (`building_id`)',
    'SELECT ''building_id exists'' AS msg');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
