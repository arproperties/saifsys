<?php
/**
 * Lease numbers: BuildingShort-Unit-NNNN. Unique key is on lease_number (company-wide),
 * so the next suffix must exceed any existing row with the same prefix — not only COUNT(unit_id),
 * otherwise a terminated lease or a row tied to another unit_id but the same display number can collide.
 */

if (!function_exists('lease_number_building_short')) {
    function lease_number_building_short(string $buildingName, int $buildingId): string {
        $buildingShort = preg_replace('/[^A-Za-z0-9]/', '', $buildingName);
        $buildingShort = strtoupper(substr($buildingShort, 0, 8));
        if ($buildingShort === '') {
            $buildingShort = 'B' . $buildingId;
        }
        return $buildingShort;
    }
}

if (!function_exists('lease_next_sequence_for_unit')) {
    /**
     * Next numeric suffix (1-based) for BuildingShort-UnitNumber-NNNN within company.
     */
    function lease_next_sequence_for_unit(PDO $conn, int $companyId, int $unitId): ?int {
        $stmt = $conn->prepare("
            SELECT u.unit_number, u.building_id, b.name AS building_name
            FROM re_units u
            JOIN re_buildings b ON b.id = u.building_id
            WHERE u.id = ? AND u.company_id = ?
            LIMIT 1
        ");
        $stmt->execute([$unitId, $companyId]);
        $unit = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$unit) {
            return null;
        }
        $buildingShort = lease_number_building_short((string)($unit['building_name'] ?? ''), (int)($unit['building_id'] ?? 0));
        $unitNum = (string)($unit['unit_number'] ?? '');
        $re = '^' . preg_quote($buildingShort, '/') . '-' . preg_quote($unitNum, '/') . '-[0-9]{1,6}$';
        $stmt = $conn->prepare("
            SELECT lease_number
            FROM re_leases
            WHERE company_id = ?
              AND (unit_id = ? OR lease_number REGEXP ?)
        ");
        $stmt->execute([$companyId, $unitId, $re]);
        $prefix = $buildingShort . '-' . $unitNum . '-';
        $max = 0;
        $rx = '/^' . preg_quote($prefix, '/') . '(\d{1,6})$/';
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $ln = (string)($row['lease_number'] ?? '');
            if (preg_match($rx, $ln, $m)) {
                $max = max($max, (int)$m[1]);
            }
        }
        return $max + 1;
    }
}

if (!function_exists('lease_generate_number_for_unit')) {
    /**
     * Full next lease number for unit, or null if unit not found.
     */
    function lease_generate_number_for_unit(PDO $conn, int $companyId, int $unitId): ?string {
        $stmt = $conn->prepare("
            SELECT u.unit_number, u.building_id, b.name AS building_name
            FROM re_units u
            JOIN re_buildings b ON b.id = u.building_id
            WHERE u.id = ? AND u.company_id = ?
            LIMIT 1
        ");
        $stmt->execute([$unitId, $companyId]);
        $unit = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$unit) {
            return null;
        }
        $buildingShort = lease_number_building_short((string)($unit['building_name'] ?? ''), (int)($unit['building_id'] ?? 0));
        $unitNum = (string)($unit['unit_number'] ?? '');
        $next = lease_next_sequence_for_unit($conn, $companyId, $unitId);
        if ($next === null) {
            return null;
        }
        return $buildingShort . '-' . $unitNum . '-' . str_pad((string)$next, 4, '0', STR_PAD_LEFT);
    }
}
