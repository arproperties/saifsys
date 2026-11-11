-- Finance > PDC Receivables: a tenant's post-dated cheque moves from the tenant's
-- debtor account to PDC Receivables on its cheque date, as a PDC Receivable voucher
-- (pdc, numbered PDCR-YYYY-NNNN). fin_pdc_entries keeps one row per cheque posted,
-- so a cheque is never posted twice.
-- Run after fin_lease_vouchers.sql. Safe to re-run.
ALTER TABLE `fin_vouchers`
  MODIFY `type` enum('jv','pc','pv','rv','si','fj','aj','lv','pdc') NOT NULL;

CREATE TABLE IF NOT EXISTS `fin_pdc_entries` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `company_id` int(11) NOT NULL,
  `cheque_id` int(11) NOT NULL,
  `lease_id` int(11) NOT NULL,
  `voucher_id` int(11) DEFAULT NULL,
  `amount` decimal(15,2) NOT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_fin_pdc_cheque` (`cheque_id`),
  KEY `idx_fin_pdc_lease` (`company_id`, `lease_id`),
  KEY `idx_fin_pdc_voucher` (`voucher_id`),
  CONSTRAINT `fk_fin_pdc_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`),
  CONSTRAINT `fk_fin_pdc_voucher` FOREIGN KEY (`voucher_id`) REFERENCES `fin_vouchers` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
