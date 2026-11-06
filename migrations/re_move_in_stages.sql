-- Move-In stages: Contract Signed -> Payment Confirmed -> Inspection -> Key Handover
--
-- * Inspection template (sections + checklist items) per company, editable on
--   the Move-In Inspection Template page. Seeded with the long-term (LT)
--   items from the Move-In Inspection Report.
-- * Each move-in keeps its own copy of the items (re_move_in_inspection_items),
--   so editing the template never changes a move-in already under way.
-- * Key handover rows per move-in (Main Door Key, MDF Cabinet, ...).
-- * Open (pending / in progress) move-ins are converted to the new stages.
--
-- Safe to run more than once.

-- ---------------------------------------------------------------------------
-- 1. Columns on re_move_ins
-- ---------------------------------------------------------------------------
ALTER TABLE `re_move_ins`
  ADD COLUMN IF NOT EXISTS `workflow_version` TINYINT(1) NOT NULL DEFAULT 1 AFTER `status`,
  ADD COLUMN IF NOT EXISTS `payment_override` TINYINT(1) NOT NULL DEFAULT 0 AFTER `payment_confirmed_at`,
  ADD COLUMN IF NOT EXISTS `payment_override_by` INT(11) DEFAULT NULL AFTER `payment_override`,
  ADD COLUMN IF NOT EXISTS `payment_override_at` DATETIME DEFAULT NULL AFTER `payment_override_by`,
  ADD COLUMN IF NOT EXISTS `payment_override_reason` VARCHAR(500) DEFAULT NULL AFTER `payment_override_at`;

-- ---------------------------------------------------------------------------
-- 2. Tables
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `re_move_in_inspection_sections` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `company_id` INT(11) NOT NULL,
  `name` VARCHAR(150) NOT NULL,
  `display_order` INT(11) NOT NULL DEFAULT 0,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL DEFAULT current_timestamp(),
  `updated_at` DATETIME NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_rmis_company` (`company_id`, `is_active`, `display_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `re_move_in_inspection_template_items` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `company_id` INT(11) NOT NULL,
  `section_id` INT(11) NOT NULL,
  `item_text` VARCHAR(255) NOT NULL,
  `display_order` INT(11) NOT NULL DEFAULT 0,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL DEFAULT current_timestamp(),
  `updated_at` DATETIME NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_rmiti_section` (`section_id`, `is_active`, `display_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `re_move_in_inspection_items` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `company_id` INT(11) NOT NULL,
  `move_in_id` INT(11) NOT NULL,
  `section_name` VARCHAR(150) NOT NULL,
  `section_order` INT(11) NOT NULL DEFAULT 0,
  `item_text` VARCHAR(255) NOT NULL,
  `item_order` INT(11) NOT NULL DEFAULT 0,
  `is_custom` TINYINT(1) NOT NULL DEFAULT 0,
  `is_checked` TINYINT(1) NOT NULL DEFAULT 0,
  `checked_by` INT(11) DEFAULT NULL,
  `checked_at` DATETIME DEFAULT NULL,
  `note` VARCHAR(255) DEFAULT NULL,
  `created_by` INT(11) DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_rmii_move_in` (`move_in_id`, `section_order`, `item_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `re_move_in_keys` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `company_id` INT(11) NOT NULL,
  `move_in_id` INT(11) NOT NULL,
  `key_label` VARCHAR(100) NOT NULL,
  `quantity` INT(11) NOT NULL DEFAULT 0,
  `card_numbers` VARCHAR(255) DEFAULT NULL,
  `display_order` INT(11) NOT NULL DEFAULT 0,
  `updated_by` INT(11) DEFAULT NULL,
  `updated_at` DATETIME NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_rmik_move_in` (`move_in_id`, `display_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ---------------------------------------------------------------------------
