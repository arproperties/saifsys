-- Add is_reconciled column to re_general_ledger for bank reconciliation
ALTER TABLE `re_general_ledger` 
ADD COLUMN `is_reconciled` TINYINT(1) DEFAULT 0 AFTER `reference`,
ADD COLUMN `reconciled_at` DATETIME DEFAULT NULL AFTER `is_reconciled`,
ADD COLUMN `reconciled_by` INT(11) DEFAULT NULL AFTER `reconciled_at`,
ADD KEY `idx_reconciled` (`is_reconciled`);
