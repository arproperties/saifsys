-- Tenancy Contract v2 (residential, Ayla standard).
-- Columns only; the template row itself is added by tools/contract/build_tenancy_contract_v2_sql.php
-- (it writes the full install file with the template HTML inside).
-- Safe to run twice.

ALTER TABLE `re_contract_templates`
  ADD COLUMN IF NOT EXISTS `template_code` VARCHAR(50) DEFAULT NULL
    COMMENT 'Version code read by the PDF generator, e.g. tenancy_contract_v2. NULL = old template, rendered as before';

ALTER TABLE `re_leases`
  ADD COLUMN IF NOT EXISTS `max_occupants` SMALLINT UNSIGNED DEFAULT NULL
    COMMENT 'Contract v2 clause 17(b). NULL = default 4',
  ADD COLUMN IF NOT EXISTS `occupancy_type` VARCHAR(100) DEFAULT NULL
    COMMENT 'single_family / couple / company_staff, or free text. NULL = a single family',
  ADD COLUMN IF NOT EXISTS `guarantor_name` VARCHAR(200) DEFAULT NULL
    COMMENT 'Contract v2 guarantor. Required for company tenants with annual rent over AED 100,000',
  ADD COLUMN IF NOT EXISTS `guarantor_eid` VARCHAR(50) DEFAULT NULL
    COMMENT 'Guarantor Emirates ID',
  ADD COLUMN IF NOT EXISTS `contract_template_version` VARCHAR(50) DEFAULT NULL
    COMMENT 'Template code (or template_<id>) the last generated contract PDF was made from';
