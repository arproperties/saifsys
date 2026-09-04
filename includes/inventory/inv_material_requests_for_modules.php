<?php
/**
 * Material requests: lists and detail for business modules (non-Inventory UI).
 */

require_once __DIR__ . '/inv_request_create_helpers.php';
require_once __DIR__ . '/../permissions.php';

/**
 * Whether DB source_module value matches the module page (realestate ↔ real_estate).
 */
function inv_material_request_source_matches_module(string $sourceInDb, string $moduleExpected): bool {
    $s = strtolower(str_replace('_', '', trim($sourceInDb)));
    $m = strtolower(str_replace('_', '', trim($moduleExpected)));
    return $s === $m;
}

function inv_material_request_can_view_as_requester(PDO $conn, array $req, int $userId): bool {
    if ($userId <= 0) {
        return false;
    }
    if ((int)($req['requested_by'] ?? 0) === $userId) {
        return true;
    }
    if (has_permission('inventory_requests.view', MODULE_INVENTORY, $conn)) {
        return true;
    }
    $roles = current_user_roles($conn);
    return in_array('Owner', $roles, true) || in_array('Admin', $roles, true);
}

function inv_material_request_can_view_in_module(PDO $conn, array $req, int $userId, string $expectedModule): bool {
    if (!inv_material_request_can_view_as_requester($conn, $req, $userId)) {
        return false;
    }
    return inv_material_request_source_matches_module((string)($req['source_module'] ?? ''), $expectedModule);
}

/**
 * @return array{req: array, lines: array, labels: array<string, string|null>, titles: array<string, string>, location_label: string}|null
 */
