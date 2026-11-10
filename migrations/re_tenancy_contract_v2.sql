-- Tenancy Contract v2 (residential, Ayla standard).
-- Columns only; the template row itself is added by tools/contract/build_tenancy_contract_v2_sql.php
-- (it writes the full install file with the template HTML inside).
-- Safe to run twice.

ALTER TABLE `re_contract_templates`
  ADD COLUMN IF NOT EXISTS `template_code` VARCHAR(50) DEFAULT NULL
    COMMENT 'Version code read by the PDF generator, e.g. tenancy_contract_v2. NULL = old template, rendered as before';

ALTER TABLE `re_leases`
  ADD COLUMN IF NOT EXISTS `max_occupants` SMALLINT UNSIGNED DEFAULT NULL
    COMMENT 'Contract v2 clause 17(b). Calculated from the unit at lease creation (snapshot); Admin may override',
  ADD COLUMN IF NOT EXISTS `occupancy_type` VARCHAR(100) DEFAULT NULL
    COMMENT 'single_family / couple / company_staff, or free text. NULL = a single family',
  ADD COLUMN IF NOT EXISTS `guarantor_name` VARCHAR(200) DEFAULT NULL
    COMMENT 'Contract v2 guarantor. Required for company tenants with annual rent over AED 100,000',
  ADD COLUMN IF NOT EXISTS `guarantor_eid` VARCHAR(50) DEFAULT NULL
    COMMENT 'Guarantor Emirates ID',
  ADD COLUMN IF NOT EXISTS `contract_template_version` VARCHAR(50) DEFAULT NULL
    COMMENT 'Template code (or template_<id>) the last generated contract PDF was made from',
  ADD COLUMN IF NOT EXISTS `contract_rates_snapshot` LONGTEXT DEFAULT NULL
    COMMENT 'Contract v2 Annex D rates (JSON) saved when the contract was first generated',
  ADD COLUMN IF NOT EXISTS `max_occupants_basis` VARCHAR(255) DEFAULT NULL
    COMMENT 'How max_occupants was worked out, e.g. 2BR, 30 sqm bedrooms -> 6',
  ADD COLUMN IF NOT EXISTS `max_occupants_override_reason` VARCHAR(255) DEFAULT NULL
    COMMENT 'Admin override reason (NULL = calculated from the unit)',
  ADD COLUMN IF NOT EXISTS `max_occupants_overridden_by` INT DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `max_occupants_overridden_at` DATETIME DEFAULT NULL;

ALTER TABLE `re_units`
  ADD COLUMN IF NOT EXISTS `bedroom_area_sqm` DECIMAL(7,2) DEFAULT NULL
    COMMENT 'Sum of net bedroom areas from the floor plan (contract v2 occupancy: 5 sqm per person)';

-- Commercial contract v2 (shop / office / food court). Printed on page 1; display only, not used in any amount.
ALTER TABLE `re_leases`
  ADD COLUMN IF NOT EXISTS `commercial_use` VARCHAR(20) DEFAULT NULL
    COMMENT 'Commercial contract v2: shop / office / food_court. NULL = contract will not print',
  ADD COLUMN IF NOT EXISTS `permitted_activity` VARCHAR(255) DEFAULT NULL
    COMMENT 'Commercial contract v2 page 1, e.g. Grocery / Restaurant',
  ADD COLUMN IF NOT EXISTS `trade_licence_no` VARCHAR(100) DEFAULT NULL
    COMMENT 'Commercial contract v2 page 1',
  ADD COLUMN IF NOT EXISTS `fit_out_days` SMALLINT UNSIGNED DEFAULT NULL
    COMMENT 'Commercial contract v2 page 1 fit-out period in days (NULL/0 = None). Display only, does not change rent';

-- Labour accommodation contract v2 (Gents / Ladies Camp). Display only.
ALTER TABLE `re_leases`
  ADD COLUMN IF NOT EXISTS `persons_per_room` SMALLINT UNSIGNED DEFAULT NULL
    COMMENT 'Labour contract v2: max persons per room (page 1, clause 21). NULL = contract will not print';

-- Commercial contract v2.1 (Ayla). Printed on the contract only; none of these is used in any amount or posting.
ALTER TABLE `re_leases`
  ADD COLUMN IF NOT EXISTS `tenant_trn` VARCHAR(30) DEFAULT NULL COMMENT 'Commercial v2.1 page 1',
  ADD COLUMN IF NOT EXISTS `tenant_signatory_name` VARCHAR(200) DEFAULT NULL COMMENT 'Commercial v2.1 page 1 + signature page',
  ADD COLUMN IF NOT EXISTS `tenant_signatory_eid` VARCHAR(50) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `tenant_bank_account_name` VARCHAR(200) DEFAULT NULL COMMENT 'Commercial v2.1 Annex A note',
  ADD COLUMN IF NOT EXISTS `guarantor_phone` VARCHAR(50) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `guarantor_email` VARCHAR(150) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `guarantor_waived_reason` VARCHAR(255) DEFAULT NULL COMMENT 'Commercial v2.1: Admin reason for printing without a guarantor',
  ADD COLUMN IF NOT EXISTS `guarantor_waived_by` INT DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `guarantor_waived_at` DATETIME DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `service_charge_terms` VARCHAR(255) DEFAULT NULL COMMENT 'NULL = None - included in the rent',
  ADD COLUMN IF NOT EXISTS `fit_out_deposit` DECIMAL(12,2) DEFAULT NULL COMMENT 'Printed only (not invoiced or posted)',
  ADD COLUMN IF NOT EXISTS `parking_terms` VARCHAR(255) DEFAULT NULL COMMENT 'NULL = None, unless agreed by the Landlord in writing';
