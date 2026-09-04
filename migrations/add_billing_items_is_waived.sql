-- Add is_waived to re_billing_items for penalty (and other) charges that can be waived
-- Safe to run: only adds column if missing

SET @col_exists = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 're_billing_items'
      AND COLUMN_NAME = 'is_waived'
);

SET @sql = IF(@col_exists = 0,
    'ALTER TABLE `re_billing_items` ADD COLUMN `is_waived` TINYINT(1) NOT NULL DEFAULT 0 AFTER `is_paid`',
    'SELECT "Column is_waived already exists" AS msg'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
