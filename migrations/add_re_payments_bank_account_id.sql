-- Add bank_account_id to re_payments so we can store and display which bank account a payment was recorded to
-- Safe to run: only adds column if missing

SET @col_exists = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 're_payments'
      AND COLUMN_NAME = 'bank_account_id'
);

SET @sql = IF(@col_exists = 0,
    'ALTER TABLE `re_payments` ADD COLUMN `bank_account_id` INT(11) DEFAULT NULL AFTER `payment_method`, ADD KEY `idx_bank_account` (`bank_account_id`)',
    'SELECT "Column bank_account_id already exists" AS msg'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Optional FK (run only if re_bank_accounts exists and you want referential integrity)
-- ALTER TABLE re_payments ADD CONSTRAINT fk_payment_bank_account FOREIGN KEY (bank_account_id) REFERENCES re_bank_accounts(id) ON DELETE SET NULL;
