-- ============================================================================
-- ARS Home Rentals — Stripe Payments
-- Production Stripe settings + PaymentIntent/webhook tracking.
-- Safe to run after ARS Phase 1 core engine.
-- ============================================================================

ALTER TABLE `ars_company_settings`
  ADD COLUMN IF NOT EXISTS `stripe_enabled` TINYINT(1) NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS `stripe_mode` ENUM('test','live') NOT NULL DEFAULT 'test',
  ADD COLUMN IF NOT EXISTS `stripe_publishable_key_test` VARCHAR(255) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `stripe_secret_key_test_enc` TEXT DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `stripe_webhook_secret_test_enc` TEXT DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `stripe_publishable_key_live` VARCHAR(255) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `stripe_secret_key_live_enc` TEXT DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `stripe_webhook_secret_live_enc` TEXT DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `stripe_default_currency` VARCHAR(10) NOT NULL DEFAULT 'AED',
  ADD COLUMN IF NOT EXISTS `stripe_payment_policy` ENUM('full','deposit','partial') NOT NULL DEFAULT 'full',
  ADD COLUMN IF NOT EXISTS `stripe_deposit_percentage` DECIMAL(5,2) NOT NULL DEFAULT 20.00,
  ADD COLUMN IF NOT EXISTS `stripe_auto_confirm` TINYINT(1) NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS `stripe_success_url` VARCHAR(500) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `stripe_failed_url` VARCHAR(500) DEFAULT NULL;

ALTER TABLE `ars_bookings`
  MODIFY `payment_status` ENUM('unpaid','partial','paid','refunded','failed') NOT NULL DEFAULT 'unpaid';

ALTER TABLE `ars_booking_payments`
  ADD COLUMN IF NOT EXISTS `payment_gateway` VARCHAR(40) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `payment_type` ENUM('manual','full','deposit','balance','partial') NOT NULL DEFAULT 'manual',
  ADD COLUMN IF NOT EXISTS `currency` VARCHAR(10) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `gateway_payment_intent_id` VARCHAR(120) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `gateway_charge_id` VARCHAR(120) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `gateway_status` VARCHAR(60) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `amount_refunded` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  ADD COLUMN IF NOT EXISTS `refunded_at` DATETIME DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `failure_code` VARCHAR(120) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `failure_message` TEXT DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `raw_payload_json` JSON DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `updated_at` DATETIME DEFAULT NULL;

CREATE INDEX IF NOT EXISTS `idx_ars_payments_gateway_intent`
  ON `ars_booking_payments` (`gateway_payment_intent_id`);

CREATE TABLE IF NOT EXISTS `ars_stripe_events` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `company_id` INT(11) DEFAULT NULL,
  `booking_id` INT(11) DEFAULT NULL,
  `event_id` VARCHAR(120) NOT NULL,
  `event_type` VARCHAR(120) NOT NULL,
  `livemode` TINYINT(1) NOT NULL DEFAULT 0,
  `payment_intent_id` VARCHAR(120) DEFAULT NULL,
  `status` ENUM('received','processed','failed') NOT NULL DEFAULT 'received',
  `payload_json` JSON DEFAULT NULL,
  `error_message` TEXT DEFAULT NULL,
  `processed_at` DATETIME DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_ars_stripe_event_id` (`event_id`),
  KEY `idx_ars_stripe_events_booking` (`booking_id`),
  KEY `idx_ars_stripe_events_intent` (`payment_intent_id`),
  KEY `idx_ars_stripe_events_type` (`event_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
