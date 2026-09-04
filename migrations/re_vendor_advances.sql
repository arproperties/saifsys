-- ============================================================================
-- Real Estate Vendor Advances / Unapplied Payments (additive)
-- ----------------------------------------------------------------------------
-- - Adds advance_amount on vendor payments (original advance portion)
-- - Vendor advance balances + applications (source-payment linked)
-- - Seeds COA 1410 under Prepaid Expenses (1500) or Current Assets (1000)
-- - Does not modify existing account rows
-- ============================================================================

SET @col_exists := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 're_vendor_payments'
      AND COLUMN_NAME = 'advance_amount'
);
SET @sql := IF(@col_exists = 0,
    'ALTER TABLE `re_vendor_payments` ADD COLUMN `advance_amount` DECIMAL(15,2) NOT NULL DEFAULT 0.00 AFTER `amount`',
    'SELECT ''advance_amount already exists'' AS msg'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

CREATE TABLE IF NOT EXISTS `re_vendor_advance_balances` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `company_id` INT(11) NOT NULL,
    `vendor_id` INT(11) NOT NULL,
    `balance_aed` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_re_vendor_advance_bal` (`company_id`, `vendor_id`),
    KEY `idx_re_vendor_advance_bal_vendor` (`vendor_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Available vendor advance balance by company/vendor.';

CREATE TABLE IF NOT EXISTS `re_vendor_advance_applications` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `company_id` INT(11) NOT NULL,
    `vendor_id` INT(11) NOT NULL,
    `vendor_payment_id` BIGINT UNSIGNED NOT NULL,
    `vendor_invoice_id` INT(11) NOT NULL,
    `amount` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    `journal_id` INT(11) DEFAULT NULL,
    `status` ENUM('posted','reversed') NOT NULL DEFAULT 'posted',
    `created_by` INT(11) DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_re_adv_app_company_vendor` (`company_id`, `vendor_id`, `status`),
    KEY `idx_re_adv_app_payment` (`company_id`, `vendor_payment_id`, `status`),
    KEY `idx_re_adv_app_bill` (`company_id`, `vendor_invoice_id`, `status`),
    KEY `idx_re_adv_app_journal` (`journal_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Vendor advance applications to bills; one row per source payment.';

INSERT INTO `re_chart_of_accounts`
    (`company_id`, `account_code`, `account_name`, `account_type`, `parent_id`, `normal_balance`, `is_header`, `description`, `is_active`)
SELECT
    coa.company_id,
    '1410',
    'Vendor Advances / Prepayments',
    'Asset',
    COALESCE(
        (SELECT p1500.id FROM re_chart_of_accounts p1500
         WHERE p1500.company_id = coa.company_id AND p1500.account_code = '1500' LIMIT 1),
        (SELECT p1000.id FROM re_chart_of_accounts p1000
         WHERE p1000.company_id = coa.company_id AND p1000.account_code = '1000' LIMIT 1)
    ),
    'debit',
    0,
    'Vendor advances and prepayments held as current asset until applied to vendor bills',
    1
FROM (
    SELECT DISTINCT company_id FROM re_chart_of_accounts
) coa
WHERE NOT EXISTS (
    SELECT 1 FROM re_chart_of_accounts x
    WHERE x.company_id = coa.company_id AND x.account_code = '1410'
)
AND (
    EXISTS (SELECT 1 FROM re_chart_of_accounts h WHERE h.company_id = coa.company_id AND h.account_code = '1500')
    OR EXISTS (SELECT 1 FROM re_chart_of_accounts h WHERE h.company_id = coa.company_id AND h.account_code = '1000')
);

INSERT INTO `settings` (`key`, `value`)
VALUES ('re_vendor_advance_account_code', '1410')
ON DUPLICATE KEY UPDATE `value` = `value`;
