-- Move-Out: signed Inspection Report (Form 1) uploads
--
-- The tenant-signed copy of the Move-Out Inspection Report is kept with the
-- move-out photos (re_move_out_photos), told apart by file_type:
--   photo              condition / damage photo (default, all existing rows)
--   inspection_report  signed Form 1 (PDF or image); completes the
--                      Inspection Report step
--
-- Safe to run more than once.

ALTER TABLE `re_move_out_photos`
  ADD COLUMN IF NOT EXISTS `file_type` VARCHAR(30) NOT NULL DEFAULT 'photo' AFTER `move_out_id`;
