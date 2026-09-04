-- ============================================================================
-- Real Estate Phase 9.5 - Recurring Vendor Bills
-- Additive recurring bill templates. Due templates are generated safely when AP pages are opened.
-- ============================================================================

CREATE TABLE IF NOT EXISTS `re_vendor_recurring_bills` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `company_id` INT(11) NOT NULL,
    `vendor_invoice_id` INT(11) DEFAULT NULL COMMENT 'Source bill used as template',
    `vendor_id` INT(11) NOT NULL,
    `template_name` VARCHAR(255) NOT NULL,
    `invoice_number_prefix` VARCHAR(100) DEFAULT NULL,
    `frequency` ENUM('weekly','monthly','quarterly','semi_annual','annual') NOT NULL DEFAULT 'monthly',
    `next_bill_date` DATE DEFAULT NULL,
    `end_date` DATE DEFAULT NULL,
    `due_days` INT(11) NOT NULL DEFAULT 0,
    `payment_terms` VARCHAR(100) DEFAULT NULL,
    `place_of_supply` VARCHAR(100) DEFAULT 'Dubai',
    `vat_treatment` ENUM('vat_registered','non_vat','exempt','out_of_scope') NOT NULL DEFAULT 'vat_registered',
    `order_number` VARCHAR(100) DEFAULT NULL,
    `permit_number` VARCHAR(100) DEFAULT NULL,
    `notes` TEXT DEFAULT NULL,
    `subtotal` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    `tax_amount` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    `total_amount` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    `lines_json` LONGTEXT DEFAULT NULL,
    `last_generated_bill_date` DATE DEFAULT NULL,
    `last_generated_invoice_id` INT(11) DEFAULT NULL,
    `status` ENUM('active','paused','cancelled') NOT NULL DEFAULT 'active',
    `created_by` INT(11) DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_re_vendor_recurring_company` (`company_id`, `status`, `next_bill_date`),
    KEY `idx_re_vendor_recurring_vendor` (`company_id`, `vendor_id`),
    KEY `idx_re_vendor_recurring_source` (`vendor_invoice_id`),
    KEY `idx_re_vendor_recurring_generated` (`last_generated_invoice_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Recurring vendor bill templates for Real Estate AP. Manual generation workflow can be added later.';

ALTER TABLE `re_vendor_recurring_bills`
    MODIFY COLUMN `frequency` ENUM('weekly','monthly','quarterly','semi_annual','annual') NOT NULL DEFAULT 'monthly',
    ADD COLUMN IF NOT EXISTS `invoice_number_prefix` VARCHAR(100) DEFAULT NULL AFTER `template_name`,
    ADD COLUMN IF NOT EXISTS `due_days` INT(11) NOT NULL DEFAULT 0 AFTER `end_date`,
    ADD COLUMN IF NOT EXISTS `payment_terms` VARCHAR(100) DEFAULT NULL AFTER `due_days`,
    ADD COLUMN IF NOT EXISTS `place_of_supply` VARCHAR(100) DEFAULT 'Dubai' AFTER `payment_terms`,
    ADD COLUMN IF NOT EXISTS `vat_treatment` ENUM('vat_registered','non_vat','exempt','out_of_scope') NOT NULL DEFAULT 'vat_registered' AFTER `place_of_supply`,
    ADD COLUMN IF NOT EXISTS `order_number` VARCHAR(100) DEFAULT NULL AFTER `vat_treatment`,
    ADD COLUMN IF NOT EXISTS `permit_number` VARCHAR(100) DEFAULT NULL AFTER `order_number`,
    ADD COLUMN IF NOT EXISTS `notes` TEXT DEFAULT NULL AFTER `permit_number`,
    ADD COLUMN IF NOT EXISTS `subtotal` DECIMAL(15,2) NOT NULL DEFAULT 0.00 AFTER `notes`,
    ADD COLUMN IF NOT EXISTS `tax_amount` DECIMAL(15,2) NOT NULL DEFAULT 0.00 AFTER `subtotal`,
    ADD COLUMN IF NOT EXISTS `total_amount` DECIMAL(15,2) NOT NULL DEFAULT 0.00 AFTER `tax_amount`,
    ADD COLUMN IF NOT EXISTS `lines_json` LONGTEXT DEFAULT NULL AFTER `total_amount`,
    ADD COLUMN IF NOT EXISTS `last_generated_bill_date` DATE DEFAULT NULL AFTER `lines_json`,
    ADD COLUMN IF NOT EXISTS `last_generated_invoice_id` INT(11) DEFAULT NULL AFTER `last_generated_bill_date`;

ALTER TABLE `re_vendor_recurring_bills`
    ADD INDEX IF NOT EXISTS `idx_re_vendor_recurring_generated` (`last_generated_invoice_id`);

