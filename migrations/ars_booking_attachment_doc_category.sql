-- Unified Documents tab (ARS booking view).
-- Files each booking attachment under one of four default document types:
--   contract | payment_receipt | security_deposit | other
--
-- Existing rows keep 'other' so older bookings are not disturbed; staff can
-- re-file them from the Documents tab at any time.
--
-- Safe to skip if already applied: ars_booking_attachments_ensure_schema()
-- adds the column on first page load too.

ALTER TABLE ars_booking_attachments
    ADD COLUMN doc_category VARCHAR(32) NOT NULL DEFAULT 'other' AFTER file_size;
