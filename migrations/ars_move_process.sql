-- ARS Move-In / Move-Out process (modules/ars/includes/ars_move_process.php)
--
-- Staged move-in and move-out per booking, like the Real Estate move-in / move-out:
--   ars_move_ins / ars_move_outs   one process per booking (cancelled ones kept)
--   ars_move_keys                  keys and access cards handed over and returned
--   ars_move_out_damages           damages found at the final inspection, with cost
--   ars_move_out_charge_types      the company's standard move-out charges
--   ars_move_out_charges           each move-out's charges with amounts
-- The checklist uses the existing ars_move_inspections tables. The pages also
-- create these tables on first use; running this file first is the same.
--
-- Safe to run more than once.

CREATE TABLE IF NOT EXISTS ars_move_ins (
    id INT AUTO_INCREMENT PRIMARY KEY,
    company_id INT NOT NULL,
    booking_id INT NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'in_progress',
    contract_verified TINYINT(1) NOT NULL DEFAULT 0,
    contract_verified_by INT NULL,
    contract_verified_at DATETIME NULL,
    payment_override TINYINT(1) NOT NULL DEFAULT 0,
    payment_override_by INT NULL,
    payment_override_at DATETIME NULL,
    payment_override_reason VARCHAR(500) NULL,
    key_handover_date DATE NULL,
    keys_handed_over TINYINT(1) NOT NULL DEFAULT 0,
    keys_handed_over_at DATETIME NULL,
    keys_handed_over_by INT NULL,
    form_sent_at DATETIME NULL,
    form_sent_to VARCHAR(255) NULL,
    form_sent_by INT NULL,
    completed_at DATETIME NULL,
    completed_by INT NULL,
    cancelled_at DATETIME NULL,
    cancelled_by INT NULL,
    cancel_reason VARCHAR(500) NULL,
    created_by INT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_amp_in_booking (company_id, booking_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ars_move_outs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    company_id INT NOT NULL,
    booking_id INT NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'in_progress',
    move_out_date DATE NULL,
    inspection_date DATE NULL,
    inspection_time TIME NULL,
    inspection_note VARCHAR(255) NULL,
    keys_returned TINYINT(1) NOT NULL DEFAULT 0,
    keys_returned_at DATETIME NULL,
    keys_returned_by INT NULL,
    keys_partial_ack TINYINT(1) NOT NULL DEFAULT 0,
    report_sent_at DATETIME NULL,
    report_sent_to VARCHAR(255) NULL,
    report_sent_by INT NULL,
    settlement_completed_at DATETIME NULL,
    settlement_completed_by INT NULL,
    settlement_note VARCHAR(500) NULL,
    completed_at DATETIME NULL,
    completed_by INT NULL,
    cancelled_at DATETIME NULL,
    cancelled_by INT NULL,
    cancel_reason VARCHAR(500) NULL,
    created_by INT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_amp_out_booking (company_id, booking_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ars_move_keys (
    id INT AUTO_INCREMENT PRIMARY KEY,
    company_id INT NOT NULL,
    booking_id INT NOT NULL,
    key_label VARCHAR(100) NOT NULL,
    quantity INT NOT NULL DEFAULT 0,
    card_numbers VARCHAR(255) NULL,
    returned_qty INT NULL,
    return_note VARCHAR(255) NULL,
    display_order INT NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_amp_keys_booking (company_id, booking_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ars_move_out_damages (
    id INT AUTO_INCREMENT PRIMARY KEY,
    company_id INT NOT NULL,
    move_out_id INT NOT NULL,
    area VARCHAR(100) NULL,
    description VARCHAR(500) NOT NULL,
    cost DECIMAL(12,2) NULL,
    created_by INT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_amp_dmg (move_out_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ars_move_out_charge_types (
    id INT AUTO_INCREMENT PRIMARY KEY,
    company_id INT NOT NULL,
    name VARCHAR(100) NOT NULL,
    display_order INT NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    KEY idx_amp_ct (company_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ars_move_out_charges (
    id INT AUTO_INCREMENT PRIMARY KEY,
    company_id INT NOT NULL,
    move_out_id INT NOT NULL,
    charge_name VARCHAR(100) NOT NULL,
    amount DECIMAL(12,2) NULL,
    note VARCHAR(255) NULL,
    is_removed TINYINT(1) NOT NULL DEFAULT 0,
    display_order INT NOT NULL DEFAULT 0,
    KEY idx_amp_ch (move_out_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Checklist sections (General Condition, Kitchen, ...) for the inspection checklist.
-- The pages add these columns on first use too.
ALTER TABLE ars_move_checklist_templates ADD COLUMN IF NOT EXISTS section_name VARCHAR(100) NULL AFTER move_type;
ALTER TABLE ars_move_inspection_items ADD COLUMN IF NOT EXISTS section_name VARCHAR(100) NULL AFTER template_id;

-- Guest digital signature on the move-in form / move-out report (signed on screen).
ALTER TABLE ars_move_ins
  ADD COLUMN IF NOT EXISTS guest_signature_path VARCHAR(500) NULL,
  ADD COLUMN IF NOT EXISTS guest_signed_name VARCHAR(150) NULL,
  ADD COLUMN IF NOT EXISTS guest_signed_at DATETIME NULL;
ALTER TABLE ars_move_outs
  ADD COLUMN IF NOT EXISTS guest_signature_path VARCHAR(500) NULL,
  ADD COLUMN IF NOT EXISTS guest_signed_name VARCHAR(150) NULL,
  ADD COLUMN IF NOT EXISTS guest_signed_at DATETIME NULL;

-- Signed copies made before the inspection was changed (Edit inspection) no longer count.
ALTER TABLE ars_move_ins ADD COLUMN IF NOT EXISTS signed_superseded_at DATETIME NULL;
ALTER TABLE ars_move_outs ADD COLUMN IF NOT EXISTS signed_superseded_at DATETIME NULL;

-- Move-out checklist: an item marked Damaged, linked to its damage (ars_move_out_damages).
ALTER TABLE ars_move_inspection_items
  ADD COLUMN IF NOT EXISTS is_damaged TINYINT(1) NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS damage_id INT NULL;

-- Which stays use the Move-In / Move-Out process: stays of at least min_nights (default 30),
-- plus every stay in a unit switched on below. Shorter stays use Check In / Check Out.
CREATE TABLE IF NOT EXISTS ars_move_settings (
    company_id INT NOT NULL PRIMARY KEY,
    min_nights INT NOT NULL DEFAULT 30,
    updated_by INT NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS ars_move_unit_process (
    company_id INT NOT NULL,
    unit_id INT NOT NULL,
    always_process TINYINT(1) NOT NULL DEFAULT 1,
    updated_by INT NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (company_id, unit_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Key Return: a key / card not returned, charged as a damage (ars_move_out_damages).
ALTER TABLE ars_move_keys ADD COLUMN IF NOT EXISTS damage_id INT NULL;
