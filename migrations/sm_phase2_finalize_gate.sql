-- Service Management Phase 2: finalize gate + financial lock (safe re-run)

-- Feature flag: defer auto-invoice on create/edit (Phase 2 ON when = 1)
INSERT INTO settings (`key`, `value`)
SELECT 'sm_defer_auto_invoice', '1'
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM settings WHERE `key` = 'sm_defer_auto_invoice');

-- make_order workflow columns (add only if missing — run via PHP migrator or manually per column)
