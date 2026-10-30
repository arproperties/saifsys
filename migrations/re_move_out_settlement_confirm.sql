-- Move-Out Deposit Settlement: operations confirmation only.
--
-- The move-out shows the deposit breakdown (received, damages, standard
-- charges, refund due / amount payable) and staff mark the settlement as
-- complete once the Accounts team has settled it manually. No accounting is
-- posted from the move-out.
--
-- Safe to run more than once.

ALTER TABLE `re_move_outs`
  ADD COLUMN IF NOT EXISTS `settlement_completed_at` DATETIME DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `settlement_completed_by` INT(11) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `settlement_note` VARCHAR(500) DEFAULT NULL;
