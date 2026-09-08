-- Operations field app — several photos or videos in one message, and video.
--
-- WHY A GROUP ID AND NOT ONE BIG REQUEST
-- -------------------------------------
-- The obvious way to send four photos as one message is one request carrying
-- four files. It is the wrong shape here:
--
--   * A message with a 60-second video and three photos is 30-40 MB. That is at
--     or over post_max_size on a shared host, and the failure when it is over is
--     a truncated request PHP reports as "no files", not as "too big".
--   * On one bar of 3G the whole thing retries from zero every time it fails.
--     The person watches a message never send and cannot tell which part is the
--     problem.
--
-- So each file is still its own request, and the message they belong to is named
-- by an id the phone generates: `client_group_id`. The first request to arrive
-- for a group creates the comment; the rest find it and attach to it. Each file
-- retries on its own, arrives on its own, and appears in the thread as it lands
-- — which is what every messaging app already does and what people expect.
--
-- The text rides on every request in the group, not just the first, so a message
-- does not lose its words if the request that happened to be first is the one
-- that could never be delivered.
--
-- Safe to re-run. IF NOT EXISTS on ADD COLUMN/INDEX is MariaDB (this server is
-- MariaDB 11.8); on MySQL 8 drop those clauses and run it once.

-- 1. Video is a third kind of attachment.
ALTER TABLE `ops_job_comment_media`
  MODIFY COLUMN `kind` ENUM('photo','voice','video') NOT NULL;

-- `duration_seconds` was commented as voice-only. It now carries video length
-- too, so a thumbnail can show "0:42" before the file has been fetched.
ALTER TABLE `ops_job_comment_media`
  MODIFY COLUMN `duration_seconds` INT(11) DEFAULT NULL
      COMMENT 'Voice and video: length as the phone measured it';

-- 2. The group id that ties several files into one message.
ALTER TABLE `ops_job_comments`
  ADD COLUMN IF NOT EXISTS `client_group_id` VARCHAR(64) DEFAULT NULL
      COMMENT 'Phone-generated: ties several uploads into one message'
      AFTER `is_material_request`;

-- Two requests from the same group must never create two comments. NULL is
-- allowed many times over in a MariaDB unique index, so every message sent
-- before this migration — and every text-only one after it — is unaffected.
CREATE UNIQUE INDEX IF NOT EXISTS `uniq_ops_comments_group`
  ON `ops_job_comments` (`job_id`, `client_group_id`);
