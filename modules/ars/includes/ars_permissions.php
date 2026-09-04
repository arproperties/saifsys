<?php
/**
 * ARS Phase 1 — action-level authorization + CSRF helpers for AJAX/JSON.
 */

require_once dirname(__DIR__, 3) . '/includes/rbac_department.php';

/** Actions that require ARS Core (or owner/admin via department helpers). */
function ars_core_actions(): array {
    return [
        'confirm', 'cancel', 'complete',
        'add_charge', 'record_payment', 'mark_link_paid',
        'set_security_deposit', 'receive_deposit', 'refund_deposit', 'settle_deposit',
        'preview_stay_dates', 'apply_stay_dates',
        'approve_lifecycle_request', 'reject_lifecycle_request', 'reapply_lifecycle_request',
        'list_booking_documents', 'send_booking_document',
        'list_attachments', 'upload_attachment', 'set_attachment_category',
        'create_service_invoice', 'create_extension_invoice', 'create_credit_note',
        'create_adjustment_invoice',
    ];
}

/** Actions allowed for Operations (and Core). */
function ars_ops_actions(): array {
    return [
        'checkin', 'checkout',
        'approve_lifecycle_request', 'reject_lifecycle_request', 'reapply_lifecycle_request',
        'list_booking_documents', 'send_booking_document', 'list_attachments', 'upload_attachment',
        'set_attachment_category',
    ];
}

function ars_user_has_core(PDO $conn): bool {
    return has_department_access(MODULE_ARS, DEPT_ARS_CORE, $conn);
}

function ars_user_has_ops(PDO $conn): bool {
    return has_department_access(MODULE_ARS, DEPT_ARS_OPERATIONS, $conn);
}

/**
 * Require permission for a booking AJAX action. Exits with JSON 403 on failure.
 */
function ars_require_booking_action(PDO $conn, string $action): void {
    $core = ars_user_has_core($conn);
    $ops = ars_user_has_ops($conn);

    // Passed arsPageAuth via module access without dept flags (e.g. owner/admin).
    if (!$core && !$ops) {
        return;
    }

    $coreOnly = in_array($action, ars_core_actions(), true)
        && !in_array($action, ars_ops_actions(), true);

    if ($coreOnly && !$core) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Insufficient permission for action: ' . $action]);
        exit;
    }
}

/**
 * Verify CSRF for AJAX/JSON endpoints. Accepts POST _csrf.
 */
function ars_ajax_csrf_verify(): void {
    $token = $_POST['_csrf'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    $ok = is_string($token) && $token !== '' && hash_equals($_SESSION['_csrf'] ?? '', $token);
    if (!$ok) {
        http_response_code(419);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'CSRF token invalid or missing.']);
        exit;
    }
}

/**
 * Short-term units query fragment. Bookings remain ARS company-scoped.
 * Units may live under RE company_id (shared inventory) — Needs business confirmation for stricter filter.
 *
 * @return array{0:string,1:array} SQL fragment after WHERE and params
 */
function ars_short_term_units_where(int $arsCompanyId, string $alias = 'u'): array {
    // Prefer units owned by ARS company when present; always require short_term/both.
    // Also include units already used by this ARS company's bookings (shared RE inventory).
    $sql = "{$alias}.rental_mode IN ('short_term','both')
            AND (
                {$alias}.company_id = ?
                OR {$alias}.id IN (SELECT DISTINCT unit_id FROM ars_bookings WHERE company_id = ?)
                OR NOT EXISTS (SELECT 1 FROM re_units ux WHERE ux.company_id = ? AND ux.rental_mode IN ('short_term','both') LIMIT 1)
            )";
    return [$sql, [$arsCompanyId, $arsCompanyId, $arsCompanyId]];
}

function ars_assert_unit_usable_for_ars(PDO $conn, int $unitId, int $arsCompanyId): void {
    [$where, $params] = ars_short_term_units_where($arsCompanyId, 'u');
    $stmt = $conn->prepare("SELECT u.id FROM re_units u WHERE u.id = ? AND {$where} LIMIT 1");
    $stmt->execute(array_merge([$unitId], $params));
    if (!$stmt->fetchColumn()) {
        throw new RuntimeException('Unit is not available for ARS short-term use.');
    }
}

/**
 * @return list<array<string,mixed>>
 */
function ars_fetch_short_term_units(PDO $conn, int $arsCompanyId): array {
    [$where, $params] = ars_short_term_units_where($arsCompanyId, 'u');
    $stmt = $conn->prepare("
        SELECT u.id, u.unit_number, u.listing_title, u.nightly_rate, COALESCE(u.monthly_rate, 0) AS monthly_rate,
               u.max_guests, b.name AS building_name
        FROM re_units u
        LEFT JOIN re_buildings b ON b.id = u.building_id
        WHERE {$where}
        ORDER BY b.name, u.unit_number
    ");
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}
