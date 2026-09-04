-- ============================================================================
-- Legal Department Module - Phase 2
-- Hearings calendar, external counsel, cost tracking, standalone module RBAC
-- Idempotent: CREATE TABLE IF NOT EXISTS. Safe to re-run.
-- ============================================================================

-- ----------------------------------------------------------------------------
-- 1) External counsel registry
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `re_legal_counsel` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `company_id` INT(11) NOT NULL,
    `counsel_type` ENUM('firm','individual') NOT NULL DEFAULT 'firm',
    `name` VARCHAR(200) NOT NULL,
    `contact_person` VARCHAR(200) DEFAULT NULL,
    `email` VARCHAR(150) DEFAULT NULL,
    `phone` VARCHAR(50) DEFAULT NULL,
    `license_number` VARCHAR(100) DEFAULT NULL,
    `specialization` VARCHAR(200) DEFAULT NULL,
    `hourly_rate` DECIMAL(12,2) DEFAULT NULL,
    `notes` TEXT DEFAULT NULL,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` DATETIME NOT NULL DEFAULT current_timestamp(),
    `updated_at` DATETIME NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
    PRIMARY KEY (`id`),
    KEY `idx_counsel_company` (`company_id`),
    KEY `idx_counsel_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ----------------------------------------------------------------------------
-- 2) Hearings & deadline calendar events
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `re_legal_hearings` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `company_id` INT(11) NOT NULL,
    `case_id` INT(11) DEFAULT NULL,
    `event_type` ENUM('hearing','deadline','filing','mediation','site_visit','other') NOT NULL DEFAULT 'hearing',
    `title` VARCHAR(255) NOT NULL,
    `description` TEXT DEFAULT NULL,
    `scheduled_date` DATE NOT NULL,
    `scheduled_time` TIME DEFAULT NULL,
    `location` VARCHAR(255) DEFAULT NULL,
    `court_room` VARCHAR(100) DEFAULT NULL,
    `counsel_id` INT(11) DEFAULT NULL,
    `assigned_to` INT(11) DEFAULT NULL,
    `status` ENUM('scheduled','completed','postponed','cancelled') NOT NULL DEFAULT 'scheduled',
    `outcome` TEXT DEFAULT NULL,
    `reminder_days_before` INT(11) NOT NULL DEFAULT 3,
    `created_by` INT(11) DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT current_timestamp(),
    `updated_at` DATETIME NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
    PRIMARY KEY (`id`),
    KEY `idx_hearing_company` (`company_id`),
    KEY `idx_hearing_case` (`case_id`),
    KEY `idx_hearing_date` (`scheduled_date`),
    KEY `idx_hearing_status` (`status`),
    KEY `idx_hearing_counsel` (`counsel_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ----------------------------------------------------------------------------
-- 3) Legal cost tracking (per case)
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `re_legal_case_costs` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `company_id` INT(11) NOT NULL,
    `case_id` INT(11) NOT NULL,
    `cost_type` ENUM('court_fee','counsel_fee','notary','courier','filing','translation','expert','other') NOT NULL DEFAULT 'other',
    `description` VARCHAR(255) DEFAULT NULL,
    `amount` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    `cost_date` DATE DEFAULT NULL,
    `counsel_id` INT(11) DEFAULT NULL,
    `invoice_reference` VARCHAR(100) DEFAULT NULL,
    `is_recoverable` TINYINT(1) NOT NULL DEFAULT 1,
    `is_recovered` TINYINT(1) NOT NULL DEFAULT 0,
    `payment_method` VARCHAR(50) DEFAULT NULL,
    `notes` TEXT DEFAULT NULL,
    `created_by` INT(11) DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT current_timestamp(),
    PRIMARY KEY (`id`),
    KEY `idx_cost_company` (`company_id`),
    KEY `idx_cost_case` (`case_id`),
    KEY `idx_cost_date` (`cost_date`),
    KEY `idx_cost_counsel` (`counsel_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Optional: primary counsel on case
ALTER TABLE `re_legal_cases` ADD COLUMN IF NOT EXISTS `counsel_id` INT(11) DEFAULT NULL;

-- ----------------------------------------------------------------------------
-- 4) Standalone Legal module RBAC migration
-- Move realestate_legal department rows to module=legal, department=legal
-- ----------------------------------------------------------------------------
UPDATE `role_departments`
SET `module` = 'legal', `department` = 'legal'
WHERE `module` = 'realestate' AND `department` = 'realestate_legal';
