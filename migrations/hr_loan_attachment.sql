-- Optional attachments on an employee loan / advance (added 2026-11-28).
-- Several files per loan, kept as a JSON list of file paths, the same way as
-- bonuses and deductions (migrations/hr_bonus_deduction_attachment.sql).
-- Run once in phpMyAdmin. Safe to run again.

ALTER TABLE cash_advances
  ADD COLUMN IF NOT EXISTS attachments_json TEXT NULL;
