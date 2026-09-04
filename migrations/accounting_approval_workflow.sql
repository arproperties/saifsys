-- Optional approval workflow for journal entries
-- Submit for Approval -> Approve -> Post
-- Run once. If columns already exist, you will get duplicate column errors (safe to ignore).

ALTER TABLE `re_journal_headers`
  ADD COLUMN `approval_status` ENUM('draft','submitted','approved') NOT NULL DEFAULT 'draft',
  ADD COLUMN `submitted_at` DATETIME DEFAULT NULL,
  ADD COLUMN `submitted_by` INT(11) DEFAULT NULL,
  ADD COLUMN `approved_at` DATETIME DEFAULT NULL,
  ADD COLUMN `approved_by` INT(11) DEFAULT NULL;
