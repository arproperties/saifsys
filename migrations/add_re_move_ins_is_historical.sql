-- Flag move-ins recorded with "Mark as Moved In" (tenant moved in before the
-- Move-In module was used: no checklist, inspection or meter readings).
-- Safe to run: only adds column if missing

SET @col_exists = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 're_move_ins'
      AND COLUMN_NAME = 'is_historical'
);

SET @sql = IF(@col_exists = 0,
    'ALTER TABLE `re_move_ins` ADD COLUMN `is_historical` TINYINT(1) NOT NULL DEFAULT 0 AFTER `status`',
    'SELECT "Column is_historical already exists" AS msg'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
