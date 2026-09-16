-- ARS ← Airbnb email sync — additive only.
-- Reads Airbnb notification mails from the ARS Gmail and creates/cancels ARS bookings.
-- Timestamps in these tables are written from PHP (Asia/Dubai); no NOW()/CURRENT_TIMESTAMP
-- defaults, because live MySQL runs in UTC.

-- 1. Where a booking came from, and its Airbnb confirmation code (HMXXXXXXXX)
ALTER TABLE ars_bookings
  ADD COLUMN IF NOT EXISTS booking_source VARCHAR(20) NOT NULL DEFAULT 'direct' COMMENT 'direct | airbnb' AFTER booking_number,
  ADD COLUMN IF NOT EXISTS channel_ref VARCHAR(30) NULL COMMENT 'Airbnb confirmation code' AFTER booking_source;

ALTER TABLE ars_bookings
  ADD UNIQUE KEY IF NOT EXISTS uq_ars_bookings_channel_ref (company_id, booking_source, channel_ref);

-- 2. Airbnb listing → ARS unit (default unit for new Airbnb bookings)
CREATE TABLE IF NOT EXISTS ars_channel_listings (
  id INT(11) NOT NULL AUTO_INCREMENT,
  company_id INT(11) NOT NULL,
  channel VARCHAR(20) NOT NULL DEFAULT 'airbnb',
  listing_id VARCHAR(40) NOT NULL COMMENT 'Airbnb room id from /rooms/<id>',
  listing_title VARCHAR(255) NULL,
  unit_id INT(11) NULL COMMENT 're_units.id, NULL = not linked yet',
  first_seen_at DATETIME NULL,
  last_seen_at DATETIME NULL,
  updated_by INT(11) NULL,
  updated_at DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_channel_listing (company_id, channel, listing_id),
  KEY idx_channel_listing_unit (unit_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. Every booking-related Airbnb email the sync has read, and what it did with it
CREATE TABLE IF NOT EXISTS ars_channel_emails (
  id INT(11) NOT NULL AUTO_INCREMENT,
  company_id INT(11) NOT NULL,
  channel VARCHAR(20) NOT NULL DEFAULT 'airbnb',
  mailbox VARCHAR(190) NOT NULL,
  uidvalidity BIGINT NOT NULL DEFAULT 0,
  imap_uid BIGINT NOT NULL,
  message_id VARCHAR(255) NULL,
  sent_at DATETIME NULL,
  subject VARCHAR(500) NULL,
  email_type VARCHAR(30) NOT NULL COMMENT 'confirmed | guest_cancel | host_cancel | updated | change_request',
  confirmation_code VARCHAR(30) NULL,
  listing_id VARCHAR(40) NULL,
  listing_title VARCHAR(255) NULL,
  guest_name VARCHAR(200) NULL,
  check_in DATE NULL,
  check_out DATE NULL,
  requested_check_in DATE NULL,
  requested_check_out DATE NULL,
  num_guests INT NULL,
  payout_amount DECIMAL(12,2) NULL,
  parsed_json MEDIUMTEXT NULL,
  raw_eml MEDIUMBLOB NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'new' COMMENT 'new | processed | needs_review | resolved | info',
  result_note TEXT NULL,
  booking_id INT(11) NULL,
  attempts INT NOT NULL DEFAULT 0,
  created_at DATETIME NULL,
  processed_at DATETIME NULL,
  resolved_by INT(11) NULL,
  resolved_at DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_channel_email_uid (company_id, mailbox, uidvalidity, imap_uid),
  KEY idx_channel_email_status (company_id, status),
  KEY idx_channel_email_code (confirmation_code),
  KEY idx_channel_email_booking (booking_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. Sync cursor and health per mailbox
CREATE TABLE IF NOT EXISTS ars_channel_sync_state (
  id INT(11) NOT NULL AUTO_INCREMENT,
  company_id INT(11) NOT NULL,
  channel VARCHAR(20) NOT NULL DEFAULT 'airbnb',
  mailbox VARCHAR(190) NOT NULL,
  uidvalidity BIGINT NOT NULL DEFAULT 0,
  last_uid BIGINT NOT NULL DEFAULT 0,
  start_at DATETIME NULL COMMENT 'Go-live moment: bookings made before this are never imported',
  last_run_at DATETIME NULL,
  last_success_at DATETIME NULL,
  last_booking_email_at DATETIME NULL,
  last_error TEXT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_channel_sync_state (company_id, channel, mailbox)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
