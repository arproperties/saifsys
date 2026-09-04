-- ============================================================================
-- Vendor payments: pay from any cash/bank GL account (additive)
-- ----------------------------------------------------------------------------
-- Payments used to be limited to accounts registered in re_bank_accounts.
-- gl_account_id lets a payment credit any active cash/bank account straight
-- from the chart of accounts (e.g. 1130 CASH - MR. AMRAN AKHTAR).
-- bank_account_id is still written when the chosen GL account is a registered
-- bank account, so existing joins and bank reconciliation keep working.
-- ============================================================================

SET @col_exists := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 're_vendor_payments'
      AND COLUMN_NAME = 'gl_account_id'
);
SET @sql := IF(@col_exists = 0,
    'ALTER TABLE `re_vendor_payments` ADD COLUMN `gl_account_id` INT(11) DEFAULT NULL AFTER `bank_account_id`',
    'SELECT ''gl_account_id already exists'' AS msg'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @idx_exists := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 're_vendor_payments'
      AND INDEX_NAME = 'idx_re_vendor_pay_gl'
);
SET @sql := IF(@idx_exists = 0,
    'ALTER TABLE `re_vendor_payments` ADD KEY `idx_re_vendor_pay_gl` (`gl_account_id`)',
    'SELECT ''idx_re_vendor_pay_gl already exists'' AS msg'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Backfill: existing payments keep crediting the same GL account they posted to.
UPDATE `re_vendor_payments` vp
JOIN `re_bank_accounts` ba
  ON ba.id = vp.bank_account_id AND ba.company_id = vp.company_id
SET vp.gl_account_id = ba.gl_account_id
WHERE vp.gl_account_id IS NULL;
