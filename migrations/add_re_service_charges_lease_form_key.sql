-- Extra services entered on the Add / Edit Lease form (Additional Parking, Storage,
-- Pest Control & Cleaning) are saved as Extra Service charges merged into the 1st cheque.
-- lease_form_key marks which form field a charge belongs to (NULL = added from Billing / Renewal).
-- Run on each environment before uploading the matching PHP files. The PHP also adds the
-- column itself when the database user may ALTER; without it the form fields stay hidden.

SET NAMES utf8mb4;

ALTER TABLE re_service_charges
  ADD COLUMN IF NOT EXISTS lease_form_key VARCHAR(40) DEFAULT NULL COMMENT 'Lease form field (parking, storage, pest_cleaning); NULL = Billing / Renewal' AFTER apply_mode,
  ADD INDEX IF NOT EXISTS idx_sc_lease_form_key (lease_id, lease_form_key);
