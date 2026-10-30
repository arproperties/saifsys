-- Tenant digital signature on the move-in / move-out inspection forms
--
-- The tenant signs on screen (Digital Sign). The signature image is saved in
-- the move-in / move-out upload folder and placed in the Tenant signature box
-- of the form; the signed PDF is filed as the signed copy (file_type
-- 'inspection_report'), which completes the form step.
--
-- Safe to run more than once.

ALTER TABLE `re_move_ins`
  ADD COLUMN IF NOT EXISTS `tenant_signature_path` VARCHAR(500) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `tenant_signed_name` VARCHAR(150) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `tenant_signed_at` DATETIME DEFAULT NULL;

ALTER TABLE `re_move_outs`
  ADD COLUMN IF NOT EXISTS `tenant_signature_path` VARCHAR(500) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `tenant_signed_name` VARCHAR(150) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `tenant_signed_at` DATETIME DEFAULT NULL;
