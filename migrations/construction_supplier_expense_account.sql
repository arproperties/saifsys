-- Supplier invoice expense account selection (Construction)
-- Run once on live server before uploading updated PHP files.

ALTER TABLE co_supplier_invoices
    ADD COLUMN expense_account_id INT(11) NULL DEFAULT NULL AFTER project_id,
    ADD KEY idx_co_si_expense_account (expense_account_id);
