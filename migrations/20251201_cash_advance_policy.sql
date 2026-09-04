-- Cash Advance Policy Settings
-- This table stores cash advance policy settings including limits, eligibility criteria, and terms

CREATE TABLE IF NOT EXISTS `cash_advance_policy` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `max_advance_amount_global` decimal(10,2) DEFAULT NULL COMMENT 'Global maximum advance amount (if set, applies to all employees)',
  `max_advance_percentage_salary` decimal(5,2) DEFAULT NULL COMMENT 'Maximum advance as percentage of monthly salary (e.g., 50.00 for 50%)',
  `min_service_months` int(11) DEFAULT 0 COMMENT 'Minimum months of service required to be eligible',
  `max_pending_advances` int(11) DEFAULT 1 COMMENT 'Maximum number of pending advances allowed at once',
  `policy_rules` text DEFAULT NULL COMMENT 'Policy rules and guidelines (HTML allowed)',
  `eligibility_criteria` text DEFAULT NULL COMMENT 'Eligibility criteria description (HTML allowed)',
  `terms_and_conditions` text DEFAULT NULL COMMENT 'Terms and conditions (HTML allowed)',
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Insert default policy (if not exists)
INSERT INTO `cash_advance_policy` (`id`, `max_advance_amount_global`, `max_advance_percentage_salary`, `min_service_months`, `max_pending_advances`, `policy_rules`, `eligibility_criteria`, `terms_and_conditions`, `is_active`)
SELECT 1, NULL, 50.00, 3, 1, 
  '<ul>
    <li>Cash advances are subject to approval by management</li>
    <li>Maximum advance amount is limited based on employee salary and service period</li>
    <li>Only one pending advance request is allowed at a time</li>
    <li>Advances will be deducted from future payroll</li>
  </ul>',
  '<ul>
    <li>Employee must have completed minimum service period</li>
    <li>No outstanding advances beyond the limit</li>
    <li>Employee must be in active employment status</li>
  </ul>',
  '<ul>
    <li>Cash advances are interest-free</li>
    <li>Repayment will be deducted from future salary payments</li>
    <li>Management reserves the right to approve or reject any request</li>
    <li>Approved advances must be used for the stated purpose</li>
  </ul>',
  1
WHERE NOT EXISTS (SELECT 1 FROM `cash_advance_policy` WHERE `id` = 1);

-- Table for per-employee maximum advance limits (overrides global settings)
CREATE TABLE IF NOT EXISTS `cash_advance_employee_limits` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `employee_id` int(11) NOT NULL,
  `max_advance_amount` decimal(10,2) NOT NULL COMMENT 'Maximum advance amount for this specific employee',
  `notes` varchar(255) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `employee_id` (`employee_id`),
  CONSTRAINT `fk_cash_advance_limits_employee` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

