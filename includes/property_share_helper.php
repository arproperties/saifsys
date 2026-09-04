<?php
/**
 * Property sharing — opaque share codes + resolve eligibility (Find Your Home V1).
 * Extensible via property_type for future Holiday Homes / commercial / sales.
 */

declare(strict_types=1);

const RE_PROPERTY_SHARE_TYPE_LONG_TERM = 'long_term_rental';

function re_property_share_ensure_schema(PDO $conn): void
{
    static $done = false;
    if ($done) {
        return;
    }
    try {
        $conn->exec("
            CREATE TABLE IF NOT EXISTS re_property_share_refs (
              id INT(11) NOT NULL AUTO_INCREMENT,
              company_id INT(11) NOT NULL,
              share_code CHAR(36) NOT NULL,
              property_type VARCHAR(40) NOT NULL DEFAULT 'long_term_rental',
              entity_id INT(11) NOT NULL,
              is_active TINYINT(1) NOT NULL DEFAULT 1,
              created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
              updated_at DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
              PRIMARY KEY (id),
              UNIQUE KEY uq_share_code (share_code),
              UNIQUE KEY uq_company_type_entity (company_id, property_type, entity_id),
              KEY idx_company_active (company_id, is_active),
              KEY idx_entity (property_type, entity_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        $done = true;
    } catch (Throwable $e) {
        error_log('re_property_share_ensure_schema: ' . $e->getMessage());
    }
}

function re_property_share_new_code(): string
{
    if (function_exists('random_bytes')) {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
    return sprintf(
        '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
        mt_rand(0, 0xffff),
        mt_rand(0, 0xffff),
        mt_rand(0, 0xffff),
        mt_rand(0, 0x0fff) | 0x4000,
        mt_rand(0, 0x3fff) | 0x8000,
        mt_rand(0, 0xffff),
        mt_rand(0, 0xffff),
        mt_rand(0, 0xffff)
    );
}

/**
 * Ensure a share_code exists for a published long-term unit. Returns share_code or null.
 */
function re_property_share_ensure_for_unit(PDO $conn, int $companyId, int $unitId): ?string
{
    if ($companyId <= 0 || $unitId <= 0) {
        return null;
    }
    re_property_share_ensure_schema($conn);

    $stmt = $conn->prepare("
        SELECT share_code, is_active
        FROM re_property_share_refs
        WHERE company_id = ?
          AND property_type = ?
          AND entity_id = ?
        LIMIT 1
    ");
    $stmt->execute([$companyId, RE_PROPERTY_SHARE_TYPE_LONG_TERM, $unitId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        if ((int)$row['is_active'] !== 1) {
            $conn->prepare("
                UPDATE re_property_share_refs
                SET is_active = 1, updated_at = NOW()
                WHERE company_id = ? AND property_type = ? AND entity_id = ?
            ")->execute([$companyId, RE_PROPERTY_SHARE_TYPE_LONG_TERM, $unitId]);
        }
        return (string)$row['share_code'];
    }

    for ($attempt = 0; $attempt < 5; $attempt++) {
        $code = re_property_share_new_code();
        try {
            $ins = $conn->prepare("
                INSERT INTO re_property_share_refs
                  (company_id, share_code, property_type, entity_id, is_active, created_at)
                VALUES (?, ?, ?, ?, 1, NOW())
            ");
            $ins->execute([$companyId, $code, RE_PROPERTY_SHARE_TYPE_LONG_TERM, $unitId]);
            return $code;
        } catch (Throwable $e) {
            // Unique collision on share_code — retry.
            error_log('re_property_share_ensure_for_unit retry: ' . $e->getMessage());
        }
    }
    return null;
}

/**
 * Same eligibility as Find Your Home public list/detail (ltr_base_where_sql).
 */
function re_property_share_long_term_eligibility_sql(string $alias = 'u'): string
{
    return "
        {$alias}.publish_to_mobile = 1
        AND {$alias}.rental_mode IN ('long_term', 'both')
        AND {$alias}.status = 'vacant'
        AND NOT EXISTS (
            SELECT 1 FROM re_leases l
            LEFT JOIN re_lease_units lu ON lu.lease_id = l.id
            WHERE l.company_id = {$alias}.company_id
              AND l.status = 'active'
              AND l.deleted_at IS NULL
              AND (l.unit_id = {$alias}.id OR lu.unit_id = {$alias}.id)
            LIMIT 1
        )
    ";
}

/**
 * @return array{available:bool,reason:?string,message:string,property_type:?string,unit_id:?int}
 */
function re_property_share_resolve(PDO $conn, string $shareCode): array
{
    $unavailable = static function (string $reason): array {
        return [
            'available' => false,
            'reason' => $reason,
            'message' => 'This property is no longer available.',
            'property_type' => null,
            'unit_id' => null,
        ];
    };

    require_once __DIR__ . '/app_mobile_config_helper.php';
    $cfg = app_mobile_config_get($conn);
    if (empty($cfg['property_share_enabled'])) {
        return $unavailable('share_disabled');
    }

    $shareCode = trim($shareCode);
    if ($shareCode === '' || !preg_match('/^[A-Za-z0-9\-]{8,64}$/', $shareCode)) {
        return $unavailable('not_found');
    }

    re_property_share_ensure_schema($conn);

    $stmt = $conn->prepare("
        SELECT id, company_id, share_code, property_type, entity_id, is_active
        FROM re_property_share_refs
        WHERE share_code = ?
        LIMIT 1
    ");
    $stmt->execute([$shareCode]);
    $ref = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$ref) {
        return $unavailable('not_found');
    }
    if ((int)$ref['is_active'] !== 1) {
        return $unavailable('inactive');
    }

    $propertyType = (string)$ref['property_type'];
    $entityId = (int)$ref['entity_id'];
    $companyId = (int)$ref['company_id'];

    if ($propertyType !== RE_PROPERTY_SHARE_TYPE_LONG_TERM) {
        // V1 only resolves long-term; other types are not exposed yet.
        return $unavailable('wrong_type');
    }

    $elig = re_property_share_long_term_eligibility_sql('u');
    $unitStmt = $conn->prepare("
        SELECT u.id, u.status, u.publish_to_mobile
        FROM re_units u
        WHERE u.id = ?
          AND u.company_id = ?
          AND {$elig}
        LIMIT 1
    ");
    $unitStmt->execute([$entityId, $companyId]);
    $unit = $unitStmt->fetch(PDO::FETCH_ASSOC);
    if (!$unit) {
        // Distinguish common reasons without returning listing data.
        $raw = $conn->prepare("
            SELECT u.id, u.status, u.publish_to_mobile, u.rental_mode
            FROM re_units u
            WHERE u.id = ? AND u.company_id = ?
            LIMIT 1
        ");
        $raw->execute([$entityId, $companyId]);
        $rawUnit = $raw->fetch(PDO::FETCH_ASSOC);
        if (!$rawUnit) {
            return $unavailable('not_found');
        }
        if ((int)$rawUnit['publish_to_mobile'] !== 1) {
            return $unavailable('unpublished');
        }
        if ((string)$rawUnit['status'] !== 'vacant') {
            return $unavailable('occupied');
        }
        return $unavailable('unavailable');
    }

    return [
        'available' => true,
        'reason' => null,
        'message' => '',
        'property_type' => $propertyType,
        'unit_id' => (int)$unit['id'],
    ];
}

/**
 * Build public share URL from ERP config (no trailing slash on base).
 */
function re_property_share_build_url(PDO $conn, string $shareCode): ?string
{
    require_once __DIR__ . '/app_mobile_config_helper.php';
    $cfg = app_mobile_config_get($conn);
    if (empty($cfg['property_share_enabled'])) {
        return null;
    }
    $base = trim((string)($cfg['share_base_url'] ?? ''));
    if ($base === '') {
        $base = re_property_share_default_base_url();
    }
    $base = rtrim($base, '/');
    if ($base === '') {
        return null;
    }
    return $base . '/p/' . rawurlencode($shareCode);
}

function re_property_share_default_base_url(): string
{
    if (function_exists('get_base_path') === false) {
        $helper = __DIR__ . '/url_helper.php';
        if (is_file($helper)) {
            require_once $helper;
        }
    }
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || ((int)($_SERVER['SERVER_PORT'] ?? 0) === 443);
    $scheme = $https ? 'https' : 'http';
    $host = trim((string)($_SERVER['HTTP_HOST'] ?? ''));
    if ($host === '') {
        return '';
    }
    $basePath = '';
    if (function_exists('get_base_path')) {
        $basePath = rtrim((string)get_base_path(), '/');
    }
    return $scheme . '://' . $host . $basePath;
}

/**
 * Professional share message from ERP template + listing fields.
 *
 * @param array{title?:string,building?:string,rent?:string,location?:string,url:string} $parts
 */
function re_property_share_format_message(PDO $conn, array $parts): string
{
    require_once __DIR__ . '/app_mobile_config_helper.php';
    $cfg = app_mobile_config_get($conn);
    $template = trim((string)($cfg['share_message_template'] ?? ''));
    if ($template === '') {
        $template = "Check out this property:\n{title}\n{building} · {location}\n{rent}\n\n{url}";
    }
    $map = [
        '{title}' => (string)($parts['title'] ?? ''),
        '{building}' => (string)($parts['building'] ?? ''),
        '{rent}' => (string)($parts['rent'] ?? ''),
        '{location}' => (string)($parts['location'] ?? ''),
        '{url}' => (string)($parts['url'] ?? ''),
    ];
    return trim(strtr($template, $map));
}

/**
 * Share metadata for an eligible published unit (detail API). No listing duplication.
 *
 * @return array{share_code:?string,share_url:?string,share_enabled:bool}|null
 */
function re_property_share_public_meta_for_unit(PDO $conn, int $companyId, int $unitId): array
{
    require_once __DIR__ . '/app_mobile_config_helper.php';
    $cfg = app_mobile_config_get($conn);
    if (empty($cfg['property_share_enabled'])) {
        return [
            'share_enabled' => false,
            'share_code' => null,
            'share_url' => null,
        ];
    }
    $code = re_property_share_ensure_for_unit($conn, $companyId, $unitId);
    if ($code === null) {
        return [
            'share_enabled' => true,
            'share_code' => null,
            'share_url' => null,
        ];
    }
    return [
        'share_enabled' => true,
        'share_code' => $code,
        'share_url' => re_property_share_build_url($conn, $code),
    ];
}