-- 3. Default long-term template, for every company that has leases and no
--    template yet
-- ---------------------------------------------------------------------------
DROP TEMPORARY TABLE IF EXISTS `tmp_rmis_default`;
-- Explicit collation: the database default may differ from the tables'
-- (e.g. a live import with utf8mb4_unicode_ci), which breaks the joins below.
CREATE TEMPORARY TABLE `tmp_rmis_default` (
  `section_name` VARCHAR(150) COLLATE utf8mb4_general_ci NOT NULL,
  `section_order` INT NOT NULL,
  `item_text` VARCHAR(255) COLLATE utf8mb4_general_ci NOT NULL,
  `item_order` INT NOT NULL
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
INSERT INTO `tmp_rmis_default` VALUES
  ('General Condition', 1, 'Apartment is clean and in good condition', 1),
  ('General Condition', 1, 'No major wall damage (holes, stains, etc.)', 2),
  ('General Condition', 1, 'AC unit is cooling properly', 3),
  ('General Condition', 1, 'AC thermostat working and functional', 4),
  ('General Condition', 1, 'No foul odor; space is well-ventilated', 5),
  ('General Condition', 1, 'Floors clean and undamaged', 6),
  ('Lighting & Electrical', 2, 'All light fixtures/bulbs functional', 1),
  ('Lighting & Electrical', 2, 'All power sockets tested and working', 2),
  ('Lighting & Electrical', 2, 'Switches and circuit breakers working properly', 3),
  ('Lighting & Electrical', 2, 'Water heater working', 4),
  ('Kitchen', 3, 'Countertops, sink, and cabinets clean and undamaged', 1),
  ('Kitchen', 3, 'Exhaust/hood filter cleaned', 2),
  ('Kitchen', 3, 'Sink clean; no leaks; strainer clear', 3),
  ('Kitchen', 3, 'All cabinets clean and functional', 4),
  ('Unit', 4, 'Room clean and free of personal items', 1),
  ('Unit', 4, 'Walls clean; no holes, scratches, or stains', 2),
  ('Unit', 4, 'Floor clean and undamaged', 3),
  ('Unit', 4, 'Built-in wardrobe/cabinets clean and functional', 4),
  ('Unit', 4, 'Light fixtures working', 5),
  ('Unit', 4, 'AC unit cooling properly', 6),
  ('Unit', 4, 'AC thermostat working and functional', 7),
  ('Unit', 4, 'Windows and locks working', 8),
  ('Unit', 4, 'Drawers open/close properly', 9),
  ('Bathroom', 5, 'Toilets flushed and clean', 1),
  ('Bathroom', 5, 'Bidet and bidet shower working, and fixtures in good condition', 2),
  ('Bathroom', 5, 'Sink, shower, and faucets functioning without leaks', 3),
  ('Bathroom', 5, 'Mirrors, shelves, and fixtures in good condition', 4),
  ('Bathroom', 5, 'No molds or water damage', 5),
  ('Bathroom', 5, 'Drains clear and not clogged', 6),
  ('Bathroom', 5, 'No foul odor; space is well-ventilated', 7),
  ('Additional Areas', 6, 'Balcony/terrace cleaned, no debris', 1),
  ('Additional Areas', 6, 'Windows and sliding doors clean and functional', 2),
  ('Additional Areas', 6, 'Storage rooms/maid''s room empty and clean', 3),
  ('Additional Areas', 6, 'Parking slot clean (if applicable)', 4),
  ('Final Walkthrough', 7, 'Walkthrough conducted with tenant and the maintenance service provider M/s Al Mezaya Technical Services', 1),
  ('Final Walkthrough', 7, 'Photos/videos taken of current condition', 2),
  ('Final Walkthrough', 7, 'Move-in inspection form completed and signed', 3);

DROP TEMPORARY TABLE IF EXISTS `tmp_rmis_companies`;
CREATE TEMPORARY TABLE `tmp_rmis_companies` AS
SELECT DISTINCT l.company_id
FROM `re_leases` l
WHERE NOT EXISTS (
  SELECT 1 FROM `re_move_in_inspection_sections` s WHERE s.company_id = l.company_id
);

INSERT INTO `re_move_in_inspection_sections` (`company_id`, `name`, `display_order`)
SELECT c.company_id, d.section_name, d.section_order
FROM `tmp_rmis_companies` c
CROSS JOIN (SELECT DISTINCT section_name, section_order FROM `tmp_rmis_default`) d;

-- Items for every default-named section that has none yet (also completes
-- an earlier run that stopped after creating the sections)
INSERT INTO `re_move_in_inspection_template_items` (`company_id`, `section_id`, `item_text`, `display_order`)
SELECT s.company_id, s.id, d.item_text, d.item_order
FROM `re_move_in_inspection_sections` s
JOIN `tmp_rmis_default` d ON d.section_name = s.name
WHERE NOT EXISTS (
  SELECT 1 FROM `re_move_in_inspection_template_items` t WHERE t.section_id = s.id
);

-- ---------------------------------------------------------------------------
-- 4. Convert open move-ins to the new stages (completed / cancelled stay as
--    they are). Carries over what was already ticked on the old checklist.
-- ---------------------------------------------------------------------------
DROP TEMPORARY TABLE IF EXISTS `tmp_rmis_convert`;
CREATE TEMPORARY TABLE `tmp_rmis_convert` AS
SELECT mi.id, mi.company_id
FROM `re_move_ins` mi
WHERE mi.status IN ('pending', 'in_progress')
  AND mi.workflow_version = 1;

-- Inspection items from the company template; ticked when the old
-- "Initial inspection completed" item or flag was done
INSERT INTO `re_move_in_inspection_items`
  (`company_id`, `move_in_id`, `section_name`, `section_order`, `item_text`, `item_order`,
   `is_checked`, `checked_by`, `checked_at`, `note`)
SELECT c.company_id, c.id, s.name, s.display_order, t.item_text, t.display_order,
       IF(mi.inspection_completed = 1 OR old_insp.is_completed = 1, 1, 0),
       IF(mi.inspection_completed = 1 OR old_insp.is_completed = 1, COALESCE(mi.inspection_completed_by, old_insp.completed_by), NULL),
       IF(mi.inspection_completed = 1 OR old_insp.is_completed = 1, COALESCE(mi.inspection_completed_at, old_insp.completed_at, NOW()), NULL),
       IF(mi.inspection_completed = 1 OR old_insp.is_completed = 1, 'Carried over from the previous checklist', NULL)
FROM `tmp_rmis_convert` c
JOIN `re_move_ins` mi ON mi.id = c.id
JOIN `re_move_in_inspection_sections` s ON s.company_id = c.company_id AND s.is_active = 1
JOIN `re_move_in_inspection_template_items` t ON t.section_id = s.id AND t.is_active = 1
LEFT JOIN `re_move_in_checklist_items` old_insp
  ON old_insp.move_in_id = c.id AND LOWER(old_insp.item_name) = 'initial inspection completed'
WHERE NOT EXISTS (SELECT 1 FROM `re_move_in_inspection_items` x WHERE x.move_in_id = c.id);

-- Key handover rows
INSERT INTO `re_move_in_keys` (`company_id`, `move_in_id`, `key_label`, `display_order`)
SELECT c.company_id, c.id, k.label, k.ord
FROM `tmp_rmis_convert` c
CROSS JOIN (
  SELECT 'Main Door Key' AS label, 1 AS ord
  UNION ALL SELECT 'MDF Cabinet', 2
  UNION ALL SELECT 'Access Card', 3
  UNION ALL SELECT 'Parking Access Card', 4
  UNION ALL SELECT 'Others', 5
) k
WHERE NOT EXISTS (SELECT 1 FROM `re_move_in_keys` x WHERE x.move_in_id = c.id);

-- Contract: carry over from the old checklist item
UPDATE `re_move_ins` mi
JOIN `tmp_rmis_convert` c ON c.id = mi.id
JOIN `re_move_in_checklist_items` ci
  ON ci.move_in_id = mi.id AND LOWER(ci.item_name) = 'contract signed and verified' AND ci.is_completed = 1
SET mi.contract_verified = 1,
    mi.contract_verified_by = COALESCE(mi.contract_verified_by, ci.completed_by),
    mi.contract_verified_at = COALESCE(mi.contract_verified_at, ci.completed_at)
WHERE mi.contract_verified = 0;

-- Payment: a payment already confirmed on the old checklist becomes an override
UPDATE `re_move_ins` mi
JOIN `tmp_rmis_convert` c ON c.id = mi.id
SET mi.payment_override = 1,
    mi.payment_override_by = mi.payment_confirmed_by,
    mi.payment_override_at = COALESCE(mi.payment_confirmed_at, NOW()),
    mi.payment_override_reason = 'Carried over from the previous checklist (payment was confirmed there)'
WHERE mi.payment_confirmed = 1 AND mi.payment_override = 0;

UPDATE `re_move_ins` mi
JOIN `tmp_rmis_convert` c ON c.id = mi.id
SET mi.workflow_version = 2;

DROP TEMPORARY TABLE IF EXISTS `tmp_rmis_convert`;
DROP TEMPORARY TABLE IF EXISTS `tmp_rmis_companies`;
DROP TEMPORARY TABLE IF EXISTS `tmp_rmis_default`;
