-- Move-In checklist: remove "Welcome package", add five new points,
-- and let re_move_in_photos hold the inspection report as well as photos.
-- Safe to run more than once.

-- ============================================================================
-- 1. Photos table: tell photos and inspection reports apart
-- ============================================================================
ALTER TABLE `re_move_in_photos`
  ADD COLUMN IF NOT EXISTS `file_type` ENUM('photo', 'inspection_report') NOT NULL DEFAULT 'photo' AFTER `move_in_id`;

-- ============================================================================
-- 2. Remove "Welcome package"
--    Templates: removed for every company.
--    Move-ins: removed from pending / in-progress ones only; completed and
--    cancelled move-ins keep their history as it was signed off.
-- ============================================================================
DELETE ci FROM `re_move_in_checklist_items` ci
JOIN `re_move_ins` mi ON mi.id = ci.move_in_id
WHERE ci.item_name LIKE 'Welcome package%'
  AND mi.status IN ('pending', 'in_progress');

DELETE FROM `re_move_in_checklist_templates`
WHERE item_name LIKE 'Welcome package%';

-- ============================================================================
-- 3. Add the new points to every company that already has a checklist
--    (companies with no templates yet get them from move_in_add.php)
-- ============================================================================
INSERT INTO `re_move_in_checklist_templates`
  (company_id, item_name, item_description, is_required, display_order, is_active)
SELECT c.company_id, n.item_name, n.item_description, n.is_required, n.display_order, 1
FROM (SELECT DISTINCT company_id FROM `re_move_in_checklist_templates`) c
CROSS JOIN (
  SELECT 'Unit cleanliness & pest-free confirmation' AS item_name,
         'Confirm the unit is clean and pest-free before handover' AS item_description,
         1 AS is_required, 8 AS display_order
  UNION ALL SELECT 'Maintenance completion confirmation',
         'Confirm all pending maintenance work in the unit is completed', 1, 9
  UNION ALL SELECT 'Tenant comments & requests',
         'Record any comments or requests from the tenant in the notes', 0, 10
  UNION ALL SELECT 'Additional services & details',
         'Record any additional services provided or requested, with details, in the notes', 0, 11
  UNION ALL SELECT 'Move-in photos & inspection report uploaded',
         'Upload the move-in photos and the inspection report', 0, 12
) n
WHERE NOT EXISTS (
  SELECT 1 FROM `re_move_in_checklist_templates` t
  WHERE t.company_id = c.company_id AND t.item_name = n.item_name
);

-- ============================================================================
-- 4. Add the new points to move-ins that are still open
-- ============================================================================
INSERT INTO `re_move_in_checklist_items`
  (company_id, move_in_id, checklist_template_id, item_name, item_description, is_required, display_order)
SELECT mi.company_id, mi.id, t.id, t.item_name, t.item_description, t.is_required, t.display_order
FROM `re_move_ins` mi
JOIN `re_move_in_checklist_templates` t
  ON t.company_id = mi.company_id
 AND t.is_active = 1
 AND t.item_name IN (
   'Unit cleanliness & pest-free confirmation',
   'Maintenance completion confirmation',
   'Tenant comments & requests',
   'Additional services & details',
   'Move-in photos & inspection report uploaded'
 )
WHERE mi.status IN ('pending', 'in_progress')
  AND NOT EXISTS (
    SELECT 1 FROM `re_move_in_checklist_items` ci
    WHERE ci.move_in_id = mi.id AND ci.item_name = t.item_name
  );
