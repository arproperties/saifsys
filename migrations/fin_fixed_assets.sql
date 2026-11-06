-- Finance > Fixed Assets and monthly scheduled entries.
-- fin_assets is the asset list: cost, useful life and the date depreciation
-- starts; it is written off in equal months, part months by days, the last
-- month taking what is left. post_from is the first month the system posts;
-- months before it were depreciated before the system (in the opening balances).
-- fin_auto_entries holds each month's entry from a schedule (an asset now, a
-- prepaid / accrued allocation later): made automatically as 'pending', posted
-- as a Fixed Journal Voucher (fj) only when Accounts approve it, or 'skipped'.
-- Run after fin_supplier_payments.sql. Safe to re-run.
ALTER TABLE `fin_vouchers`
  MODIFY `type` enum('jv','pc','pv','rv','si','fj','aj') NOT NULL;

CREATE TABLE IF NOT EXISTS `fin_assets` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `company_id` int(11) NOT NULL,
  `name` varchar(150) NOT NULL,
  `asset_account_id` int(11) NOT NULL,
  `accum_account_id` int(11) NOT NULL,
  `expense_account_id` int(11) NOT NULL,
  `cost` decimal(15,2) NOT NULL,
  `life_months` int(11) NOT NULL,
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
  KEY `idx_fin_asset_company` (`company_id`,`status`),
  KEY `idx_fin_asset_source` (`source_voucher_id`),
  CONSTRAINT `fk_fin_asset_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`),
  CONSTRAINT `fk_fin_asset_acc` FOREIGN KEY (`asset_account_id`) REFERENCES `fin_coa_accounts` (`id`),
  CONSTRAINT `fk_fin_asset_accum` FOREIGN KEY (`accum_account_id`) REFERENCES `fin_coa_accounts` (`id`),
  CONSTRAINT `fk_fin_asset_exp` FOREIGN KEY (`expense_account_id`) REFERENCES `fin_coa_accounts` (`id`),
  CONSTRAINT `fk_fin_asset_source` FOREIGN KEY (`source_voucher_id`) REFERENCES `fin_vouchers` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `fin_auto_entries` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `company_id` int(11) NOT NULL,
  `source_type` enum('asset','allocation') NOT NULL,
  `source_id` int(11) NOT NULL,
  `period` date NOT NULL,
  `entry_date` date NOT NULL,
  `amount` decimal(15,2) NOT NULL,
  `narration` varchar(255) NOT NULL,
  `lines_json` text NOT NULL,
  `status` enum('pending','posted','skipped') NOT NULL DEFAULT 'pending',
  `voucher_id` int(11) DEFAULT NULL,
  `decided_by` int(11) DEFAULT NULL,
  `decided_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_fin_auto_period` (`source_type`,`source_id`,`period`),
  KEY `idx_fin_auto_company` (`company_id`,`status`,`entry_date`),
  KEY `idx_fin_auto_voucher` (`voucher_id`),
  CONSTRAINT `fk_fin_auto_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`),
  CONSTRAINT `fk_fin_auto_voucher` FOREIGN KEY (`voucher_id`) REFERENCES `fin_vouchers` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
