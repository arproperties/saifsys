-- Operations field app — photos and voice notes inside the job conversation.
--
-- WHY A NEW TABLE
-- ---------------
-- ops_job_photos is the before/after *evidence* that the work happened. It
-- carries its own phase rules (a Before photo locks the moment the job starts)
-- and the office reads it as a record. A photo attached to a message is not
-- that: it is someone saying "look at this tap". Overloading one table would
-- put both meanings behind the same rules and neither would stay true.
--
-- WHY ops_job_comments IS NOT ALTERED
-- -----------------------------------
-- A message can be a photo or a voice note with no text. The obvious change is
-- to make `comment` nullable — but modules/operations/job_view.php renders it
-- through htmlspecialchars(), which deprecation-warns on null in PHP 8.1+, and
-- that file is not part of this change. An empty string carries exactly the
-- same meaning with no ALTER on a live table and nothing else to break.
-- `comment = ''` therefore means "this message is its attachment".
--
-- One media row per message today (the app sends one attachment per message —
-- see api/mobile/ops/ops_endpoints.php). The comment_id foreign key allows
-- several later without another migration.

CREATE TABLE IF NOT EXISTS `ops_job_comment_media` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `comment_id` INT(11) NOT NULL,
  -- job_id and company_id are denormalised from the comment on purpose: every
  -- query in the API is scoped by company, and a scope that has to be reached
  -- through a JOIN is a scope somebody eventually forgets to apply.
  `job_id` INT(11) NOT NULL,
  `company_id` INT(11) NOT NULL,
  `kind` ENUM('photo','voice') NOT NULL,
  `file_path` VARCHAR(500) NOT NULL COMMENT 'Relative to the app root, under uploads/operations',
  -- Voice only. Recorded by the phone, so the player can show a length before
  -- the file has been fetched. NULL on photos.
  `duration_seconds` INT(11) DEFAULT NULL,
  `uploaded_by` INT(11) DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_ops_cmedia_comment` (`comment_id`),
  KEY `idx_ops_cmedia_job` (`job_id`, `created_at`),
  CONSTRAINT `fk_ops_cmedia_comment` FOREIGN KEY (`comment_id`)
      REFERENCES `ops_job_comments`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Deliberately NOT stored: the MIME type. Nothing may ever serve one of these
-- files with a Content-Type read from the database or sniffed from the bytes.
-- The serving route derives it from an extension whitelist and nothing else —
-- see ops_comment_media_content_type() in
-- modules/operations/includes/ops_helper.php.
