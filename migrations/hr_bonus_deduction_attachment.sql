-- Optional attachment on an employee bonus and on an employee deduction (added 2026-11-27).
-- Run once in phpMyAdmin. Safe to run again.

ALTER TABLE employee_bonuses
  ADD COLUMN IF NOT EXISTS attachment_path VARCHAR(255) NULL;

ALTER TABLE employee_deductions
  ADD COLUMN IF NOT EXISTS attachment_path VARCHAR(255) NULL;
