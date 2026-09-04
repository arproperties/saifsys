-- ============================================================================
-- Extra Service Charges: independent Payment Plan (collection only)
-- Does NOT replace monthly billing items / obligations / invoices.
-- ============================================================================

CREATE TABLE IF NOT EXISTS `re_service_charge_payment_plans` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `company_id` INT(11) NOT NULL,
    `service_charge_id` INT(11) NOT NULL,
    `lease_id` INT(11) NOT NULL,
    `status` ENUM('draft','active','cancelled','completed') NOT NULL DEFAULT 'active',
    `planned_total` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    `expected_method` VARCHAR(30) DEFAULT NULL,
    `notes` TEXT DEFAULT NULL,
    `created_by` INT(11) DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_re_sc_plan_charge` (`company_id`, `service_charge_id`, `status`),
    KEY `idx_re_sc_plan_lease` (`company_id`, `lease_id`, `status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Collection payment plans for Extra Service Charges (independent of monthly revenue schedule).';

CREATE TABLE IF NOT EXISTS `re_service_charge_payment_plan_lines` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `company_id` INT(11) NOT NULL,
    `plan_id` BIGINT UNSIGNED NOT NULL,
    `line_no` INT(11) NOT NULL DEFAULT 1,
    `due_date` DATE NOT NULL,
    `amount` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    `status` ENUM('planned','registered','partially_collected','collected','cancelled') NOT NULL DEFAULT 'planned',
    `cheque_id` INT(11) DEFAULT NULL,
    `notes` VARCHAR(255) DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_re_sc_plan_line_plan` (`company_id`, `plan_id`, `line_no`),
    KEY `idx_re_sc_plan_line_due` (`company_id`, `due_date`, `status`),
    KEY `idx_re_sc_plan_line_cheque` (`cheque_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Expected collection lines for a service charge payment plan.';

-- Optional FK-style link from PDC to plan line (nullable; rent PDCs ignore)
SET @col := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 're_post_dated_cheques' AND COLUMN_NAME = 'service_payment_plan_line_id'
);
SET @sql := IF(@col = 0,
    'ALTER TABLE `re_post_dated_cheques` ADD COLUMN `service_payment_plan_line_id` BIGINT UNSIGNED NULL DEFAULT NULL AFTER `billing_item_id`, ADD KEY `idx_pdc_sc_plan_line` (`service_payment_plan_line_id`)',
    'SELECT ''service_payment_plan_line_id exists'' AS msg');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
