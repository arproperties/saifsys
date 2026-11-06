-- ============================================================================
-- Add 'food_court' to re_units.unit_type ENUM
-- ----------------------------------------------------------------------------
-- Purpose:
--   Park Place needs a "Food Court" unit; the Add/Edit Unit dropdown reads its
--   options from this ENUM, so the new value appears there automatically.
--
-- Safety:
--   Additive ENUM widen only (new value appended at the end). Existing unit
--   rows keep their current values. No data rewrite.
-- ============================================================================

ALTER TABLE `re_units`
  MODIFY COLUMN `unit_type` ENUM(
    'studio',
    '1br',
    '2br',
    '3br',
    '4br',
    'penthouse',
    'commercial',
    'office',
    'big_electrical_room',
    'pump_room',
    'store_room',
    'dining_hall',
    'big_kitchen',
    'labour_room',
    'labour_bathroom',
    'food_court'
  ) NOT NULL;
