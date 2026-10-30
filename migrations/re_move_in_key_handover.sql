-- When the keys of a move-in are handed over (step 4 of the move-in stages):
--   move_in    at move-in, confirmed before the inspection form (default)
--   scheduled  on key_handover_scheduled_at, confirmed when it happens
--   on_signing when the tenant signs the inspection form: confirmed
--              automatically when the signed form is uploaded (step 5)
--
-- Requires migrations/re_move_in_stages.sql. Safe to run more than once.

ALTER TABLE `re_move_ins`
  ADD COLUMN IF NOT EXISTS `key_handover_mode` VARCHAR(20) NOT NULL DEFAULT 'move_in' AFTER `keys_handed_over_at`,
  ADD COLUMN IF NOT EXISTS `key_handover_scheduled_at` DATETIME DEFAULT NULL AFTER `key_handover_mode`;
