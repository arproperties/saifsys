-- Finance > Trial Balance. Debit and credit lines posted against the new chart
-- (fin_coa_accounts). fin_vouchers.sql, run next, ties each line to its voucher.
-- Run after fin_chart_of_accounts.sql. Safe to re-run.
CREATE TABLE IF NOT EXISTS `fin_ledger_entries` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `company_id` int(11) NOT NULL,
  `account_id` int(11) NOT NULL,
  `entry_date` date NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `debit` decimal(15,2) NOT NULL DEFAULT 0.00,
  `credit` decimal(15,2) NOT NULL DEFAULT 0.00,
  `created_by` int(11) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_fin_le_company_date` (`company_id`,`entry_date`),
  KEY `idx_fin_le_account` (`account_id`),
  CONSTRAINT `fk_fin_le_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`),
  CONSTRAINT `fk_fin_le_account` FOREIGN KEY (`account_id`) REFERENCES `fin_coa_accounts` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
