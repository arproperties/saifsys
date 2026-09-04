-- Invoice Templates Signature Feature - Database Migration
-- Run this SQL file on your live database to add signature support

-- Add signature_path column to invoice_templates table
ALTER TABLE invoice_templates ADD COLUMN signature_path VARCHAR(255) DEFAULT NULL AFTER show_signature;

-- Verify the column was added
DESCRIBE invoice_templates;
