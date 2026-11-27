-- Optional attachments on an employee bonus and on an employee deduction (added 2026-11-27).
-- Several files per row, kept as a JSON list of file paths.
-- Run once in phpMyAdmin. Safe to run again.

ALTER TABLE employee_bonuses
  ADD COLUMN IF NOT EXISTS attachments_json TEXT NULL;

ALTER TABLE employee_deductions
  ADD COLUMN IF NOT EXISTS attachments_json TEXT NULL;
