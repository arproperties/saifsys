-- Optional remark per move-in inspection item, typed by the user
-- (kept apart from `note`, which the system fills, e.g. "Signed form uploaded").
-- Printed on the inspection form only when "Item remarks" is included.
--
-- Requires migrations/re_move_in_stages.sql. Safe to run more than once.

ALTER TABLE `re_move_in_inspection_items`
  ADD COLUMN IF NOT EXISTS `remark` VARCHAR(500) DEFAULT NULL AFTER `note`;
