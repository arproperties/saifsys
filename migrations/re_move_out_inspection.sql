-- Move-Out inspection checklist
--
-- * Move-Out Inspection Template (sections + checklist items) per company,
--   separate from the move-in one and editable on the Move-Out Inspection
--   Template page. Started from a copy of the company's move-in template.
-- * Each move-out gets its own copy of the template's items, so editing the
--   template never changes a move-out already under way.
-- * Every item is marked OK, Damaged or N/A. A Damaged item is linked to the
--   damage it was recorded as (re_move_out_damages), which feeds the damage
--   assessment and the security deposit settlement.
-- * The final inspection can only be completed once every item is marked.
--
-- Requires migrations/re_move_in_stages.sql (the template tables).
-- Safe to run more than once.

CREATE TABLE IF NOT EXISTS `re_move_out_inspection_sections` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `company_id` INT(11) NOT NULL,
  `name` VARCHAR(150) NOT NULL,
  `display_order` INT(11) NOT NULL DEFAULT 0,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL DEFAULT current_timestamp(),
  `updated_at` DATETIME NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_rmos_company` (`company_id`, `is_active`, `display_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `re_move_out_inspection_template_items` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `company_id` INT(11) NOT NULL,
  `section_id` INT(11) NOT NULL,
  `item_text` VARCHAR(255) NOT NULL,
  `display_order` INT(11) NOT NULL DEFAULT 0,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL DEFAULT current_timestamp(),
  `updated_at` DATETIME NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_rmoti_section` (`section_id`, `is_active`, `display_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `re_move_out_inspection_items` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `company_id` INT(11) NOT NULL,
  `move_out_id` INT(11) NOT NULL,
  `section_name` VARCHAR(150) NOT NULL,
  `section_order` INT(11) NOT NULL DEFAULT 0,
  `item_text` VARCHAR(255) NOT NULL,
  `item_order` INT(11) NOT NULL DEFAULT 0,
  `is_custom` TINYINT(1) NOT NULL DEFAULT 0,
  `status` ENUM('open','ok','damaged','na') NOT NULL DEFAULT 'open',
  `remark` VARCHAR(500) DEFAULT NULL,
  `damage_id` INT(11) DEFAULT NULL,
  `checked_by` INT(11) DEFAULT NULL,
  `checked_at` DATETIME DEFAULT NULL,
  `created_by` INT(11) DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_rmoi_move_out` (`move_out_id`, `section_order`, `item_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

ALTER TABLE `re_move_out_damages`
  ADD COLUMN IF NOT EXISTS `inspection_item_id` INT(11) DEFAULT NULL AFTER `move_out_id`;

-- Walkthrough section: its items are only OK or N/A (no Damaged), and the
-- section cannot be hidden from the template. Flagged, not matched by name,
-- so renaming the section keeps it.
ALTER TABLE `re_move_out_inspection_sections`
  ADD COLUMN IF NOT EXISTS `is_walkthrough` TINYINT(1) NOT NULL DEFAULT 0 AFTER `is_active`;
ALTER TABLE `re_move_out_inspection_items`
  ADD COLUMN IF NOT EXISTS `is_walkthrough` TINYINT(1) NOT NULL DEFAULT 0 AFTER `is_custom`;
UPDATE `re_move_out_inspection_sections` SET `is_walkthrough` = 1, `is_active` = 1 WHERE `name` LIKE '%walkthrough%';
UPDATE `re_move_out_inspection_items` SET `is_walkthrough` = 1 WHERE `section_name` LIKE '%walkthrough%';
