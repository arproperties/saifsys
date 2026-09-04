-- ============================================================================
-- Extra Service Charges: agreement lifecycle + VAT snapshot (additive / idempotent)
-- Revenue recognition path unchanged (monthly billing items still generated as today).
-- ============================================================================

-- re_service_charges.contracted_amount
SET @col := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 're_service_charges' AND COLUMN_NAME = 'contracted_amount'
);
SET @sql := IF(@col = 0,
    'ALTER TABLE `re_service_charges` ADD COLUMN `contracted_amount` DECIMAL(15,2) NULL DEFAULT NULL AFTER `amount`',
    'SELECT ''contracted_amount exists'' AS msg');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- lifecycle_status
SET @col := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 're_service_charges' AND COLUMN_NAME = 'lifecycle_status'
);
SET @sql := IF(@col = 0,
    'ALTER TABLE `re_service_charges` ADD COLUMN `lifecycle_status` ENUM(''draft'',''active'',''suspended'',''expired'',''cancelled'',''superseded'') NOT NULL DEFAULT ''active'' AFTER `is_active`',
    'SELECT ''lifecycle_status exists'' AS msg');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- vat_treatment on charges
SET @col := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 're_service_charges' AND COLUMN_NAME = 'vat_treatment'
);
SET @sql := IF(@col = 0,
    'ALTER TABLE `re_service_charges` ADD COLUMN `vat_treatment` ENUM(''standard'',''zero_rated'',''exempt'',''out_of_scope'',''company_default'') NOT NULL DEFAULT ''company_default'' AFTER `lifecycle_status`',
    'SELECT ''sc vat_treatment exists'' AS msg');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 're_service_charges' AND COLUMN_NAME = 'vat_rate'
);
SET @sql := IF(@col = 0,
    'ALTER TABLE `re_service_charges` ADD COLUMN `vat_rate` DECIMAL(5,2) NOT NULL DEFAULT 0.00 AFTER `vat_treatment`',
    'SELECT ''sc vat_rate exists'' AS msg');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 're_service_charges' AND COLUMN_NAME = 'expected_payment_method'
);
SET @sql := IF(@col = 0,
    'ALTER TABLE `re_service_charges` ADD COLUMN `expected_payment_method` VARCHAR(30) NULL DEFAULT NULL AFTER `vat_rate`',
    'SELECT ''expected_payment_method exists'' AS msg');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 're_service_charges' AND COLUMN_NAME = 'suspended_effective_date'
);
SET @sql := IF(@col = 0,
    'ALTER TABLE `re_service_charges` ADD COLUMN `suspended_effective_date` DATE NULL DEFAULT NULL AFTER `expected_payment_method`',
    'SELECT ''suspended_effective_date exists'' AS msg');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 're_service_charges' AND COLUMN_NAME = 'cancelled_effective_date'
);
SET @sql := IF(@col = 0,
    'ALTER TABLE `re_service_charges` ADD COLUMN `cancelled_effective_date` DATE NULL DEFAULT NULL AFTER `suspended_effective_date`',
    'SELECT ''cancelled_effective_date exists'' AS msg');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 're_service_charges' AND COLUMN_NAME = 'supersedes_id'
);
SET @sql := IF(@col = 0,
    'ALTER TABLE `re_service_charges` ADD COLUMN `supersedes_id` INT(11) NULL DEFAULT NULL AFTER `cancelled_effective_date`',
    'SELECT ''supersedes_id exists'' AS msg');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 're_service_charges' AND COLUMN_NAME = 'superseded_by_id'
);
SET @sql := IF(@col = 0,
    'ALTER TABLE `re_service_charges` ADD COLUMN `superseded_by_id` INT(11) NULL DEFAULT NULL AFTER `supersedes_id`',
    'SELECT ''superseded_by_id exists'' AS msg');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Ensure apply_mode exists with full enum (formalized; no web ALTER required after this)
SET @col := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 're_service_charges' AND COLUMN_NAME = 'apply_mode'
);
SET @sql := IF(@col = 0,
    'ALTER TABLE `re_service_charges` ADD COLUMN `apply_mode` ENUM(''standalone'',''merge_first_installment'',''split_all_installments'') NOT NULL DEFAULT ''standalone'' AFTER `recurrence_type`',
    'SELECT ''apply_mode exists'' AS msg');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Types VAT defaults
SET @col := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 're_service_charge_types' AND COLUMN_NAME = 'vat_treatment'
);
SET @sql := IF(@col = 0,
    'ALTER TABLE `re_service_charge_types` ADD COLUMN `vat_treatment` ENUM(''standard'',''zero_rated'',''exempt'',''out_of_scope'',''company_default'') NOT NULL DEFAULT ''company_default'' AFTER `recurrence_type`',
    'SELECT ''type vat_treatment exists'' AS msg');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 're_service_charge_types' AND COLUMN_NAME = 'vat_rate'
);
SET @sql := IF(@col = 0,
    'ALTER TABLE `re_service_charge_types` ADD COLUMN `vat_rate` DECIMAL(5,2) NOT NULL DEFAULT 0.00 AFTER `vat_treatment`',
    'SELECT ''type vat_rate exists'' AS msg');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Backfill lifecycle from is_active (safe)
UPDATE `re_service_charges`
SET `lifecycle_status` = IF(`is_active` = 1, 'active', 'cancelled')
WHERE `lifecycle_status` = 'active' AND `is_active` = 0;

-- Backfill contracted_amount from existing billing nets (idempotent; only where NULL)
UPDATE `re_service_charges` sc
LEFT JOIN (
    SELECT company_id, service_charge_id, ROUND(SUM(amount), 2) AS net_sum
    FROM `re_billing_items`
    WHERE item_type = 'service_charge' AND service_charge_id IS NOT NULL
    GROUP BY company_id, service_charge_id
) bi ON bi.service_charge_id = sc.id AND bi.company_id = sc.company_id
SET sc.contracted_amount = bi.net_sum
WHERE sc.contracted_amount IS NULL AND bi.net_sum IS NOT NULL;

-- Settings (ON DUPLICATE keep existing)
INSERT INTO `settings` (`key`, `value`)
VALUES
    ('re_receipt_allocation_priority', 'oldest_due'),
    ('re_service_charge_company_vat_default_treatment', 'exempt'),
    ('re_service_charge_company_vat_default_rate', '0')
ON DUPLICATE KEY UPDATE `value` = `value`;
