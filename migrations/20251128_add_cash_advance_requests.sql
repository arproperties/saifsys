-- Migration: Add cash advance request workflow
-- Date: 2025-11-28
-- Description: Adds columns to support employee cash advance requests with approval workflow

ALTER TABLE `cash_advances`
  ADD COLUMN `request_status` enum('pending','approved','rejected') DEFAULT NULL AFTER `status`,
  ADD COLUMN `requested_amount` decimal(10,2) DEFAULT NULL AFTER `request_status`,
  ADD COLUMN `approved_amount` decimal(10,2) DEFAULT NULL AFTER `requested_amount`,
  ADD COLUMN `requested_by` int(11) DEFAULT NULL AFTER `approved_amount`,
  ADD COLUMN `approved_by` int(11) DEFAULT NULL AFTER `requested_by`,
  ADD COLUMN `approved_at` datetime DEFAULT NULL AFTER `approved_by`,
  ADD COLUMN `rejection_reason` varchar(255) DEFAULT NULL AFTER `approved_at`;

-- Add index for faster queries on pending requests
CREATE INDEX `idx_request_status` ON `cash_advances` (`request_status`);

