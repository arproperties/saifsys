-- Construction supplier invoice bill-entry fields (parity with RE vendor bills)
-- Run once after construction_supplier_invoices_phase2.sql

ALTER TABLE co_supplier_invoices
    ADD COLUMN payment_terms VARCHAR(100) DEFAULT NULL AFTER due_date,
    ADD COLUMN place_of_supply VARCHAR(100) DEFAULT 'Dubai' AFTER payment_terms,
    ADD COLUMN vat_treatment VARCHAR(30) NOT NULL DEFAULT 'vat_registered' AFTER place_of_supply,
    ADD COLUMN order_number VARCHAR(100) DEFAULT NULL AFTER vat_treatment,
    ADD COLUMN permit_number VARCHAR(100) DEFAULT NULL AFTER order_number,
    ADD COLUMN notes TEXT DEFAULT NULL AFTER reference;

ALTER TABLE co_supplier_invoice_items
    ADD COLUMN vat_treatment VARCHAR(30) NOT NULL DEFAULT 'standard' AFTER subtotal;
