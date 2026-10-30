-- ============================================================================
-- Real Estate: Bulk Tenant Email — multiple attachments per campaign.
-- attachments_json holds a list of {path,name,mime,size}. The legacy
-- attachment_* columns keep the first file for older screens.
-- ============================================================================

ALTER TABLE `re_bulk_email_campaigns`
  ADD COLUMN `attachments_json` JSON NULL DEFAULT NULL AFTER `attachment_size`;
