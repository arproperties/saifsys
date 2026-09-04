-- ============================================================
-- Migration: Lease Termination Workflow + Returned Cheques
-- Safe to run multiple times on MariaDB/MySQL 8 compatible setups.
-- ============================================================

ALTER TABLE `re_leases`
    ADD COLUMN IF NOT EXISTS `termination_date` DATE NULL
        COMMENT 'Contract termination effective date' AFTER `move_out_date`,
    ADD COLUMN IF NOT EXISTS `termination_reason` TEXT NULL
        COMMENT 'Reason/notes captured when lease is terminated' AFTER `termination_date`,
    ADD COLUMN IF NOT EXISTS `terminated_by` INT(11) NULL
        COMMENT 'user.id who terminated the lease' AFTER `termination_reason`,
    ADD COLUMN IF NOT EXISTS `terminated_at` DATETIME NULL
        COMMENT 'Timestamp when termination workflow was applied' AFTER `terminated_by`;

ALTER TABLE `re_leases`
    ADD INDEX IF NOT EXISTS `idx_termination_date` (`termination_date`);

ALTER TABLE `re_post_dated_cheques`
    MODIFY COLUMN `status` ENUM('pending','deposited','cleared','bounced','cancelled','returned') NOT NULL DEFAULT 'pending';

ALTER TABLE `re_lease_cheques`
    MODIFY COLUMN `status` ENUM('pending','deposited','cleared','bounced','cancelled','returned') NOT NULL DEFAULT 'pending';

ALTER TABLE `re_lease_installments`
    MODIFY COLUMN `status` ENUM('pending','paid','overdue','waived','partial','cancelled') NOT NULL DEFAULT 'pending';

