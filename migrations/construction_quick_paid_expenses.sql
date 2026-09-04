-- Construction Quick Paid Expenses — mark existing Construction ERP expenses as legacy archive.
-- Human-applied. Safe to re-run (column add is guarded by application checks if already present).
-- New Construction Quick Paid rows keep legacy_archive = 0 (editable).
-- Do NOT rewrite journals.

ALTER TABLE `erp_expense_headers`
    ADD COLUMN `legacy_archive` TINYINT(1) NOT NULL DEFAULT 0
        COMMENT '1=Construction Historical ERP archive (read-only forever); 0=Quick Paid / live'
        AFTER `status`;

UPDATE `erp_expense_headers`
SET `legacy_archive` = 1
WHERE `source_module` = 'construction';
