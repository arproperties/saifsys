-- Construction: retirement mode on contractor payment retirement audit log
-- Supports verified_match vs business_approved_legacy workflows.

SET @col_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'co_contractor_payment_retirement_log'
      AND COLUMN_NAME = 'retirement_mode'
);
SET @sql := IF(@col_exists = 0,
    'ALTER TABLE co_contractor_payment_retirement_log
        ADD COLUMN retirement_mode VARCHAR(40) NOT NULL DEFAULT ''verified_match'' AFTER verified_against_note,
        ADD INDEX idx_co_cp_retire_mode (company_id, retirement_mode)',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
