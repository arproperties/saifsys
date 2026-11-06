-- Finance > Allocations: prepaid expenses and accrued revenue spread over
-- months. Each schedule makes one entry a month (fin_auto_entries, source_type
-- 'allocation'), pending until Accounts approve it, then posted as an
-- Allocation Journal Voucher (aj): Dr debit_account, Cr credit_account.
--   prepaid  Dr the expense, Cr the prepaid (asset) account
--   accrued  Dr the accrued revenue (asset) account, Cr the revenue
-- Spread like assets: equal months, part months by days, the last month takes
-- what is left. post_from: earlier months are already in the opening balances.
-- Run after fin_fixed_assets.sql. Safe to re-run.
CREATE TABLE IF NOT EXISTS `fin_alloc_schedules` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `company_id` int(11) NOT NULL,
  `kind` enum('prepaid','accrued') NOT NULL,
  `name` varchar(150) NOT NULL,
  `debit_account_id` int(11) NOT NULL,
  `credit_account_id` int(11) NOT NULL,
  `amount` decimal(15,2) NOT NULL,
  `months` int(11) NOT NULL,
  `start_date` date NOT NULL,
  `post_from` date NOT NULL,
  `status` enum('active','stopped') NOT NULL DEFAULT 'active',
  `stop_date` date DEFAULT NULL,
  `source_voucher_id` int(11) DEFAULT NULL,
  `notes` varchar(255) DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_by` int(11) DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_fin_alc_company` (`company_id`,`status`),
  KEY `idx_fin_alc_source` (`source_voucher_id`),
  CONSTRAINT `fk_fin_alc_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`),
  CONSTRAINT `fk_fin_alc_debit` FOREIGN KEY (`debit_account_id`) REFERENCES `fin_coa_accounts` (`id`),
  CONSTRAINT `fk_fin_alc_credit` FOREIGN KEY (`credit_account_id`) REFERENCES `fin_coa_accounts` (`id`),
  CONSTRAINT `fk_fin_alc_source` FOREIGN KEY (`source_voucher_id`) REFERENCES `fin_vouchers` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
