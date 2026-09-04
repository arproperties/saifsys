-- ============================================================================
-- Maintenance locations: Unit OR Common Area (+ legacy building-only WOs)
-- Additive only. Run before deploying UI that depends on these columns.
-- ============================================================================
--
-- PRE-FLIGHT AUDIT (run manually on target DB; copy results to deploy notes):
--
--   SELECT s.id, s.company_id, s.work_order_number, s.building_id, s.unit_id,
--          s.title, s.schedule_date, s.status, b.name AS building_name
--   FROM re_maintenance_schedules s
--   LEFT JOIN re_buildings b ON b.id = s.building_id
--   WHERE s.building_id IS NOT NULL AND s.building_id > 0
--     AND (s.unit_id IS NULL OR s.unit_id = 0)
--   ORDER BY s.id;
--
-- If rows exist: after this migration they receive location_type='building'
-- (legacy). New creates still require unit or common_area only.
-- If zero rows: proceed normally (backfill still safe).
-- ============================================================================

-- ---------------------------------------------------------------------------
-- 1) Common areas: expanded types + unique name per building
-- ---------------------------------------------------------------------------
-- Resolve duplicate names before unique index (append " (id)" if needed).
UPDATE re_building_common_areas ca
JOIN (
    SELECT building_id, area_name
    FROM re_building_common_areas
    GROUP BY building_id, area_name
    HAVING COUNT(*) > 1
) d ON d.building_id = ca.building_id AND d.area_name = ca.area_name
SET ca.area_name = CONCAT(ca.area_name, ' (', ca.id, ')')
WHERE ca.id NOT IN (
    SELECT keep_id FROM (
        SELECT MIN(id) AS keep_id
        FROM re_building_common_areas
        GROUP BY building_id, area_name
    ) x
);

SET @idx := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 're_building_common_areas'
      AND INDEX_NAME = 'uq_re_building_common_areas_name'
);
SET @sql := IF(@idx = 0,
    'ALTER TABLE `re_building_common_areas` ADD UNIQUE KEY `uq_re_building_common_areas_name` (`building_id`, `area_name`)',
    'SELECT ''uq_re_building_common_areas_name exists'' AS msg');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Expand area_type enum (additive values).
ALTER TABLE `re_building_common_areas`
  MODIFY COLUMN `area_type` ENUM(
    'lobby','reception','corridor','elevator','staircase','rooftop','garden',
    'playground','parking','storage','swimming_pool','pump_room','electrical_room','other'
  ) NOT NULL DEFAULT 'other';

-- ---------------------------------------------------------------------------
-- 2) re_maintenance_requests
-- ---------------------------------------------------------------------------
SET @col := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 're_maintenance_requests' AND COLUMN_NAME = 'location_type'
);
SET @sql := IF(@col = 0,
    'ALTER TABLE `re_maintenance_requests`
        ADD COLUMN `location_type` ENUM(''unit'',''common_area'',''building'') NOT NULL DEFAULT ''unit'' AFTER `unit_id`,
        ADD COLUMN `building_id` INT(11) NULL DEFAULT NULL AFTER `location_type`,
        ADD COLUMN `common_area_id` INT(11) NULL DEFAULT NULL AFTER `building_id`,
        ADD KEY `idx_re_maint_req_building` (`building_id`),
        ADD KEY `idx_re_maint_req_common_area` (`common_area_id`),
        ADD KEY `idx_re_maint_req_location_type` (`location_type`)',
    'SELECT ''re_maintenance_requests location columns exist'' AS msg');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Allow common-area requests (unit optional).
ALTER TABLE `re_maintenance_requests` MODIFY COLUMN `unit_id` INT(11) NULL DEFAULT NULL;

-- Backfill unit requests from unit → building.
UPDATE re_maintenance_requests r
JOIN re_units u ON u.id = r.unit_id
SET r.building_id = u.building_id,
    r.location_type = 'unit'
WHERE r.unit_id IS NOT NULL
  AND (r.building_id IS NULL OR r.building_id = 0 OR r.location_type = 'unit');

-- ---------------------------------------------------------------------------
-- 3) re_maintenance_schedules
-- ---------------------------------------------------------------------------
SET @col := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 're_maintenance_schedules' AND COLUMN_NAME = 'location_type'
);
SET @sql := IF(@col = 0,
    'ALTER TABLE `re_maintenance_schedules`
        ADD COLUMN `location_type` ENUM(''unit'',''common_area'',''building'') NOT NULL DEFAULT ''unit'' AFTER `unit_id`,
        ADD COLUMN `common_area_id` INT(11) NULL DEFAULT NULL AFTER `location_type`,
        ADD KEY `idx_re_maint_sched_common_area` (`common_area_id`),
        ADD KEY `idx_re_maint_sched_location_type` (`location_type`)',
    'SELECT ''re_maintenance_schedules location columns exist'' AS msg');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Unit schedules
UPDATE re_maintenance_schedules
SET location_type = 'unit'
WHERE unit_id IS NOT NULL AND unit_id > 0;

-- Backfill building_id from unit when missing
UPDATE re_maintenance_schedules s
JOIN re_units u ON u.id = s.unit_id
SET s.building_id = u.building_id
WHERE (s.building_id IS NULL OR s.building_id = 0)
  AND s.unit_id IS NOT NULL AND s.unit_id > 0;

-- Legacy: building set, no unit → location_type=building (NOT unit with null unit)
UPDATE re_maintenance_schedules
SET location_type = 'building',
    unit_id = NULL,
    common_area_id = NULL
WHERE building_id IS NOT NULL AND building_id > 0
  AND (unit_id IS NULL OR unit_id = 0);

-- Post-migration audit snapshot (informational)
SELECT 'legacy_building_only_schedules' AS audit_key, COUNT(*) AS cnt
FROM re_maintenance_schedules
WHERE location_type = 'building';
