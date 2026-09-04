# Maintenance schedules: building_id without unit_id (pre-migration audit)

**Date:** 2026-07-27  
**Local DB (`datanew`):** audited before applying `migrations/re_maintenance_common_area_location.sql`

## Query

```sql
SELECT s.id, s.company_id, s.work_order_number, s.building_id, s.unit_id,
       s.title, s.schedule_date, s.status, b.name AS building_name
FROM re_maintenance_schedules s
LEFT JOIN re_buildings b ON b.id = s.building_id
WHERE s.building_id IS NOT NULL AND s.building_id > 0
  AND (s.unit_id IS NULL OR s.unit_id = 0)
ORDER BY s.id;
```

## Local results

| Metric | Value |
|--------|-------|
| Affected schedules (building, no unit) | **0** |
| Schedules with neither building nor unit | **0** |
| Total schedules | **4** |

**Conclusion (local):** No legacy building-only rows. Migration proceeds with normal unit backfill; the `location_type='building'` path remains available for live DBs that may have such rows.

## Live / deploy instruction

Re-run the query above on the target database **before** migration. Paste results into the deploy notes.

- If **0 rows**: proceed.
- If **N rows**: after migration they are preserved as `location_type='building'` (viewable; must be manually assigned to Unit or Common Area later). Do **not** invent units or auto-assign common areas.
