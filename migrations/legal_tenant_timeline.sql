-- ============================================================================
-- Legal module: case stages, settlement offers, PDF-vs-saifsys checks,
-- claimant, review flags (for the Tenant Timeline).  2026-11-12
-- ----------------------------------------------------------------------------
-- Idempotent: CREATE TABLE IF NOT EXISTS / ADD COLUMN IF NOT EXISTS.
-- The two MODIFY statements only ADD enum values; every existing value is kept.
-- No lease, accounting or money tables are touched.
-- ============================================================================

-- 1) Cases: two new statuses, claimant, review flag
ALTER TABLE `re_legal_cases`
    MODIFY `status` ENUM('draft','open','notice_sent','filed','settlement_dept','in_hearing','judgment','appeal','execution','settled','closed','withdrawn') NOT NULL DEFAULT 'draft';
ALTER TABLE `re_legal_cases` ADD COLUMN IF NOT EXISTS `claimant_name` VARCHAR(200) DEFAULT NULL AFTER `jurisdiction`;
ALTER TABLE `re_legal_cases` ADD COLUMN IF NOT EXISTS `needs_review` TINYINT(1) NOT NULL DEFAULT 0;
ALTER TABLE `re_legal_cases` ADD COLUMN IF NOT EXISTS `review_note` VARCHAR(255) DEFAULT NULL;

-- 2) Hearings: eviction date type, review flag
ALTER TABLE `re_legal_hearings`
    MODIFY `event_type` ENUM('hearing','deadline','filing','mediation','site_visit','eviction','other') NOT NULL DEFAULT 'hearing';
ALTER TABLE `re_legal_hearings` ADD COLUMN IF NOT EXISTS `needs_review` TINYINT(1) NOT NULL DEFAULT 0;

-- 3) Notices: review flag
ALTER TABLE `re_legal_notices` ADD COLUMN IF NOT EXISTS `needs_review` TINYINT(1) NOT NULL DEFAULT 0;

-- 4) Case stages: filing -> settlement dept -> judgment -> appeal -> execution
CREATE TABLE IF NOT EXISTS `re_legal_case_stages` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `company_id` INT(11) NOT NULL,
    `case_id` INT(11) NOT NULL,
    `stage_type` ENUM('filing','settlement_dept','hearing','judgment','appeal','execution','other') NOT NULL DEFAULT 'other',
    `reference_number` VARCHAR(60) DEFAULT NULL,
    `stage_date` DATE DEFAULT NULL,
    `outcome` TEXT DEFAULT NULL,
    `awarded_amount` DECIMAL(14,2) DEFAULT NULL,
    `next_date` DATE DEFAULT NULL,
    `needs_review` TINYINT(1) NOT NULL DEFAULT 0,
    `created_by` INT(11) DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT current_timestamp(),
    `updated_at` DATETIME NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
    PRIMARY KEY (`id`),
    KEY `idx_stage_case` (`case_id`),
    KEY `idx_stage_company` (`company_id`),
    KEY `idx_stage_date` (`stage_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- 5) Settlement offers
CREATE TABLE IF NOT EXISTS `re_legal_settlement_offers` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `company_id` INT(11) NOT NULL,
    `case_id` INT(11) NOT NULL,
    `offered_by` ENUM('tenant','landlord','court') NOT NULL DEFAULT 'tenant',
    `amount` DECIMAL(14,2) DEFAULT NULL,
    `offer_date` DATE DEFAULT NULL,
    `status` ENUM('pending','accepted','refused','withdrawn') NOT NULL DEFAULT 'pending',
    `response_date` DATE DEFAULT NULL,
    `notes` TEXT DEFAULT NULL,
    `needs_review` TINYINT(1) NOT NULL DEFAULT 0,
    `created_by` INT(11) DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT current_timestamp(),
    `updated_at` DATETIME NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
    PRIMARY KEY (`id`),
    KEY `idx_offer_case` (`case_id`),
    KEY `idx_offer_company` (`company_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- 6) Differences between an outside document (e.g. counsel PDF) and saifsys
CREATE TABLE IF NOT EXISTS `re_legal_data_checks` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `company_id` INT(11) NOT NULL,
    `tenant_id` INT(11) DEFAULT NULL,
    `lease_id` INT(11) DEFAULT NULL,
    `case_id` INT(11) DEFAULT NULL,
    `topic` VARCHAR(200) NOT NULL,
    `source_label` VARCHAR(150) DEFAULT NULL,
    `source_value` TEXT DEFAULT NULL,
    `system_value` TEXT DEFAULT NULL,
    `status` ENUM('open','resolved') NOT NULL DEFAULT 'open',
    `resolution` TEXT DEFAULT NULL,
    `resolved_by` INT(11) DEFAULT NULL,
    `resolved_at` DATETIME DEFAULT NULL,
    `created_by` INT(11) DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT current_timestamp(),
    PRIMARY KEY (`id`),
    KEY `idx_check_company` (`company_id`),
    KEY `idx_check_tenant` (`tenant_id`),
    KEY `idx_check_case` (`case_id`),
    KEY `idx_check_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
