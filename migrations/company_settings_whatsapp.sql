-- Company support WhatsApp for Customer App Account Support section.
-- Source of truth: Settings → Company Information (company_settings).
-- Additive only. Rollback: ALTER TABLE company_settings DROP COLUMN whatsapp;

ALTER TABLE `company_settings`
  ADD COLUMN IF NOT EXISTS `whatsapp` VARCHAR(50) NULL DEFAULT NULL
    COMMENT 'WhatsApp number for tenant/customer app support contact'
    AFTER `website`;
