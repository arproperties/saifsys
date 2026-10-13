-- Finance > Vouchers for the new chart: journal voucher (jv), petty cash (pc),
-- payment voucher (pv), receipt voucher (rv). A voucher is the header; its debit
-- and credit lines live in fin_ledger_entries, which the Trial Balance reads.
-- Deleting a voucher keeps it (status void) and its lines (is_void).
-- fin_voucher_history records who added, edited and deleted each voucher.
-- Run after fin_chart_of_accounts.sql and fin_ledger_entries.sql. Safe to re-run.
CREATE TABLE IF NOT EXISTS `fin_vouchers` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `company_id` int(11) NOT NULL,
  `type` enum('jv','pc','pv','rv') NOT NULL,
  `voucher_no` varchar(20) NOT NULL,
  `voucher_date` date NOT NULL,
  `party` varchar(150) DEFAULT NULL,
  `reference` varchar(100) DEFAULT NULL,
  `narration` varchar(255) DEFAULT NULL,
  `amount` decimal(15,2) NOT NULL,
  `status` enum('posted','void') NOT NULL DEFAULT 'posted',
  `created_by` int(11) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `voided_by` int(11) DEFAULT NULL,
  `voided_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_fin_voucher_no` (`company_id`,`voucher_no`),
  KEY `idx_fin_voucher_list` (`company_id`,`type`,`voucher_date`),
  CONSTRAINT `fk_fin_voucher_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Ledger lines belong to a voucher. journal_no and is_sample belonged to demo
-- lines that no longer exist; those lines go with them.
DELETE FROM `fin_ledger_entries` WHERE
  (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'fin_ledger_entries' AND COLUMN_NAME = 'voucher_id') = 0;

ALTER TABLE `fin_ledger_entries`
  DROP COLUMN IF EXISTS `journal_no`,
  DROP COLUMN IF EXISTS `is_sample`,
  ADD COLUMN IF NOT EXISTS `voucher_id` int(11) NOT NULL AFTER `account_id`,
  ADD COLUMN IF NOT EXISTS `line_no` int(11) NOT NULL DEFAULT 1 AFTER `voucher_id`,
  ADD COLUMN IF NOT EXISTS `is_void` tinyint(1) NOT NULL DEFAULT 0 AFTER `credit`,
  ADD KEY IF NOT EXISTS `idx_fin_le_voucher` (`voucher_id`);

ALTER TABLE `fin_ledger_entries`
  ADD CONSTRAINT `fk_fin_le_voucher` FOREIGN KEY IF NOT EXISTS (`voucher_id`) REFERENCES `fin_vouchers` (`id`);

ALTER TABLE `fin_vouchers`
  DROP COLUMN IF EXISTS `is_sample`;

-- Who added, edited or deleted a voucher, and the voucher before and after.
CREATE TABLE IF NOT EXISTS `fin_voucher_history` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `company_id` int(11) NOT NULL,
  `voucher_id` int(11) NOT NULL,
  `action` enum('created','edited','deleted') NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `before_json` longtext DEFAULT NULL,
  `after_json` longtext DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_fin_vh_voucher` (`voucher_id`),
  KEY `idx_fin_vh_company_time` (`company_id`,`created_at`),
  CONSTRAINT `fk_fin_vh_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Vouchers saved before the history existed: their add, and their delete if any.
INSERT INTO `fin_voucher_history` (`company_id`, `voucher_id`, `action`, `user_id`, `created_at`)
SELECT v.company_id, v.id, 'created', v.created_by, v.created_at
  FROM `fin_vouchers` v
 WHERE NOT EXISTS (SELECT 1 FROM `fin_voucher_history` h WHERE h.voucher_id = v.id AND h.action = 'created');

INSERT INTO `fin_voucher_history` (`company_id`, `voucher_id`, `action`, `user_id`, `created_at`)
SELECT v.company_id, v.id, 'deleted', v.voided_by, COALESCE(v.voided_at, v.created_at)
  FROM `fin_vouchers` v
 WHERE v.status = 'void'
   AND NOT EXISTS (SELECT 1 FROM `fin_voucher_history` h WHERE h.voucher_id = v.id AND h.action = 'deleted');
