-- ============================================================================
-- Add deposit_bank_account_id to PDC / lease cheque tables
-- ----------------------------------------------------------------------------
-- Purpose:
--   Persist which company bank account a bounced cheque was deposited to
--   (for operational record + bank reco guidance). Additive only.
-- ============================================================================

ALTER TABLE `re_post_dated_cheques`
  ADD COLUMN `deposit_bank_account_id` INT(11) NULL DEFAULT NULL
    COMMENT 'Company re_bank_accounts.id where cheque was deposited / bounced'
    AFTER `bank_name`,
  ADD KEY `idx_re_pdc_deposit_bank` (`company_id`, `deposit_bank_account_id`);

ALTER TABLE `re_lease_cheques`
  ADD COLUMN `deposit_bank_account_id` INT(11) NULL DEFAULT NULL
    COMMENT 'Company re_bank_accounts.id where cheque was deposited / bounced'
    AFTER `bank_name`,
  ADD KEY `idx_re_lease_cheques_deposit_bank` (`deposit_bank_account_id`);
