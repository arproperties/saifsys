-- ============================================================================
-- Operations — video in the Before/After evidence
-- ============================================================================
-- ops_job_photos held stills only, and the kind was implied by the table's
-- name. Now that a clip can be filed as evidence, the row has to say which it
-- is: the office renders an <img> or a <video> from this, and the app decides
-- whether to draw a play badge on the thumbnail.
--
-- Derived from the extension would have worked and needed no migration, but the
-- serving whitelist and the renderer would then each be re-deciding the same
-- thing from a filename. The row says it once.
--
-- Every existing row is a photo, which is what the default gives them, so this
-- is safe to run on live data with no backfill.

ALTER TABLE `ops_job_photos`
  ADD COLUMN `media_kind` ENUM('photo','video') NOT NULL DEFAULT 'photo'
  COMMENT 'What file_path points at. Set at upload from a validated extension.'
  AFTER `photo_type`;
