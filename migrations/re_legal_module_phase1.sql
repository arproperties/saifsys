-- ============================================================================
-- Legal Department Module - Phase 1
-- ----------------------------------------------------------------------------
-- Tables: property owners registry, legal cases, polymorphic case links,
-- case event timeline, and legal notices. Includes deadline/reminder fields.
--
-- Idempotent: CREATE TABLE IF NOT EXISTS / ADD COLUMN IF NOT EXISTS /
-- INSERT IGNORE. Safe to re-run.
-- ============================================================================

-- ----------------------------------------------------------------------------
-- 1) Property owners registry
-- No owner/landlord table exists today (only re_buildings.landlord_name text).
-- We create a registry and REUSE existing data by seeding from landlord_name
-- and backfilling re_buildings.owner_id (see bottom of file).
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `re_property_owners` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `company_id` INT(11) NOT NULL,
    `name` VARCHAR(200) NOT NULL,
    `contact_person` VARCHAR(200) DEFAULT NULL,
    `email` VARCHAR(150) DEFAULT NULL,
    `phone` VARCHAR(50) DEFAULT NULL,
    `emirates_id_trn` VARCHAR(100) DEFAULT NULL,
    `notes` TEXT DEFAULT NULL,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` DATETIME NOT NULL DEFAULT current_timestamp(),
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_owner_company_name` (`company_id`, `name`),
    KEY `idx_owner_company` (`company_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

ALTER TABLE `re_buildings` ADD COLUMN IF NOT EXISTS `owner_id` INT(11) DEFAULT NULL;

-- ----------------------------------------------------------------------------
-- 2) Legal cases / matters
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `re_legal_cases` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `company_id` INT(11) NOT NULL,
    `case_number` VARCHAR(40) NOT NULL,
    `title` VARCHAR(255) NOT NULL,
    `case_type` ENUM('cheque_bounce','eviction','rent_recovery','deposit_dispute','contract_breach','rdc_dispute','other') NOT NULL DEFAULT 'other',
    `case_source` ENUM('manual','bounced_cheque','lease_violation','tenant_complaint','renewal','document','other') NOT NULL DEFAULT 'manual',
    `status` ENUM('draft','open','notice_sent','filed','in_hearing','judgment','execution','settled','closed','withdrawn') NOT NULL DEFAULT 'draft',
    `priority` ENUM('low','medium','high','urgent') NOT NULL DEFAULT 'medium',
    `description` TEXT DEFAULT NULL,
    `claim_amount` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    `recovered_amount` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    `jurisdiction` VARCHAR(150) DEFAULT NULL,
    `external_reference` VARCHAR(150) DEFAULT NULL,
    -- Primary context (denormalized for fast filtering)
    `building_id` INT(11) DEFAULT NULL,
    `unit_id` INT(11) DEFAULT NULL,
    `tenant_id` INT(11) DEFAULT NULL,
    `lease_id` INT(11) DEFAULT NULL,
    `owner_id` INT(11) DEFAULT NULL,
    `primary_cheque_id` INT(11) DEFAULT NULL,
    -- Assignment + deadline/reminder tracking (Phase 1)
    `assigned_to` INT(11) DEFAULT NULL,
    `opened_date` DATE DEFAULT NULL,
    `filed_date` DATE DEFAULT NULL,
    `closed_date` DATE DEFAULT NULL,
    `next_action_date` DATE DEFAULT NULL,
    `deadline_date` DATE DEFAULT NULL,
    `reminder_days_before` INT(11) NOT NULL DEFAULT 3,
    `created_by` INT(11) DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT current_timestamp(),
    `updated_at` DATETIME NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
    `deleted_at` DATETIME DEFAULT NULL,
    `deleted_by` INT(11) DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_case_company_number` (`company_id`, `case_number`),
    KEY `idx_case_company` (`company_id`),
    KEY `idx_case_status` (`status`),
    KEY `idx_case_type` (`case_type`),
    KEY `idx_case_deadline` (`deadline_date`),
    KEY `idx_case_assigned` (`assigned_to`),
    KEY `idx_case_tenant` (`tenant_id`),
    KEY `idx_case_lease` (`lease_id`),
    KEY `idx_case_cheque` (`primary_cheque_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ----------------------------------------------------------------------------
-- 3) Polymorphic case links (additional/multiple related entities)
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `re_legal_case_links` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `case_id` INT(11) NOT NULL,
    `link_type` ENUM('building','unit','tenant','lease','owner','cheque','document','payment','installment') NOT NULL,
    `link_id` INT(11) NOT NULL,
    `label` VARCHAR(255) DEFAULT NULL,
    `created_by` INT(11) DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT current_timestamp(),
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_case_link` (`case_id`, `link_type`, `link_id`),
    KEY `idx_link_case` (`case_id`),
    KEY `idx_link_target` (`link_type`, `link_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ----------------------------------------------------------------------------
-- 4) Case event timeline
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `re_legal_case_events` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `case_id` INT(11) NOT NULL,
    `event_type` ENUM('note','status_change','notice_sent','document_added','link_added','payment','deadline') NOT NULL DEFAULT 'note',
    `description` TEXT DEFAULT NULL,
    `old_value` VARCHAR(255) DEFAULT NULL,
    `new_value` VARCHAR(255) DEFAULT NULL,
    `event_date` DATE DEFAULT NULL,
    `created_by` INT(11) DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT current_timestamp(),
    PRIMARY KEY (`id`),
    KEY `idx_event_case` (`case_id`),
    KEY `idx_event_type` (`event_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ----------------------------------------------------------------------------
-- 5) Legal notices
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `re_legal_notices` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `company_id` INT(11) NOT NULL,
    `case_id` INT(11) DEFAULT NULL,
    `notice_type` ENUM('warning','demand_payment','eviction_30day','eviction_12month_notarized','cheque_bounce_demand','contract_termination','final_notice','other') NOT NULL DEFAULT 'warning',
    `reference_number` VARCHAR(40) NOT NULL,
    `subject` VARCHAR(255) NOT NULL,
    `body` MEDIUMTEXT DEFAULT NULL,
    `recipient_name` VARCHAR(200) DEFAULT NULL,
    `recipient_address` TEXT DEFAULT NULL,
    `recipient_email` VARCHAR(150) DEFAULT NULL,
    `tenant_id` INT(11) DEFAULT NULL,
    `lease_id` INT(11) DEFAULT NULL,
    `unit_id` INT(11) DEFAULT NULL,
    `building_id` INT(11) DEFAULT NULL,
    `issue_date` DATE DEFAULT NULL,
    `response_deadline` DATE DEFAULT NULL,
    `deadline_date` DATE DEFAULT NULL,
    `reminder_days_before` INT(11) NOT NULL DEFAULT 3,
    `assigned_to` INT(11) DEFAULT NULL,
    `delivery_method` ENUM('email','courier','hand','registered_post','notary','other') NOT NULL DEFAULT 'email',
    `delivery_status` ENUM('draft','sent','delivered','acknowledged','no_response') NOT NULL DEFAULT 'draft',
    `delivered_at` DATETIME DEFAULT NULL,
    `pdf_path` VARCHAR(500) DEFAULT NULL,
    `created_by` INT(11) DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT current_timestamp(),
    `updated_at` DATETIME NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_notice_company_ref` (`company_id`, `reference_number`),
    KEY `idx_notice_company` (`company_id`),
    KEY `idx_notice_case` (`case_id`),
    KEY `idx_notice_status` (`delivery_status`),
    KEY `idx_notice_deadline` (`deadline_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ----------------------------------------------------------------------------
-- 6) Allow documents to be attached to legal cases (extend related_type enum)
-- ----------------------------------------------------------------------------
ALTER TABLE `re_documents`
    MODIFY `related_type` ENUM('lease','tenant','unit','building','maintenance','legal_case') NOT NULL;

-- ----------------------------------------------------------------------------
-- 7) Reuse existing landlord data: seed owners + backfill building owner_id
-- ----------------------------------------------------------------------------
INSERT IGNORE INTO `re_property_owners` (`company_id`, `name`)
SELECT DISTINCT b.company_id, TRIM(b.landlord_name)
FROM `re_buildings` b
WHERE b.landlord_name IS NOT NULL AND TRIM(b.landlord_name) <> '';

UPDATE `re_buildings` b
JOIN `re_property_owners` o
    ON o.company_id = b.company_id AND o.name = TRIM(b.landlord_name)
SET b.owner_id = o.id
WHERE (b.owner_id IS NULL OR b.owner_id = 0)
  AND b.landlord_name IS NOT NULL AND TRIM(b.landlord_name) <> '';
