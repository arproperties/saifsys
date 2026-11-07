-- Bed Space Rental Agreement: selectable letterheads and the saved agreements.
-- The page also creates these tables on first use (bed_space_contract_helper.php).
-- Existing tables are not changed.

CREATE TABLE IF NOT EXISTS `re_letterheads` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `company_id` INT(11) NOT NULL,
  `name` VARCHAR(150) NOT NULL,
  `type` ENUM('images','pdf') NOT NULL DEFAULT 'images' COMMENT 'images: header/footer pictures; pdf: a full letterhead page behind every page',
  `header_path` VARCHAR(500) DEFAULT NULL COMMENT 'NULL on the built-in one = uploads/contracts/header.(png|jpg|jpeg)',
  `footer_path` VARCHAR(500) DEFAULT NULL COMMENT 'NULL = uploads/contracts/footer.(png|jpg|jpeg)',
  `pdf_path` VARCHAR(500) DEFAULT NULL COMMENT 'Full-page letterhead PDF (type pdf)',
  `margin_top` DECIMAL(5,1) NOT NULL DEFAULT 45.0 COMMENT 'mm kept clear for the PDF letterhead header',
  `margin_bottom` DECIMAL(5,1) NOT NULL DEFAULT 30.0 COMMENT 'mm kept clear for the PDF letterhead footer',
  `is_builtin` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Ain Al Reem: cannot be deleted',
  `is_default` TINYINT(1) NOT NULL DEFAULT 0,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_by` INT(11) DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_company` (`company_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `re_bed_space_contracts` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `company_id` INT(11) NOT NULL,
  `lease_id` INT(11) DEFAULT NULL COMMENT 'Lease the fields were filled from, if any',
  `letterhead_id` INT(11) DEFAULT NULL,
  `plot_no` VARCHAR(100) DEFAULT NULL,
  `agreement_date` DATE DEFAULT NULL,
  `landlord_name` VARCHAR(255) DEFAULT NULL,
  `tenant_name` VARCHAR(255) DEFAULT NULL,
  `subject_of_tenancy` VARCHAR(255) DEFAULT NULL,
  `start_date` DATE DEFAULT NULL,
  `end_date` DATE DEFAULT NULL,
  `unit_no` VARCHAR(100) DEFAULT NULL,
  `bed_space_no` VARCHAR(50) DEFAULT NULL,
  `rent` DECIMAL(12,2) DEFAULT NULL,
  `terms_of_payment` VARCHAR(255) DEFAULT NULL,
  `security_deposit` DECIMAL(12,2) DEFAULT NULL,
  `status` ENUM('draft','final','cancelled') NOT NULL DEFAULT 'draft' COMMENT 'Draft: edit/delete. Final: locked, PDF made. Cancelled: final cancelled with a reason',
  `contract_path` VARCHAR(500) DEFAULT NULL,
  `generated_at` DATETIME DEFAULT NULL,
  `confirmed_at` DATETIME DEFAULT NULL,
  `confirmed_by` INT(11) DEFAULT NULL,
  `cancelled_at` DATETIME DEFAULT NULL,
  `cancelled_by` INT(11) DEFAULT NULL,
  `cancel_reason` TEXT DEFAULT NULL,
  `created_by` INT(11) DEFAULT NULL,
  `updated_by` INT(11) DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_company` (`company_id`),
  KEY `idx_lease` (`lease_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