function inv_material_request_load_detail(PDO $conn, int $companyId, int $requestId): ?array {
    $hStmt = $conn->prepare("SELECT r.*, u.username AS requested_username, u.fullname AS requested_fullname
        FROM inv_request_headers r
        LEFT JOIN user u ON u.id = r.requested_by
        WHERE r.id = ? AND r.company_id = ?");
    $hStmt->execute([$requestId, $companyId]);
    $req = $hStmt->fetch(PDO::FETCH_ASSOC);
    if (!$req) {
        return null;
    }

    $lStmt = $conn->prepare("
        SELECT l.*, i.item_code, i.name AS item_name, um.code AS uom_code
        FROM inv_request_lines l
        JOIN inv_items i ON i.id = l.item_id AND i.company_id = l.company_id
        LEFT JOIN inv_uoms um ON um.id = l.uom_id
        WHERE l.header_id = ? AND l.company_id = ?
        ORDER BY l.sort_order ASC, l.id ASC
    ");
    $lStmt->execute([$requestId, $companyId]);
    $lines = $lStmt->fetchAll(PDO::FETCH_ASSOC);

    $ctx = [
        'context_building_id' => !empty($req['context_building_id']) ? (int)$req['context_building_id'] : null,
        'context_unit_id' => !empty($req['context_unit_id']) ? (int)$req['context_unit_id'] : null,
        'context_project_id' => !empty($req['context_project_id']) ? (int)$req['context_project_id'] : null,
        'context_booking_id' => !empty($req['context_booking_id']) ? (int)$req['context_booking_id'] : null,
        'context_work_order_id' => !empty($req['context_work_order_id']) ? (int)$req['context_work_order_id'] : null,
        'context_housekeeping_id' => !empty($req['context_housekeeping_id']) ? (int)$req['context_housekeeping_id'] : null,
        'context_cleaning_job_id' => !empty($req['context_cleaning_job_id']) ? (int)$req['context_cleaning_job_id'] : null,
    ];
    $labels = inv_request_resolve_context_labels($conn, $companyId, $ctx);
    $titles = inv_material_request_context_field_titles();

    $locationLabel = '';
    if (!empty($req['location_from_id'])) {
        $ls = $conn->prepare("SELECT code, name FROM inv_locations WHERE id = ? AND company_id = ? LIMIT 1");
        $ls->execute([(int)$req['location_from_id'], $companyId]);
        $lr = $ls->fetch(PDO::FETCH_ASSOC);
        if ($lr) {
            $locationLabel = $lr['code'] . ' — ' . $lr['name'];
        }
    }

    return [
        'req' => $req,
        'lines' => $lines,
        'labels' => $labels,
        'titles' => $titles,
        'location_label' => $locationLabel,
    ];
}

/** Current user's requests for a given source_module value. */
function inv_material_requests_fetch_for_user(PDO $conn, int $companyId, int $userId, string $sourceModule, int $limit = 200): array {
    $lim = max(1, min(500, $limit));
    $stmt = $conn->prepare("
        SELECT r.*, u.username AS requested_username
        FROM inv_request_headers r
        LEFT JOIN user u ON u.id = r.requested_by
        WHERE r.company_id = ? AND r.requested_by = ? AND LOWER(REPLACE(TRIM(r.source_module), '_', '')) = LOWER(REPLACE(?, '_', ''))
        ORDER BY r.id DESC
        LIMIT {$lim}
    ");
    $stmt->execute([$companyId, $userId, $sourceModule]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function inv_material_request_list_summary_line(PDO $conn, int $companyId, array $r): string {
    $ctx = [
        'context_building_id' => !empty($r['context_building_id']) ? (int)$r['context_building_id'] : null,
        'context_unit_id' => !empty($r['context_unit_id']) ? (int)$r['context_unit_id'] : null,
        'context_project_id' => !empty($r['context_project_id']) ? (int)$r['context_project_id'] : null,
        'context_booking_id' => !empty($r['context_booking_id']) ? (int)$r['context_booking_id'] : null,
        'context_work_order_id' => !empty($r['context_work_order_id']) ? (int)$r['context_work_order_id'] : null,
        'context_housekeeping_id' => !empty($r['context_housekeeping_id']) ? (int)$r['context_housekeeping_id'] : null,
        'context_cleaning_job_id' => !empty($r['context_cleaning_job_id']) ? (int)$r['context_cleaning_job_id'] : null,
    ];
    $labels = inv_request_resolve_context_labels($conn, $companyId, $ctx);
    $parts = array_values(array_filter($labels));
    if (!$parts && (!empty($r['source_table']) || !empty($r['source_id']))) {
        $parts[] = trim(($r['source_table'] ?? '') . ' #' . (int)($r['source_id'] ?? 0));
    }
    return $parts ? implode(' · ', $parts) : '—';
}

function inv_material_requests_fetch_for_maintenance_wo(PDO $conn, int $companyId, int $woId, int $limit = 100): array {
    $lim = max(1, min(200, $limit));
    $stmt = $conn->prepare("
        SELECT r.*, u.username AS requested_username
        FROM inv_request_headers r
        LEFT JOIN user u ON u.id = r.requested_by
        WHERE r.company_id = ?
          AND LOWER(REPLACE(TRIM(r.source_module), '_', '')) IN ('realestate')
          AND (
            r.context_work_order_id = ?
            OR (r.source_table = 're_maintenance_requests' AND r.source_id = ?)
          )
        ORDER BY r.id DESC
        LIMIT {$lim}
    ");
    $stmt->execute([$companyId, $woId, $woId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function inv_material_requests_fetch_for_project(PDO $conn, int $companyId, int $projectId, int $limit = 100): array {
    $lim = max(1, min(200, $limit));
    $stmt = $conn->prepare("
        SELECT r.*, u.username AS requested_username
        FROM inv_request_headers r
        LEFT JOIN user u ON u.id = r.requested_by
        WHERE r.company_id = ?
          AND LOWER(REPLACE(TRIM(r.source_module), '_', '')) = 'construction'
          AND (
            r.context_project_id = ?
            OR (r.source_table = 'co_projects' AND r.source_id = ?)
          )
        ORDER BY r.id DESC
        LIMIT {$lim}
    ");
    $stmt->execute([$companyId, $projectId, $projectId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function inv_material_requests_fetch_for_booking(PDO $conn, int $companyId, int $bookingId, int $limit = 100): array {
    $lim = max(1, min(200, $limit));
    $stmt = $conn->prepare("
        SELECT r.*, u.username AS requested_username
        FROM inv_request_headers r
        LEFT JOIN user u ON u.id = r.requested_by
        WHERE r.company_id = ?
          AND LOWER(REPLACE(TRIM(r.source_module), '_', '')) = 'ars'
          AND (
            r.context_booking_id = ?
            OR (r.source_table = 'ars_bookings' AND r.source_id = ?)
          )
        ORDER BY r.id DESC
        LIMIT {$lim}
    ");
    $stmt->execute([$companyId, $bookingId, $bookingId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function inv_material_requests_fetch_for_cleaning_order(PDO $conn, int $companyId, int $orderId, int $limit = 100): array {
    $lim = max(1, min(200, $limit));
    $stmt = $conn->prepare("
        SELECT r.*, u.username AS requested_username
        FROM inv_request_headers r
        LEFT JOIN user u ON u.id = r.requested_by
        WHERE r.company_id = ?
          AND LOWER(REPLACE(TRIM(r.source_module), '_', '')) = 'cleaning'
          AND (
            r.context_cleaning_job_id = ?
            OR (r.source_table = 'make_order' AND r.source_id = ?)
          )
        ORDER BY r.id DESC
        LIMIT {$lim}
    ");
    $stmt->execute([$companyId, $orderId, $orderId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function inv_material_request_status_badge_class(string $status): string {
    $s = strtolower($status);
    if ($s === 'completed') {
        return 'success';
    }
    if ($s === 'rejected') {
        return 'danger';
    }
    return 'secondary';
}
