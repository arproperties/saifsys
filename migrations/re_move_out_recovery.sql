-- Move-Out: Final Recovery Invoice
--
-- When the damages and standard move-out charges are more than the security
-- deposit, the shortfall is charged to the tenant on the (expired / terminated)
-- lease from the move-out: a one-off service charge + billing item, which the
-- invoice engine turns into a receivable like any other lease charge. The
-- move-out keeps the link and shows Due / Partly paid / Paid. The move-out
-- cannot be completed while a shortfall has not been invoiced.
--
-- Safe to run more than once.

ALTER TABLE `re_move_outs`
  ADD COLUMN IF NOT EXISTS `recovery_service_charge_id` INT(11) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `recovery_billing_item_id` INT(11) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `recovery_amount` DECIMAL(12,2) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `recovery_raised_at` DATETIME DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `recovery_raised_by` INT(11) DEFAULT NULL;
