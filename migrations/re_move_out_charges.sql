-- Standard Move-Out Charges (cleaning, painting, pest control, ...)
--
-- re_move_out_charge_types   the company's list, edited on the Standard
--                            Move-Out Charges page (no amounts: they differ
--                            per unit). Cleaning, Painting and Pest Control
--                            are added the first time it is used.
-- re_move_out_charges        each move-out's own copy of the list; staff enter
--                            the amount (NULL = not entered, 0 = no charge) and
--                            may add one-off charges. Every charge with an amount
--                            becomes a security deposit deduction when the
--                            settlement is prepared.
--
-- Safe to run more than once.

CREATE TABLE IF NOT EXISTS `re_move_out_charge_types` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `company_id` INT(11) NOT NULL,
  `name` VARCHAR(100) NOT NULL,
  `display_order` INT(11) NOT NULL DEFAULT 0,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL DEFAULT current_timestamp(),
  `updated_at` DATETIME NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_rmoct_company` (`company_id`, `is_active`, `display_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `re_move_out_charges` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `company_id` INT(11) NOT NULL,
  `move_out_id` INT(11) NOT NULL,
  `charge_name` VARCHAR(100) NOT NULL,
  `amount` DECIMAL(10,2) DEFAULT NULL COMMENT 'NULL = not entered yet',
  `note` VARCHAR(255) DEFAULT NULL,
  `is_custom` TINYINT(1) NOT NULL DEFAULT 0,
  `display_order` INT(11) NOT NULL DEFAULT 0,
  `updated_by` INT(11) DEFAULT NULL,
  `updated_at` DATETIME NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_rmoc_move_out` (`move_out_id`, `display_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- A charge removed from one move-out (not needed for that unit) is kept as
-- removed, so the list is not copied in again; it can be restored.
ALTER TABLE `re_move_out_charges`
  ADD COLUMN IF NOT EXISTS `is_removed` TINYINT(1) NOT NULL DEFAULT 0 AFTER `is_custom`;
