-- Service Management Phase 0/1 — optional feature flags (safe re-run)
-- Run manually: does not auto-enable accounting service (compare mode on by default).

INSERT INTO settings (`key`, `value`)
SELECT 'sm_use_accounting_service', '0'
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM settings WHERE `key` = 'sm_use_accounting_service');

INSERT INTO settings (`key`, `value`)
SELECT 'sm_accounting_compare_mode', '1'
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM settings WHERE `key` = 'sm_accounting_compare_mode');
