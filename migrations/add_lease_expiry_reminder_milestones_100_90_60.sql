-- Extend lease expiry reminder types for automated cron (100 / 90 / 60 / 30 days before end_date).
-- Run once on each environment: mysql ... < migrations/add_lease_expiry_reminder_milestones_100_90_60.sql

ALTER TABLE `re_lease_expiry_reminders`
  MODIFY COLUMN `reminder_type` ENUM(
    '100_days',
    '90_days',
    '60_days',
    '30_days',
    '15_days',
    '7_days',
    '1_day',
    'expired'
  ) NOT NULL DEFAULT '30_days';
