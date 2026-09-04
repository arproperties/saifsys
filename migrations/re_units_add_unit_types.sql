-- ============================================================================
-- Expand re_units.unit_type ENUM with additional facility / commercial types
-- ----------------------------------------------------------------------------
-- Purpose:
--   Allow Add/Edit Unit to store Office, electrical/pump/store rooms, dining hall,
--   and big kitchen (plus labour room types already used in PHP fallback).
--
-- Safety:
--   Additive ENUM widen only. Existing unit rows keep their current values.
--   No data rewrite.
--
-- Rollback intent:
--   Only safe after confirming no units use the new values, then ALTER ENUM back
--   to the previous list. Prefer leaving widened ENUM in place.
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
    'labour_bathroom'
  ) NOT NULL;
