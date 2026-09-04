-- =============================================================================
-- Service Management — settings defaults ONLY (no schema, no WO backfill)
-- =============================================================================
-- Idempotent: inserts missing keys; does not overwrite existing values.
-- Safe for live when SM feature flags are missing from `settings`.
--
-- Does NOT:
--   - ALTER tables
--   - Backfill make_order.is_finalized
--   - Touch invoices / GL
--
-- Apply:
--   mysql -u USER -p DATABASE < migrations/sm_settings_defaults_only.sql
-- =============================================================================

SET NAMES utf8mb4;

-- Phase 0/1
INSERT INTO settings (`key`, `value`)
SELECT 'sm_use_accounting_service', '0'
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM settings WHERE `key` = 'sm_use_accounting_service');

INSERT INTO settings (`key`, `value`)
SELECT 'sm_accounting_compare_mode', '1'
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM settings WHERE `key` = 'sm_accounting_compare_mode');

-- Phase 2
INSERT INTO settings (`key`, `value`)
SELECT 'sm_defer_auto_invoice', '1'
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM settings WHERE `key` = 'sm_defer_auto_invoice');

-- Phase 4
INSERT INTO settings (`key`, `value`)
SELECT 'sm_hybrid_batch_invoicing', '1'
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM settings WHERE `key` = 'sm_hybrid_batch_invoicing');

-- Phase 7
INSERT INTO settings (`key`, `value`)
SELECT 'sm_expense_requires_approval', '0'
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM settings WHERE `key` = 'sm_expense_requires_approval');

INSERT INTO settings (`key`, `value`)
SELECT 'sm_prepaid_asset_account', '1240'
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM settings WHERE `key` = 'sm_prepaid_asset_account');

INSERT INTO settings (`key`, `value`)
SELECT 'sm_phase7_enabled', '1'
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM settings WHERE `key` = 'sm_phase7_enabled');

-- Phase 8
INSERT INTO settings (`key`, `value`)
SELECT 'sm_phase8_enabled', '1'
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM settings WHERE `key` = 'sm_phase8_enabled');

INSERT INTO settings (`key`, `value`)
SELECT 'sm_gm_dashboard_default_days', '30'
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM settings WHERE `key` = 'sm_gm_dashboard_default_days');

-- Go-live health
INSERT INTO settings (`key`, `value`)
SELECT 'sm_health_exclude_orphan_invoices', '1'
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM settings WHERE `key` = 'sm_health_exclude_orphan_invoices');

SELECT `key`, `value`
FROM settings
WHERE `key` LIKE 'sm_%'
ORDER BY `key`;
