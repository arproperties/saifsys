<?php
/**
 * Per-company inventory preferences (default POS location, etc.).
 */

/**
 * @return array{company_id?:int, default_pos_location_id?:int|null}
 */
function inv_get_company_settings(PDO $conn, int $companyId): array {
    if ($companyId <= 0) {
        return ['company_id' => $companyId, 'default_pos_location_id' => null];
    }
    $st = $conn->prepare("SELECT company_id, default_pos_location_id FROM inv_company_settings WHERE company_id = ? LIMIT 1");
    $st->execute([$companyId]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    if (!$r) {
        return ['company_id' => $companyId, 'default_pos_location_id' => null];
    }
    return $r;
}

/**
 * @throws InvalidArgumentException if location is not valid for this company
 */
function inv_save_default_pos_location(PDO $conn, int $companyId, ?int $locationId): void {
    if ($companyId <= 0) {
        throw new InvalidArgumentException('Invalid company');
    }
    if ($locationId !== null && $locationId > 0) {
        $v = $conn->prepare("SELECT id FROM inv_locations WHERE id = ? AND company_id = ? AND is_active = 1 LIMIT 1");
        $v->execute([$locationId, $companyId]);
        if (!$v->fetchColumn()) {
            throw new InvalidArgumentException('Location not found or inactive for this company.');
        }
    } else {
        $locationId = null;
    }
    $st = $conn->prepare("
        INSERT INTO inv_company_settings (company_id, default_pos_location_id)
        VALUES (?,?)
        ON DUPLICATE KEY UPDATE default_pos_location_id = VALUES(default_pos_location_id)
    ");
    $st->execute([$companyId, $locationId]);
}
