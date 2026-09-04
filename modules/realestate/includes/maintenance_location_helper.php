<?php
/**
 * Maintenance location: Unit | Common Area | legacy Building-only schedules.
 */

if (!function_exists('re_maint_require_company')) {
    function re_maint_require_company(PDO $conn): int
    {
        $companyId = (int)(current_company_id($conn) ?: 0);
        if ($companyId <= 0) {
            http_response_code(400);
            die('Company context is required.');
        }
        return $companyId;
    }
}

if (!function_exists('re_maint_location_columns_ready')) {
    function re_maint_location_columns_ready(PDO $conn): bool
    {
        static $ready = null;
        if ($ready !== null) {
            return $ready;
        }
        try {
            $n = (int)$conn->query("
                SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE()
                  AND TABLE_NAME = 're_maintenance_requests'
                  AND COLUMN_NAME = 'location_type'
            ")->fetchColumn();
            $ready = $n > 0;
        } catch (Throwable $e) {
            $ready = false;
        }
        return $ready;
    }
}

if (!function_exists('re_maint_area_types')) {
    /** @return list<string> */
    function re_maint_area_types(): array
    {
        return [
            'lobby', 'reception', 'corridor', 'elevator', 'staircase', 'rooftop', 'garden',
            'playground', 'parking', 'storage', 'swimming_pool', 'pump_room', 'electrical_room', 'other',
        ];
    }
}

if (!function_exists('re_maint_location_label')) {
    function re_maint_location_label(
        ?string $buildingName,
        string $locationType,
        ?string $unitNumber = null,
        ?string $areaName = null
    ): string {
        $b = trim((string)$buildingName);
        $prefix = $b !== '' ? $b : 'Building';
        $type = strtolower(trim($locationType));
        if ($type === 'common_area') {
            $a = trim((string)$areaName);
            return $a !== '' ? ($prefix . ' — ' . $a) : ($prefix . ' — Common Area');
        }
        if ($type === 'building') {
            return $prefix . ' — Building (location to assign)';
        }
        $u = trim((string)$unitNumber);
        return $u !== '' ? ($prefix . ' — Unit ' . $u) : ($prefix . ' — Unit');
    }
}

/**
 * Validate location for NEW create/update (unit or common_area only).
 * Legacy location_type=building is not accepted for new writes.
 *
 * @return array{ok:bool,error?:string,location_type?:string,building_id?:int,unit_id?:?int,common_area_id?:?int}
 */
if (!function_exists('re_maint_validate_location')) {
    function re_maint_validate_location(
        PDO $conn,
        int $companyId,
        string $locationType,
        int $buildingId,
        int $unitId,
        int $commonAreaId,
        bool $allowLegacyBuilding = false
    ): array {
        if ($companyId <= 0) {
            return ['ok' => false, 'error' => 'Company context is required.'];
        }
        $type = strtolower(trim($locationType));
        if (!in_array($type, ['unit', 'common_area', 'building'], true)) {
            return ['ok' => false, 'error' => 'Invalid location type.'];
        }
        if ($type === 'building' && !$allowLegacyBuilding) {
            return ['ok' => false, 'error' => 'Select a Unit or Common Area (building-only location is legacy).'];
        }
        if ($buildingId <= 0) {
            return ['ok' => false, 'error' => 'Building is required.'];
        }
        $bst = $conn->prepare('SELECT id, name FROM re_buildings WHERE id = ? AND company_id = ? LIMIT 1');
        $bst->execute([$buildingId, $companyId]);
        $building = $bst->fetch(PDO::FETCH_ASSOC);
        if (!$building) {
            return ['ok' => false, 'error' => 'Building not found for this company.'];
        }

        if ($type === 'unit') {
            if ($unitId <= 0) {
                return ['ok' => false, 'error' => 'Unit is required.'];
            }
            $ust = $conn->prepare('SELECT id, unit_number FROM re_units WHERE id = ? AND company_id = ? AND building_id = ? LIMIT 1');
            $ust->execute([$unitId, $companyId, $buildingId]);
            if (!$ust->fetch(PDO::FETCH_ASSOC)) {
                return ['ok' => false, 'error' => 'Unit does not belong to the selected building.'];
            }
            return [
                'ok' => true,
                'location_type' => 'unit',
                'building_id' => $buildingId,
                'unit_id' => $unitId,
                'common_area_id' => null,
            ];
        }

        if ($type === 'common_area') {
            if ($commonAreaId <= 0) {
                return ['ok' => false, 'error' => 'Common area is required.'];
            }
            $cst = $conn->prepare("
                SELECT ca.id, ca.area_name
                FROM re_building_common_areas ca
                JOIN re_buildings b ON b.id = ca.building_id AND b.company_id = ?
                WHERE ca.id = ? AND ca.building_id = ? AND ca.is_active = 1
                LIMIT 1
            ");
            $cst->execute([$companyId, $commonAreaId, $buildingId]);
            if (!$cst->fetch(PDO::FETCH_ASSOC)) {
                return ['ok' => false, 'error' => 'Common area not found, inactive, or not in this building.'];
            }
            return [
                'ok' => true,
                'location_type' => 'common_area',
                'building_id' => $buildingId,
                'unit_id' => null,
                'common_area_id' => $commonAreaId,
            ];
        }

        // Legacy building-only (edit of existing WO without changing location type)
        return [
            'ok' => true,
            'location_type' => 'building',
            'building_id' => $buildingId,
            'unit_id' => null,
            'common_area_id' => null,
        ];
    }
}

if (!function_exists('re_maint_load_buildings')) {
    /** @return list<array{id:int,name:string}> */
    function re_maint_load_buildings(PDO $conn, int $companyId): array
    {
        $st = $conn->prepare('SELECT id, name FROM re_buildings WHERE company_id = ? ORDER BY name');
        $st->execute([$companyId]);
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
}

if (!function_exists('re_maint_load_units_for_building')) {
    /** @return list<array{id:int,unit_number:string}> */
    function re_maint_load_units_for_building(PDO $conn, int $companyId, int $buildingId): array
    {
        $st = $conn->prepare('SELECT id, unit_number FROM re_units WHERE company_id = ? AND building_id = ? ORDER BY unit_number');
        $st->execute([$companyId, $buildingId]);
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
}

if (!function_exists('re_maint_load_common_areas_for_building')) {
    /**
     * @return list<array<string,mixed>>
     */
    function re_maint_load_common_areas_for_building(PDO $conn, int $companyId, int $buildingId, bool $activeOnly = true): array
    {
        $sql = "
            SELECT ca.id, ca.area_name, ca.area_type, ca.floor_number, ca.is_active
            FROM re_building_common_areas ca
            JOIN re_buildings b ON b.id = ca.building_id AND b.company_id = ?
            WHERE ca.building_id = ?
        ";
        if ($activeOnly) {
            $sql .= ' AND ca.is_active = 1';
        }
        $sql .= ' ORDER BY ca.area_name';
        $st = $conn->prepare($sql);
        $st->execute([$companyId, $buildingId]);
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
}
