-- "Not applicable" for a move-in inspection item (e.g. Balcony in a unit without one).
-- An N/A item counts as done: is_checked = 1 and is_na = 1. It stays on the
-- inspection form marked N/A (removing an item takes it off the form instead).
--
-- Requires migrations/re_move_in_stages.sql. Safe to run more than once.

ALTER TABLE `re_move_in_inspection_items`
  ADD COLUMN IF NOT EXISTS `is_na` TINYINT(1) NOT NULL DEFAULT 0 AFTER `is_checked`;
