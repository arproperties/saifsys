-- Operations — photos and video attached to a stock movement.
--
-- WHY A NEW TABLE
-- ---------------
-- ops_job_comment_media hangs off a comment: it is what somebody *said*. A
-- photo taken while material is handed over is not a message — nobody typed
-- anything and there is no thread it belongs to. It is evidence against the
-- movement: the meter reading, the drum before it was opened, the signature on
-- the handover slip. Hanging it off the movement is what makes it survive as
-- the movement's own record, readable from the job and from the item's
-- history, and deleted with the movement rather than with a conversation.
--
-- A movement is not always on a job — a stock take or a delivery has no job_id
-- — so job_id is nullable here exactly as it is on ops_stock_moves.
--
-- No `duration_seconds`: nothing here is a voice note, and a length is only
-- worth storing when it is shown before the file is fetched. The video tiles
-- on this side are small and load their own first frame.

CREATE TABLE IF NOT EXISTS `ops_stock_move_media` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `move_id` INT(11) NOT NULL,
  -- Denormalised from the movement for the same reason it is on the comment
  -- media table: a scope reached through a JOIN is a scope somebody eventually
  -- forgets to apply.
  `company_id` INT(11) NOT NULL,
  `job_id` INT(11) DEFAULT NULL,
  `kind` ENUM('photo','video') NOT NULL,
  `file_path` VARCHAR(500) NOT NULL COMMENT 'Relative to the app root, under uploads/operations',
  `uploaded_by` INT(11) DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_ops_smedia_move` (`move_id`),
  KEY `idx_ops_smedia_company` (`company_id`, `created_at`),
  KEY `idx_ops_smedia_job` (`job_id`),
  CONSTRAINT `fk_ops_smedia_move` FOREIGN KEY (`move_id`)
      REFERENCES `ops_stock_moves`(`id`) ON DELETE CASCADE,
  -- Matches ops_stock_moves.job_id: deleting a job empties the column rather
  -- than taking the movement — and now its evidence — with it.
  CONSTRAINT `fk_ops_smedia_job` FOREIGN KEY (`job_id`)
      REFERENCES `ops_jobs`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Deliberately NOT stored: the MIME type. Nothing may ever serve one of these
-- files with a Content-Type read from the database or sniffed from the bytes.
-- stock_media.php derives it from an extension whitelist and nothing else —
-- ops_comment_media_content_type() in
-- modules/operations/includes/ops_helper.php.
