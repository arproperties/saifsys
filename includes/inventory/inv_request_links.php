<?php
/**
 * Phase 3 — Deep links + access for material request creation from business modules.
 */

require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../module_access.php';

/**
 * Parse allowed query keys for request_create.php prefill.
 *
 * @return array<string,mixed>
 */
function inv_request_parse_prefill_from_get(): array {
    $out = [];
    if (!empty($_GET['source_module'])) {
        $out['source_module'] = strtolower(trim((string)$_GET['source_module']));
    }
    if (isset($_GET['source_table']) && $_GET['source_table'] !== '') {
        $out['source_table'] = trim((string)$_GET['source_table']);
    }
    if (isset($_GET['source_id']) && $_GET['source_id'] !== '') {
        $out['source_id'] = (int)$_GET['source_id'];
    }
    foreach ([
        'context_building_id',
        'context_unit_id',
        'context_project_id',
        'context_booking_id',
        'context_work_order_id',
        'context_housekeeping_id',
        'context_cleaning_job_id',
    ] as $k) {
        if (isset($_GET[$k]) && $_GET[$k] !== '') {
            $out[$k] = (int)$_GET[$k];
        }
    }
    if (!empty($_GET['notes_hint'])) {
        $out['notes_hint'] = trim((string)$_GET['notes_hint']);
    }
    if (!empty($_GET['request_date'])) {
        $out['request_date'] = trim((string)$_GET['request_date']);
    }
    return $out;
}

/**
 * Build URL to Inventory → New material request with pre-filled context.
 *
 * @param array<string,mixed> $opts source_module, source_table, source_id, context_* optional, notes_hint optional
 */
function inv_request_material_create_url(PDO $conn, array $opts): string {
    require_once dirname(__DIR__) . '/url_helper.php';
    $root = get_application_web_root();
    $sm = strtolower(trim((string)($opts['source_module'] ?? 'inventory')));
    $paths = [
        'realestate' => '/modules/realestate/material_request_create.php',
        'real_estate' => '/modules/realestate/material_request_create.php',
        'construction' => '/modules/construction/material_request_create.php',
        'ars' => '/modules/ars/material_request_create.php',
        'cleaning' => '/operation/material_request_create.php',
        'inventory' => '/modules/inventory/request_create.php',
    ];
    $base = ($root !== '' ? $root : '') . ($paths[$sm] ?? $paths['inventory']);
    $q = [];
    if (!empty($opts['source_module'])) {
        $q['source_module'] = strtolower(trim((string)$opts['source_module']));
    }
    if (!empty($opts['source_table'])) {
        $q['source_table'] = trim((string)$opts['source_table']);
    }
    if (isset($opts['source_id']) && (int)$opts['source_id'] > 0) {
        $q['source_id'] = (int)$opts['source_id'];
    }
    foreach ([
        'context_building_id',
        'context_unit_id',
        'context_project_id',
        'context_booking_id',
        'context_work_order_id',
        'context_housekeeping_id',
        'context_cleaning_job_id',
    ] as $k) {
        if (!empty($opts[$k])) {
            $q[$k] = (int)$opts[$k];
        }
    }
    if (!empty($opts['notes_hint'])) {
        $q['notes_hint'] = substr((string)$opts['notes_hint'], 0, 500);
    }
    if (!empty($opts['request_date'])) {
        $q['request_date'] = (string)$opts['request_date'];
    }
    return $base . ($q ? ('?' . http_build_query($q)) : '');
}

/**
 * True if user may open material request create: inventory_requests.create plus
 * Inventory module access, or Owner/Admin, or access to the hinted business module.
 */
function inv_user_can_access_material_request_create(PDO $conn, ?string $sourceModuleHint): bool {
    require_once dirname(__DIR__) . '/permissions.php';
    if (!has_permission('inventory_requests.create', MODULE_INVENTORY, $conn)) {
        return false;
    }
    $uid = current_user_id();
    if (!$uid) {
        return false;
    }
    $roles = current_user_roles($conn);
    if (in_array('Owner', $roles, true) || in_array('Admin', $roles, true)) {
        return true;
    }
    if (user_has_module_access($conn, $uid, MODULE_INVENTORY)) {
        return true;
    }
    $sm = strtolower(trim((string)$sourceModuleHint));
    $map = [
        'cleaning' => MODULE_CLEANING,
        'realestate' => MODULE_REALESTATE,
        'real_estate' => MODULE_REALESTATE,
        'construction' => MODULE_CONSTRUCTION,
        'ars' => MODULE_ARS,
        'inventory' => MODULE_INVENTORY,
    ];
    if (isset($map[$sm])) {
        return user_has_module_access($conn, $uid, $map[$sm]);
    }
    return false;
}

/**
 * Enforce access for request_create.php (replaces strict inventory-only gate when appropriate).
 */
function inv_require_material_request_create_page(PDO $conn): void {
    require_once dirname(__DIR__) . '/permissions.php';
    require_once dirname(__DIR__) . '/rbac_department.php';
    require_permission('inventory_requests.create', MODULE_INVENTORY, $conn);

    $prefill = inv_request_parse_prefill_from_get();
    $hint = $prefill['source_module'] ?? null;
    if ($hint === null || $hint === '') {
        if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && !empty($_POST['source_module'])) {
            $hint = strtolower(trim((string)$_POST['source_module']));
        }
    }

    if (inv_user_can_access_material_request_create($conn, $hint)) {
        if (user_has_module_access($conn, (int)current_user_id(), MODULE_INVENTORY)) {
            require_department_access(MODULE_INVENTORY, DEPT_INVENTORY, $conn);
        }
        return;
    }

    http_response_code(403);
    echo '<div style="font-family:system-ui;padding:32px">
            <h3>403 – Forbidden</h3>
            <p>You do not have access to create material requests for this context.</p>
            <p><a href="javascript:history.back()">Go Back</a></p>
          </div>';
    exit;
}
