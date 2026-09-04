-- Phase 3 follow-up: bank master details for cleaning reconciliation
-- Adds optional bank metadata while preserving existing links to chart_of_accounts.

SET NAMES utf8mb4;

ALTER TABLE `cleaning_bank_accounts`
  ADD COLUMN `bank_name` varchar(150) DEFAULT NULL AFTER `name`,
  ADD COLUMN `account_name` varchar(150) DEFAULT NULL AFTER `bank_name`,
  ADD COLUMN `account_number` varchar(80) DEFAULT NULL AFTER `account_name`,
  ADD COLUMN `iban` varchar(80) DEFAULT NULL AFTER `account_number`,
  ADD COLUMN `swift_bic` varchar(20) DEFAULT NULL AFTER `iban`,
  ADD COLUMN `branch_name` varchar(120) DEFAULT NULL AFTER `swift_bic`;
