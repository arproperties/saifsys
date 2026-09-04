-- Phase 2 — Cleaning AR: deposit account (chart_of_accounts.account_no) on receipts
-- Run once on live/dev after backup.

ALTER TABLE `receipts`
  ADD COLUMN `deposit_account_no` VARCHAR(10) NULL DEFAULT NULL
  COMMENT 'COA account_no for cash/bank debit; NULL = legacy infer from method'
  AFTER `method`;
