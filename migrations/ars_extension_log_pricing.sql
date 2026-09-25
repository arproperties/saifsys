-- ARS Extend tab: a price per night on every extension entry.
--
-- The Extend tab used to record dates only. It now records what those nights
-- were sold at (rate_per_night), what they come to (amount = rate x nights),
-- and which extension invoice billed them (document_id, NULL until billed).
--
-- Run once on live (phpMyAdmin) BEFORE uploading the PHP files. Safe to run a
-- second time: each ALTER is skipped when the column is already there.

-- The table itself, for an install that has never opened the Extend tab.
CREATE TABLE IF NOT EXISTS `ars_booking_extension_log` (
  `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `booking_id`    INT NOT NULL,
  `company_id`    INT NOT NULL,
  `extended_from` DATE NOT NULL,
  `extended_to`   DATE NOT NULL,
  `nights`        INT NOT NULL DEFAULT 0,
  `note`          VARCHAR(255) NULL DEFAULT NULL,
  `created_by`    INT NULL DEFAULT NULL,
  `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_ext_log_booking` (`booking_id`, `company_id`),
  KEY `idx_ext_log_dates` (`extended_from`, `extended_to`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- The three new columns. Existing rows keep NULL: they were recorded before
-- there was a price, so they are shown as "no price set" rather than as free.
SET @db := DATABASE();

SET @sql := (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE `ars_booking_extension_log` ADD COLUMN `rate_per_night` DECIMAL(12,2) NULL DEFAULT NULL AFTER `nights`',
  'SELECT "rate_per_night already present"')
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'ars_booking_extension_log' AND COLUMN_NAME = 'rate_per_night');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql := (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE `ars_booking_extension_log` ADD COLUMN `amount` DECIMAL(12,2) NULL DEFAULT NULL AFTER `rate_per_night`',
  'SELECT "amount already present"')
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'ars_booking_extension_log' AND COLUMN_NAME = 'amount');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- document_id points at ars_financial_documents.id once the entry is billed.
-- No foreign key on purpose: the log is allowed to outlive a voided document.
SET @sql := (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE `ars_booking_extension_log` ADD COLUMN `document_id` INT NULL DEFAULT NULL AFTER `amount`',
  'SELECT "document_id already present"')
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'ars_booking_extension_log' AND COLUMN_NAME = 'document_id');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
