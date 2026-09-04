-- ============================================================================
-- Extra Service Charges: amount_basis snapshot (VAT exclusive vs inclusive)
-- Additive / idempotent. Does not rewrite historical billing math.
-- ============================================================================

SET @col := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 're_service_charges'
      AND COLUMN_NAME = 'amount_basis'
);
SET @sql := IF(@col = 0,
    'ALTER TABLE `re_service_charges` ADD COLUMN `amount_basis` ENUM(''vat_exclusive'',''vat_inclusive'') NOT NULL DEFAULT ''vat_exclusive'' AFTER `vat_rate`',
    'SELECT ''amount_basis exists'' AS msg');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Historical rows: treat contracted as net (matches prior exclusive behaviour)
UPDATE `re_service_charges`
SET `amount_basis` = 'vat_exclusive'
WHERE `amount_basis` IS NULL OR `amount_basis` = 'vat_exclusive';
