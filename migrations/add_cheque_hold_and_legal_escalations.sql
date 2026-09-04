-- ============================================================================
-- Cheque "hold" status + Legal cheque escalation/notification inbox
-- ----------------------------------------------------------------------------
-- 1) Adds a 'hold' status to the post-dated / lease cheque tables so an
--    accountant can park a cheque (do not deposit yet).
-- 2) Creates re_legal_cheque_escalations: the legal department's notification
--    inbox for bounced cheques and "escalate to legal" requests raised from
--    the Lease View page.
-- Safe to run multiple times where noted.
-- ============================================================================

ALTER TABLE `re_post_dated_cheques`
    MODIFY COLUMN `status` ENUM('pending','deposited','cleared','bounced','cancelled','returned','hold') NOT NULL DEFAULT 'pending';

ALTER TABLE `re_lease_cheques`
    MODIFY COLUMN `status` ENUM('pending','deposited','cleared','bounced','cancelled','returned','hold') NOT NULL DEFAULT 'pending';

CREATE TABLE IF NOT EXISTS `re_legal_cheque_escalations` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `company_id` INT(11) NOT NULL,
    `lease_id` INT(11) NOT NULL,
    `installment_id` INT(11) DEFAULT NULL,
    `cheque_id` INT(11) DEFAULT NULL,
    `tenant_id` INT(11) DEFAULT NULL,
    `type` ENUM('bounced','escalated','hold') NOT NULL DEFAULT 'escalated',
    `cheque_number` VARCHAR(100) DEFAULT NULL,
    `cheque_amount` DECIMAL(10,2) DEFAULT NULL,
    `cheque_date` DATE DEFAULT NULL,
    `reason` TEXT DEFAULT NULL,
    `status` ENUM('new','acknowledged','resolved') NOT NULL DEFAULT 'new',
    `legal_case_id` INT(11) DEFAULT NULL,
    `created_by` INT(11) DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `acknowledged_by` INT(11) DEFAULT NULL,
    `acknowledged_at` DATETIME DEFAULT NULL,
    `resolved_by` INT(11) DEFAULT NULL,
    `resolved_at` DATETIME DEFAULT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_company` (`company_id`),
    KEY `idx_lease` (`lease_id`),
    KEY `idx_status` (`status`),
    KEY `idx_type` (`type`),
    KEY `idx_cheque` (`cheque_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
