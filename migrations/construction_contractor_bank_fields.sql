-- ============================================================================
-- Add structured bank fields to co_contractors
-- Keeps bank_details for backward compatibility / additional notes
-- ============================================================================

ALTER TABLE `co_contractors`
  ADD COLUMN `bank_name` VARCHAR(255) DEFAULT NULL AFTER `tax_number`,
  ADD COLUMN `account_number` VARCHAR(100) DEFAULT NULL AFTER `bank_name`,
  ADD COLUMN `iban` VARCHAR(50) DEFAULT NULL AFTER `account_number`,
  ADD COLUMN `swift_code` VARCHAR(20) DEFAULT NULL AFTER `iban`;
