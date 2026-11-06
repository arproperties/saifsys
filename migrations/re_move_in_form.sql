-- Move-In Inspection Form (step 5 of the move-in stages)
--
-- The form itself is generated on demand (move_in_form.php) and the signed
-- copy is stored like the other move-in files (re_move_in_photos,
-- file_type = 'inspection_report'). These columns only record the last time
-- the form was emailed to the tenant.
-- 
-- Requires migrations/re_move_in_stages.sql. Safe to run more than once.

ALTER TABLE `re_move_ins`
  ADD COLUMN IF NOT EXISTS `form_sent_at` DATETIME DEFAULT NULL AFTER `keys_received_by_tenant`,
  ADD COLUMN IF NOT EXISTS `form_sent_to` VARCHAR(255) DEFAULT NULL AFTER `form_sent_at`,
  ADD COLUMN IF NOT EXISTS `form_sent_by` INT(11) DEFAULT NULL AFTER `form_sent_to`;

