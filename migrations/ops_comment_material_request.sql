-- Operations field app — asking for materials in the conversation.
--
-- WHY THIS EXISTS
-- ---------------
-- The field app used to ask for materials through a form: material name,
-- quantity, unit. That form is the wrong question to put in front of the people
-- who use this app. Many of them cannot write English, and none of them are
-- doing stock-keeping while standing in a stairwell — they just need to say
-- "I have run out of bleach". Asking them to spell it, count it and name its
-- unit stopped them cold.
--
-- So the request moved into the conversation, where they can say it out loud,
-- photograph the empty bottle, or type it if they can. One flag on the message
-- marks it as "I need something", which is the whole of what the phone has to
-- know. The structured record — the name, the quantity, the unit, and whether
-- it was approved — is filled in by an admin in the Operations web module,
-- because that is tracking, and tracking is the office's job.
--
-- Later, the per-job record went too — see ops_drop_job_materials.sql. The
-- office hands the thing over and takes it off the Stock page, and that
-- movement is the only record kept.
--
-- Safe to re-run. IF NOT EXISTS on ADD COLUMN is MariaDB (and this server is
-- MariaDB 11.8); on MySQL 8 drop that clause and run it once.

ALTER TABLE `ops_job_comments`
  ADD COLUMN IF NOT EXISTS `is_material_request` TINYINT(1) NOT NULL DEFAULT 0
      COMMENT 'This message is someone asking the office for something'
      AFTER `comment`;

-- The office's pending-requests view reads ops_jobs.needs_materials. A flagged
-- message sets it, exactly as the old form did, so nothing downstream of that
-- flag has to change.
CREATE INDEX IF NOT EXISTS `idx_ops_comments_material`
  ON `ops_job_comments` (`job_id`, `is_material_request`);
