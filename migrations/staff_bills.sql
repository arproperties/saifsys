-- Bills — tracking a bill (or money) from hand to hand, on the staff app (/staff/ → Bills).
--
-- Someone starts a bill: date received, amount, attachment, notes, and who they
-- give it to on which day. That person gets a notification, accepts it or
-- rejects it with a reason (a rejected bill goes back to the one who gave it),
-- then gives it to the next person or keeps it. Keeping it ends the chain.
-- Every hand-over is one row in bill_moves: the bill's history.
--
-- Who may use it: everyone with a staff app PIN, unless HR turns Bills off
-- (HR → employee → Mobile App PIN → Bills; stored as staff_app_access app
-- 'bills_off'). Only people with Bills on appear in "Given to".
-- Rules: includes/staff_bills.php. Report: Operations → Staff Bills (Owner, Admin).
-- Files live in uploads/bills/{bill_id}/ (not web-readable).
--
-- Safe to run more than once.

CREATE TABLE IF NOT EXISTS `bill_bills` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `received_on` DATE NOT NULL COMMENT 'The day the bill was received',
  `amount` DECIMAL(14,2) NOT NULL,
  `notes` VARCHAR(500) NULL,
  `created_by` INT(11) NOT NULL COMMENT 'user.id of the one who started it',
  `created_by_name` VARCHAR(120) NOT NULL,
  `status` ENUM('waiting','with','kept','discarded') NOT NULL COMMENT 'waiting = given, not yet accepted; with = the holder has it; kept = finished with the holder; discarded = thrown away by the office',
  `holder_user_id` INT(11) NOT NULL COMMENT 'Who has it now (or who it waits for)',
  `holder_name` VARCHAR(120) NOT NULL,
  `kept_at` DATETIME(6) NULL,
  `discarded_at` DATETIME(6) NULL COMMENT 'Operations → Staff Bills → Delete (Owner, Admin)',
  `discarded_by` INT(11) NULL,
  `discarded_by_name` VARCHAR(120) NULL,
  `discard_reason` VARCHAR(255) NULL,
  `client_ref` VARCHAR(64) NULL COMMENT 'The phone''s id for one save: a resend makes one bill',
  `created_at` DATETIME(6) NOT NULL COMMENT 'Microseconds: the history is put in order by these times',
  `updated_at` DATETIME(6) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_bill_bills_ref` (`created_by`, `client_ref`),
  KEY `idx_bill_bills_holder` (`holder_user_id`, `status`),
  KEY `idx_bill_bills_received` (`received_on`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `bill_moves` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `bill_id` INT(11) NOT NULL,
  `from_user_id` INT(11) NOT NULL,
  `from_name` VARCHAR(120) NOT NULL,
  `to_user_id` INT(11) NOT NULL,
  `to_name` VARCHAR(120) NOT NULL,
  `given_on` DATE NOT NULL COMMENT 'The day it was handed over, as the giver said',
  `status` ENUM('waiting','accepted','rejected') NOT NULL DEFAULT 'waiting',
  `reason` VARCHAR(255) NULL COMMENT 'Why it was rejected',
  `acted_at` DATETIME(6) NULL COMMENT 'When it was accepted or rejected',
  `created_at` DATETIME(6) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_bill_moves_bill` (`bill_id`, `id`),
  KEY `idx_bill_moves_from` (`from_user_id`),
  KEY `idx_bill_moves_to` (`to_user_id`),
  CONSTRAINT `fk_bill_moves_bill` FOREIGN KEY (`bill_id`) REFERENCES `bill_bills`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `bill_files` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `bill_id` INT(11) NOT NULL,
  `file_path` VARCHAR(255) NOT NULL,
  `created_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_bill_files_bill` (`bill_id`),
  CONSTRAINT `fk_bill_files_bill` FOREIGN KEY (`bill_id`) REFERENCES `bill_bills`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Changes made to a bill after it was started (kind edit: amount, date
-- received, notes, attachments added, each with its reason; only the starter,
-- before anyone accepts it), and notes added later (kind note: the starter or
-- whoever holds it; reason holds the note). changes = JSON list of
-- [what, before, after]. Shown in the bill's history.
CREATE TABLE IF NOT EXISTS `bill_edits` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `bill_id` INT(11) NOT NULL,
  `user_id` INT(11) NOT NULL,
  `user_name` VARCHAR(120) NOT NULL,
  `kind` ENUM('edit','note') NOT NULL DEFAULT 'edit',
  `changes` TEXT NOT NULL,
  `reason` VARCHAR(255) NOT NULL,
  `created_at` DATETIME(6) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_bill_edits_bill` (`bill_id`, `id`),
  CONSTRAINT `fk_bill_edits_bill` FOREIGN KEY (`bill_id`) REFERENCES `bill_bills`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- A request from the staff app to delete a bill (Request delete, with a reason).
-- Only the office deletes: Operations → Staff Bills → Delete (the bill is then
-- discarded, see bill_bills.discarded_*) or Decline. One open request at a time.
CREATE TABLE IF NOT EXISTS `bill_delete_requests` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `bill_id` INT(11) NOT NULL,
  `user_id` INT(11) NOT NULL COMMENT 'Who asked',
  `user_name` VARCHAR(120) NOT NULL,
  `reason` VARCHAR(255) NOT NULL,
  `status` ENUM('open','deleted','declined') NOT NULL DEFAULT 'open',
  `decided_by` INT(11) NULL,
  `decided_by_name` VARCHAR(120) NULL,
  `decided_at` DATETIME(6) NULL,
  `decline_reason` VARCHAR(255) NULL,
  `created_at` DATETIME(6) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_bill_delreq_bill` (`bill_id`, `status`),
  CONSTRAINT `fk_bill_delreq_bill` FOREIGN KEY (`bill_id`) REFERENCES `bill_bills`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
