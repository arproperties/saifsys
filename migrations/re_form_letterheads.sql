-- Header and footer images for the Move-In and Move-Out inspection forms,
-- per company, edited on Real Estate > Form Header & Footer
-- (modules/realestate/form_letterhead.php).
--
-- No row, or an empty path, means the default letterhead used by the
-- contracts: uploads/contracts/header.png and uploads/contracts/footer.png.
--
-- Safe to run more than once.

CREATE TABLE IF NOT EXISTS `re_form_letterheads` (
  `company_id` INT(11) NOT NULL,
  `header_path` VARCHAR(500) DEFAULT NULL,
  `footer_path` VARCHAR(500) DEFAULT NULL,
  `updated_by` INT(11) DEFAULT NULL,
  `updated_at` DATETIME NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`company_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
