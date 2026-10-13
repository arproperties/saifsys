-- Finance > Chart of Accounts (New). One chart per company, kept apart from the
-- older chart_of_accounts table: nothing here reads or writes that table.
-- Codes are 7 digits: class, group, sub-group, category, then a 3-digit ledger
-- number (1112001). A code ending in 000 is a heading; only ledgers take postings.
-- A code ending in 999 is a total line and takes no postings.
-- Safe to re-run.
CREATE TABLE IF NOT EXISTS `fin_coa_accounts` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `company_id` int(11) NOT NULL,
  `code` char(7) NOT NULL,
  `name` varchar(150) NOT NULL,
  `level` enum('class','group','sub_group','category','ledger','total') NOT NULL,
  `parent_id` int(11) DEFAULT NULL,
  `normal_balance` enum('debit','credit') NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_by` int(11) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_fin_coa_company_code` (`company_id`,`code`),
  KEY `idx_fin_coa_parent` (`parent_id`),
  CONSTRAINT `fk_fin_coa_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`),
  CONSTRAINT `fk_fin_coa_parent` FOREIGN KEY (`parent_id`) REFERENCES `fin_coa_accounts` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Charts created before total lines existed.
ALTER TABLE `fin_coa_accounts`
  MODIFY `level` enum('class','group','sub_group','category','ledger','total') NOT NULL;
