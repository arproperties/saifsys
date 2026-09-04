<?php
/**
 * Retail POS profiles: one default selling location per URL code (multi-store registers).
 */

function inv_pos_profile_normalize_code(string $raw): string {
    $s = strtolower(preg_replace('/[^a-z0-9_-]+/i', '', $raw));
    if (strlen($s) > 32) {
        $s = substr($s, 0, 32);
    }
    return $s;
}

/**
 * @return list<array<string,mixed>>
 */
function inv_pos_profiles_list(PDO $conn, int $companyId): array {
    if ($companyId <= 0) {
        return [];
    }
    $st = $conn->prepare("
        SELECT p.id, p.company_id, p.profile_code, p.label, p.default_location_id, p.sort_order, p.is_active,
               l.name AS location_name, l.code AS location_code
        FROM inv_pos_retail_profiles p
        INNER JOIN inv_locations l ON l.id = p.default_location_id AND l.company_id = p.company_id
        WHERE p.company_id = ?
        ORDER BY p.sort_order ASC, p.label ASC, p.profile_code ASC
    ");
    $st->execute([$companyId]);
    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/**
 * @return array<string,mixed>|null
 */
function inv_pos_profile_get_by_code(PDO $conn, int $companyId, string $codeRaw): ?array {
    $code = inv_pos_profile_normalize_code($codeRaw);
    if ($code === '' || $companyId <= 0) {
        return null;
    }
    $st = $conn->prepare("
        SELECT p.id, p.company_id, p.profile_code, p.label, p.default_location_id, p.sort_order, p.is_active
        FROM inv_pos_retail_profiles p
        WHERE p.company_id = ? AND p.profile_code = ? AND p.is_active = 1
        LIMIT 1
    ");
    $st->execute([$companyId, $code]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return null;
    }
    $locId = (int)$row['default_location_id'];
    $v = $conn->prepare("SELECT id, name FROM inv_locations WHERE id = ? AND company_id = ? AND is_active = 1 LIMIT 1");
    $v->execute([$locId, $companyId]);
    $loc = $v->fetch(PDO::FETCH_ASSOC);
    if (!$loc) {
        return null;
    }
    $row['location_name'] = (string)$loc['name'];
    return $row;
}

function inv_pos_profile_create(PDO $conn, int $companyId, string $label, string $codeRaw, int $locationId): void {
    if ($companyId <= 0) {
        throw new InvalidArgumentException('Invalid company');
    }
    $label = trim($label);
    if ($label === '') {
        throw new InvalidArgumentException('Profile name is required.');
    }
    $code = inv_pos_profile_normalize_code($codeRaw);
    if ($code === '') {
        throw new InvalidArgumentException('URL code must contain letters or numbers (e.g. store1, st-2).');
    }
    if ($locationId <= 0) {
        throw new InvalidArgumentException('Choose a default location.');
    }
    $v = $conn->prepare("SELECT id FROM inv_locations WHERE id = ? AND company_id = ? AND is_active = 1 LIMIT 1");
    $v->execute([$locationId, $companyId]);
    if (!$v->fetchColumn()) {
        throw new InvalidArgumentException('Location not found or inactive for this company.');
    }
    try {
        $st = $conn->prepare("
            INSERT INTO inv_pos_retail_profiles (company_id, profile_code, label, default_location_id, sort_order, is_active)
            VALUES (?,?,?,?,0,1)
        ");
        $st->execute([$companyId, $code, $label, $locationId]);
    } catch (PDOException $e) {
        $dup = isset($e->errorInfo[1]) ? (int)$e->errorInfo[1] : 0;
        if ($dup === 1062) {
            throw new InvalidArgumentException('That URL code is already used for another profile.');
        }
        throw $e;
    }
}

function inv_pos_profile_delete(PDO $conn, int $companyId, int $profileId): void {
    if ($companyId <= 0 || $profileId <= 0) {
        throw new InvalidArgumentException('Invalid profile.');
    }
    $st = $conn->prepare("DELETE FROM inv_pos_retail_profiles WHERE id = ? AND company_id = ?");
    $st->execute([$profileId, $companyId]);
    if ($st->rowCount() < 1) {
        throw new InvalidArgumentException('Profile not found.');
    }
}
