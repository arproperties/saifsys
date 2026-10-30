-- Cancel / void a move-in or move-out made by mistake (move_in_view.php, move_out_view.php).
-- The record is kept with status 'cancelled'; these columns say who did it, when and why.
--
-- Safe to run more than once.

ALTER TABLE `re_move_ins`
  ADD COLUMN IF NOT EXISTS `cancelled_at` DATETIME DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `cancelled_by` INT(11) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `cancel_reason` VARCHAR(500) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `cancelled_from_status` VARCHAR(20) DEFAULT NULL;

ALTER TABLE `re_move_outs`
  ADD COLUMN IF NOT EXISTS `cancelled_at` DATETIME DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `cancelled_by` INT(11) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `cancel_reason` VARCHAR(500) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `cancelled_from_status` VARCHAR(20) DEFAULT NULL;
