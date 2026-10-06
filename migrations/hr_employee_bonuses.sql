-- Bonuses recorded on the employee profile (HR > Employee > Bonus tab).
-- Payroll build adds the unpaid bonuses dated up to the end of the run period on
-- top of the typed Bonus+, and stores that part in payroll_items.profile_bonus.
-- Posting a run stamps paid_run_id, so a bonus is paid once and one dated inside
-- an already posted period carries into the next run.
CREATE TABLE IF NOT EXISTS `employee_bonuses` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `employee_id` int(11) NOT NULL,
  `bonus_date` date NOT NULL,
  `amount` decimal(12,2) NOT NULL,
  `description` varchar(500) NOT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `paid_run_id` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_eb_emp_date` (`employee_id`,`bonus_date`),
  CONSTRAINT `fk_eb_emp` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Part of payroll_items.bonus that came from employee_bonuses. Safe to re-run.
ALTER TABLE `payroll_items`
  ADD COLUMN IF NOT EXISTS `profile_bonus` decimal(12,2) NOT NULL DEFAULT 0.00 AFTER `bonus`;

-- Run that paid the bonus (NULL = not paid yet). Safe to re-run.
ALTER TABLE `employee_bonuses`
  ADD COLUMN IF NOT EXISTS `paid_run_id` int(11) DEFAULT NULL AFTER `created_at`;
