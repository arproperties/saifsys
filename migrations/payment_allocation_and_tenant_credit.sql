-- Payment allocation across multiple installments + tenant credit balance
-- Safe to run: checks for existing tables/columns

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";

-- 1) Ensure re_lease_installments has 'partial' status
SET @col_check = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 're_lease_installments' AND COLUMN_NAME = 'status');
SET @enum_has_partial = (SELECT COLUMN_TYPE FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 're_lease_installments' AND COLUMN_NAME = 'status');
SET @sql = IF(@col_check > 0 AND (@enum_has_partial NOT LIKE '%partial%'),
    'ALTER TABLE `re_lease_installments` MODIFY COLUMN `status` ENUM(''pending'',''paid'',''overdue'',''waived'',''partial'') NOT NULL DEFAULT ''pending''',
    'SELECT "re_lease_installments.status already has partial or column missing" AS msg');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 2) Payment allocations: one payment can be split across multiple installments
CREATE TABLE IF NOT EXISTS `re_payment_allocations` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `payment_id` int(11) NOT NULL,
  `installment_id` int(11) NOT NULL,
  `amount_allocated` decimal(12,2) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_payment` (`payment_id`),
  KEY `idx_installment` (`installment_id`),
  CONSTRAINT `fk_payment_alloc_payment` FOREIGN KEY (`payment_id`) REFERENCES `re_payments` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_payment_alloc_installment` FOREIGN KEY (`installment_id`) REFERENCES `re_lease_installments` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- 3) Tenant credit balance (overpayments / advance)
CREATE TABLE IF NOT EXISTS `re_tenant_credit_balances` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `tenant_id` int(11) NOT NULL,
  `company_id` int(11) NOT NULL,
  `balance_aed` decimal(12,2) NOT NULL DEFAULT 0.00,
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_tenant_company` (`tenant_id`, `company_id`),
  KEY `idx_tenant` (`tenant_id`),
  KEY `idx_company` (`company_id`),
  CONSTRAINT `fk_credit_balance_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `re_tenants` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_credit_balance_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- 4) Tenant credit transactions (audit trail)
CREATE TABLE IF NOT EXISTS `re_tenant_credit_transactions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `tenant_id` int(11) NOT NULL,
  `company_id` int(11) NOT NULL,
  `amount_aed` decimal(12,2) NOT NULL,
  `type` enum('credit','debit') NOT NULL COMMENT 'credit=added (overpayment), debit=applied to installment',
  `payment_id` int(11) DEFAULT NULL,
  `installment_id` int(11) DEFAULT NULL,
  `reference` varchar(255) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_tenant` (`tenant_id`),
  KEY `idx_company` (`company_id`),
  KEY `idx_payment` (`payment_id`),
  KEY `idx_created` (`created_at`),
  CONSTRAINT `fk_credit_txn_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `re_tenants` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_credit_txn_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_credit_txn_payment` FOREIGN KEY (`payment_id`) REFERENCES `re_payments` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- 5) Ensure re_payments.payment_method ENUM accepts all methods (avoids "Data truncated" on submit)
ALTER TABLE `re_payments`
  MODIFY COLUMN `payment_method` ENUM('cash', 'bank_transfer', 'cheque', 'auto_debit', 'cash_deposit') NOT NULL DEFAULT 'bank_transfer';

-- 6) Accounting: Tenant Advance / Tenant Credit (Liability 2120) for overpayments
-- Add account 2120 for each company that has chart of accounts and does not yet have 2120
INSERT INTO re_chart_of_accounts
  (company_id, account_code, account_name, account_type, parent_id, normal_balance, is_header, description, is_active)
SELECT DISTINCT coa.company_id, '2120', 'Tenant Advances / Tenant Credit', 'Liability', NULL, 'credit', 0,
  'Tenant overpayments and advance rent held as liability until applied to future installments', 1
FROM re_chart_of_accounts coa
WHERE NOT EXISTS (SELECT 1 FROM re_chart_of_accounts x WHERE x.company_id = coa.company_id AND x.account_code = '2120');
