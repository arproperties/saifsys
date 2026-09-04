<?php
/**
 * Phase D-light — tenant auth, lease scope (X-Tenant-Lease-Id), dashboard, payments, invoices.
 * Financial totals/status come from lease_financial_summary_service.php (Phase 2).
 * Requires includes/customer_api.php and db $conn.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../../modules/realestate/includes/lease_financial_summary_service.php';

/**
 * Safe list limit from query string. Defaults preserve Phase 2/3 Flutter consumption.
 * Clients may pass ?limit=; values are clamped to [1, $max].
 */
function customer_api_tenant_query_limit(int $default, int $max = 200): int {
    $raw = $_GET['limit'] ?? null;
    if ($raw === null || $raw === '') {
        return max(1, min($default, $max));
    }
    $n = (int)$raw;
    if ($n < 1) {
        return max(1, min($default, $max));
    }
    return max(1, min($n, $max));
}

/**
 * Optional offset for list endpoints (default 0). Clamped to avoid abuse.
 */
function customer_api_tenant_query_offset(int $max = 10000): int {
    $raw = $_GET['offset'] ?? null;
    if ($raw === null || $raw === '') {
        return 0;
    }
    $n = (int)$raw;
    if ($n < 0) {
        return 0;
    }
    return min($n, $max);
}

/**
 * Build dashboard/compat financial block from canonical summary + schedule helpers.
 * Temporary aliases (outstanding_rent, etc.) are populated from the service — not Legacy SQL.
 *
 * @param array<string,mixed> $summary from re_lease_financial_summary()
 * @return array<string,mixed>
 */
function customer_api_tenant_financial_payload_for_dashboard(PDO $conn, int $companyId, int $leaseId, array $summary): array {
    // Next Cheque = next unsettled operational schedule row (allocations + cleared cheque lifecycle).
    // Fully settled when remaining <= 0.01 AED. Partial remaining is returned as amount.
    // Bounced/returned unsettled rows are prioritized over pending due-date order.
    $pick = re_lease_next_unsettled_installment($conn, $companyId, $leaseId);
    $nextInst = null;
    $nextRow = $pick['next'] ?? null;
    if (is_array($nextRow)) {
        $nextInst = [
            'installment_date' => (string)($nextRow['installment_date'] ?? ''),
            // Tenant-facing: remaining balance on a partially settled cheque; full amount otherwise.
            'amount' => (string)($nextRow['remaining_amount'] ?? $nextRow['amount'] ?? '0.00'),
            'status' => (string)(($nextRow['cheque_status'] ?? '') !== ''
                ? $nextRow['cheque_status']
                : ($nextRow['display_status'] ?? $nextRow['status'] ?? '')),
            'sequence' => (int)($nextRow['sequence'] ?? 0),
            'installment_id' => (int)($nextRow['id'] ?? 0),
            'cheque_number' => $nextRow['cheque_number'] ?? null,
            'settlement_source' => (string)($nextRow['settlement_source'] ?? ''),
        ];
    }

    // Only load SC rows when summary shows SC outstanding (same next_sc semantics).
    $nextSc = null;
    if ((float)($summary['service_charge_outstanding'] ?? 0) > 0.005) {
        $sc = re_lease_service_charge_rows($conn, $companyId, $leaseId);
        foreach ($sc['service_charges'] ?? [] as $row) {
            if ((float)($row['outstanding_amount'] ?? 0) > 0.005) {
                $nextSc = [
                    'due_date' => (string)($row['due_date'] ?? ''),
                    'service_name' => (string)(($row['service_name'] ?? '') ?: ($row['item_name'] ?? 'Service charge')),
                    'amount' => (string)($row['outstanding_amount'] ?? '0.00'),
                    'status' => (string)($row['display_status'] ?? $row['status'] ?? ''),
                ];
                break;
            }
        }
    }

    // Canonical fields first; Flutter Phase-2 compat aliases from the same service values.
    return array_merge($summary, [
        'outstanding_rent' => (string)($summary['rent_outstanding'] ?? '0.00'),
        'outstanding_penalties' => (string)($summary['penalty_outstanding'] ?? '0.00'),
        'outstanding_service_charges' => (string)($summary['service_charge_outstanding'] ?? '0.00'),
        'next_installment' => $nextInst,
        'next_service_charge' => $nextSc,
        'next_due' => $summary['next_due'] ?? null,
    ]);
}

function customer_api_tenant_lease_header_value(): ?int {
    $raw = trim((string)(
        $_SERVER['HTTP_X_TENANT_LEASE_ID']
        ?? $_SERVER['REDIRECT_HTTP_X_TENANT_LEASE_ID']
        ?? ''
    ));
    if ($raw === '' || !ctype_digit($raw)) {
        return null;
    }
    return (int)$raw;
}

/**
 * @return array<string,mixed>
 */
function customer_api_tenant_require_access(PDO $conn): array {
    $token = customer_api_get_bearer();
    if ($token === null || $token === '') {
        customer_api_send_error('unauthorized', 'Authorization Bearer token required', 401);
    }
    $pl = customer_api_jwt_verify($token);
    if (!$pl || ($pl['typ'] ?? '') !== 'tenant') {
        customer_api_send_error('invalid_token', 'Invalid or expired access token', 401);
    }
    $sub = (string)($pl['sub'] ?? '');
    if (preg_match('/^tpu:(\d+)$/', $sub, $m)) {
        $tpuId = (int)$m[1];
        $stmt = $conn->prepare("
            SELECT id, tenant_id, company_id, email, display_name, status
            FROM tenant_portal_users
            WHERE id = ? AND status = 'approved'
            LIMIT 1
        ");
        $stmt->execute([$tpuId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            customer_api_send_error('unauthorized', 'Account not found or inactive', 401);
        }
        return [
            'mode' => 'tpu',
            'tenant_portal_user_id' => (int)$row['id'],
            'user_id' => null,
            'tenant_id' => (int)$row['tenant_id'],
            'company_id' => (int)$row['company_id'],
            'display_name' => (string)($row['display_name'] ?? $row['email'] ?? ''),
            'email' => (string)($row['email'] ?? ''),
        ];
    }
    if (preg_match('/^usr:(\d+)$/', $sub, $m)) {
        $userId = (int)$m[1];
        $stmt = $conn->prepare("
            SELECT 1 FROM tenant_portal_accounts
            WHERE user_id = ? AND status = 'approved'
            LIMIT 1
        ");
        $stmt->execute([$userId]);
        if (!$stmt->fetchColumn()) {
            customer_api_send_error('unauthorized', 'Account not found or inactive', 401);
        }
        $acc = $conn->prepare("
            SELECT tpa.company_id, l.tenant_id
            FROM tenant_portal_accounts tpa
            INNER JOIN re_leases l ON l.id = tpa.lease_id
            WHERE tpa.user_id = ? AND tpa.status = 'approved'
            ORDER BY tpa.lease_id
            LIMIT 1
        ");
        $acc->execute([$userId]);
        $first = $acc->fetch(PDO::FETCH_ASSOC);
        if (!$first) {
            customer_api_send_error('unauthorized', 'No lease linked to this account', 401);
        }
        $uStmt = $conn->prepare('SELECT username, fullname, email FROM user WHERE id = ? LIMIT 1');
        $uStmt->execute([$userId]);
        $u = $uStmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $name = (string)($u['fullname'] ?? $u['username'] ?? 'Tenant');

        return [
            'mode' => 'usr',
            'tenant_portal_user_id' => null,
            'user_id' => $userId,
            'tenant_id' => (int)$first['tenant_id'],
            'company_id' => (int)$first['company_id'],
            'display_name' => $name,
            'email' => (string)($u['email'] ?? $u['username'] ?? ''),
        ];
    }
    customer_api_send_error('invalid_token', 'Invalid access token subject', 401);
}

/**
 * @return list<int>
 */
function customer_api_tenant_allowed_lease_ids(PDO $conn, array $ctx): array {
    if ($ctx['mode'] === 'tpu') {
        $stmt = $conn->prepare("
            SELECT id FROM re_leases
            WHERE tenant_id = ? AND company_id = ?
            ORDER BY lease_number
        ");
        $stmt->execute([(int)$ctx['tenant_id'], (int)$ctx['company_id']]);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }
    $stmt = $conn->prepare("
        SELECT lease_id FROM tenant_portal_accounts
        WHERE user_id = ? AND status = 'approved'
        ORDER BY lease_id
    ");
    $stmt->execute([(int)$ctx['user_id']]);
    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

/**
 * @param list<int> $leaseIds
 * @return list<array<string,mixed>>
 */
function customer_api_tenant_leases_summary(PDO $conn, array $leaseIds): array {
    if ($leaseIds === []) {
        return [];
    }
    $ph = implode(',', array_fill(0, count($leaseIds), '?'));
    $stmt = $conn->prepare("
        SELECT l.id AS lease_id, l.lease_number, l.start_date, l.end_date, l.status,
               COALESCE(l.accounting_mode, 'legacy') AS accounting_mode,
               u.unit_number, b.name AS building_name,
               CASE
                   WHEN l.end_date >= CURDATE() THEN DATEDIFF(l.end_date, CURDATE())
                   ELSE -1
               END AS days_remaining,
               CASE
                   WHEN l.status IN ('active', 'renewed') AND l.end_date >= CURDATE() THEN 1
                   ELSE 0
               END AS is_current
        FROM re_leases l
        JOIN re_units u ON u.id = l.unit_id
        JOIN re_buildings b ON b.id = u.building_id
        WHERE l.id IN ($ph)
        ORDER BY is_current DESC, days_remaining DESC, l.start_date DESC, l.id DESC
    ");
    $stmt->execute(array_values($leaseIds));
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $out = [];
    foreach ($rows as $r) {
        $mode = (string)($r['accounting_mode'] ?? 'legacy');
        if (!in_array($mode, ['legacy', 'invoice'], true)) {
            $mode = 'legacy';
        }
        $out[] = [
            'lease_id' => (int)$r['lease_id'],
            'lease_number' => (string)($r['lease_number'] ?? ''),
            'unit_number' => (string)($r['unit_number'] ?? ''),
            'building_name' => (string)($r['building_name'] ?? ''),
            'start_date' => (string)($r['start_date'] ?? ''),
            'end_date' => (string)($r['end_date'] ?? ''),
            'status' => (string)($r['status'] ?? ''),
            'accounting_mode' => $mode,
            'days_remaining' => (int)($r['days_remaining'] ?? -1),
            'is_current' => ((int)($r['is_current'] ?? 0)) === 1,
        ];
    }
    return $out;
}

/**
 * Default lease: current/active with the most days left; tie-break newest start_date.
 *
 * @param list<int> $leaseIds
 */
function customer_api_tenant_recommended_lease_id(PDO $conn, array $leaseIds): ?int {
    $leases = customer_api_tenant_leases_summary($conn, $leaseIds);
    if ($leases === []) {
        return null;
    }
    foreach ($leases as $lease) {
        if (!empty($lease['is_current'])) {
            return (int)$lease['lease_id'];
        }
    }
    return (int)$leases[0]['lease_id'];
}

/**
 * Pick active lease: valid header, else recommended default.
 *
 * @param list<int> $allowed
 */
function customer_api_tenant_active_lease_id(PDO $conn, array $allowed, ?int $headerLeaseId): ?int {
    if ($allowed === []) {
        return null;
    }
    if ($headerLeaseId !== null && in_array($headerLeaseId, $allowed, true)) {
        return $headerLeaseId;
    }
    return customer_api_tenant_recommended_lease_id($conn, $allowed);
}

/**
 * @return array<string,mixed>
 */
function customer_api_tenant_payload_for_response(PDO $conn, array $ctx, ?int $preferLeaseId = null): array {
    $allowed = customer_api_tenant_allowed_lease_ids($conn, $ctx);
    $leases = customer_api_tenant_leases_summary($conn, $allowed);
    $recommended = customer_api_tenant_recommended_lease_id($conn, $allowed);
    $hdr = customer_api_tenant_lease_header_value();
    if ($preferLeaseId !== null && in_array($preferLeaseId, $allowed, true)) {
        $active = $preferLeaseId;
    } elseif ($hdr !== null && in_array($hdr, $allowed, true)) {
        $active = $hdr;
    } else {
        $active = $recommended;
    }

    // Tenant master (Edit Tenant) is source of truth for name + contact.
    // Portal display_name is only a fallback (often set once at registration).
    $email = '';
    $phone = '';
    $displayName = trim((string)($ctx['display_name'] ?? ''));
    $tenantId = (int)($ctx['tenant_id'] ?? 0);
    $companyId = (int)($ctx['company_id'] ?? 0);
    if ($tenantId > 0 && $companyId > 0) {
        $tStmt = $conn->prepare("
            SELECT tenant_type, company_name, first_name, last_name, email, phone
            FROM re_tenants
            WHERE id = ? AND company_id = ?
            LIMIT 1
        ");
        $tStmt->execute([$tenantId, $companyId]);
        $tRow = $tStmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $email = trim((string)($tRow['email'] ?? ''));
        $phone = trim((string)($tRow['phone'] ?? ''));
        $type = strtolower(trim((string)($tRow['tenant_type'] ?? 'individual')));
        if ($type === 'company') {
            $masterName = trim((string)($tRow['company_name'] ?? ''));
        } else {
            $masterName = trim(
                trim((string)($tRow['first_name'] ?? '')) . ' ' . trim((string)($tRow['last_name'] ?? ''))
            );
        }
        if ($masterName !== '') {
            $displayName = $masterName;
        }
    }
    if ($email === '') {
        $email = trim((string)($ctx['email'] ?? ''));
    }
    if ($displayName === '') {
        $displayName = 'Tenant';
    }

    // Company support contacts from Settings → Company Information (company_settings).
    require_once dirname(__DIR__, 3) . '/includes/company_helper.php';
    $companyContact = company_settings_contact_for_company($conn, $companyId);

    return [
        'tenant_portal_user_id' => $ctx['tenant_portal_user_id'],
        'display_name' => $displayName,
        'company_id' => $ctx['company_id'],
        'tenant_id' => $ctx['tenant_id'],
        'email' => $email,
        'phone' => $phone,
        'support' => [
            'office_phone' => $companyContact['phone'],
            'email' => $companyContact['email'],
            'whatsapp' => $companyContact['whatsapp'],
            'company_name' => $companyContact['legal_name'],
        ],
        'leases' => $leases,
        'active_lease_id' => $active,
        'recommended_lease_id' => $recommended,
    ];
}

function customer_api_tenant_verify_legacy_password(string $stored, string $plain): bool {
    if ($stored === '') {
        return false;
    }
    if (str_starts_with($stored, '$2y$') || str_starts_with($stored, '$argon2')) {
        return password_verify($plain, $stored);
    }
    if (preg_match('/^[a-f0-9]{32}$/i', $stored)) {
        return md5($plain) === strtolower($stored);
    }
    return hash_equals($stored, $plain);
}

function customer_api_tenant_handle_login(PDO $conn): void {
    $body = customer_api_read_json_body();
    $email = strtolower(trim((string)($body['email'] ?? '')));
    $password = (string)($body['password'] ?? '');

    if ($email === '' || $password === '') {
        customer_api_send_error('validation_error', 'email and password are required', 400);
    }

    $stmt = $conn->prepare("
        SELECT * FROM tenant_portal_users
        WHERE LOWER(TRIM(email)) = ? AND status = 'approved'
    ");
    $stmt->execute([$email]);
    $candidates = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $matched = null;
    foreach ($candidates as $tpu) {
        if (password_verify($password, (string)($tpu['password_hash'] ?? ''))) {
            $matched = $tpu;
            break;
        }
    }

    if ($matched !== null) {
        $tpuId = (int)$matched['id'];
        $conn->prepare('UPDATE tenant_portal_users SET last_login_at = NOW() WHERE id = ?')->execute([$tpuId]);
        $pair = customer_api_issue_token_pair([
            'typ' => 'tenant',
            'sub' => 'tpu:' . $tpuId,
            'cid' => (int)$matched['company_id'],
            'tid' => (int)$matched['tenant_id'],
        ]);
        $ctx = [
            'mode' => 'tpu',
            'tenant_portal_user_id' => $tpuId,
            'user_id' => null,
            'tenant_id' => (int)$matched['tenant_id'],
            'company_id' => (int)$matched['company_id'],
            'display_name' => (string)($matched['display_name'] ?? $matched['email'] ?? ''),
            'email' => (string)($matched['email'] ?? ''),
        ];
        $tenant = customer_api_tenant_payload_for_response($conn, $ctx, null);
        customer_api_send_ok(array_merge([
            'token_type' => 'Bearer',
        ], $pair, [
            'tenant' => $tenant,
        ]));
    }

    $uStmt = $conn->prepare('SELECT id, username, fullname, password FROM user WHERE username = ? LIMIT 1');
    $uStmt->execute([$email]);
    $user = $uStmt->fetch(PDO::FETCH_ASSOC);
    if ($user && customer_api_tenant_verify_legacy_password((string)($user['password'] ?? ''), $password)) {
        $uid = (int)$user['id'];
        $ap = $conn->prepare("SELECT 1 FROM tenant_portal_accounts WHERE user_id = ? AND status = 'approved' LIMIT 1");
        $ap->execute([$uid]);
        if ($ap->fetchColumn()) {
            // continue
        } else {
            $stRow = $conn->prepare('SELECT status FROM tenant_portal_accounts WHERE user_id = ? LIMIT 1');
            $stRow->execute([$uid]);
            $st = $stRow->fetchColumn();
            if ($st === false) {
                customer_api_send_error('invalid_credentials', 'Invalid email or password', 401);
            }
            if ($st === 'pending_approval') {
                customer_api_send_error('account_pending', 'Your account is pending approval', 403);
            }
            customer_api_send_error('account_suspended', 'Portal access has been suspended or revoked', 403);
        }
        $acc = $conn->prepare("
            SELECT tpa.company_id, l.tenant_id
            FROM tenant_portal_accounts tpa
            INNER JOIN re_leases l ON l.id = tpa.lease_id
            WHERE tpa.user_id = ? AND tpa.status = 'approved'
            ORDER BY tpa.lease_id
            LIMIT 1
        ");
        $acc->execute([$uid]);
        $first = $acc->fetch(PDO::FETCH_ASSOC);
        if (!$first) {
            customer_api_send_error('invalid_credentials', 'Invalid email or password', 401);
        }
        $pair = customer_api_issue_token_pair([
            'typ' => 'tenant',
            'sub' => 'usr:' . $uid,
            'cid' => (int)$first['company_id'],
            'tid' => (int)$first['tenant_id'],
        ]);
        $ctx = [
            'mode' => 'usr',
            'tenant_portal_user_id' => null,
            'user_id' => $uid,
            'tenant_id' => (int)$first['tenant_id'],
            'company_id' => (int)$first['company_id'],
            'display_name' => (string)($user['fullname'] ?? $user['username'] ?? ''),
            'email' => (string)($user['username'] ?? ''),
        ];
        $tenant = customer_api_tenant_payload_for_response($conn, $ctx, null);
        customer_api_send_ok(array_merge([
            'token_type' => 'Bearer',
        ], $pair, [
            'tenant' => $tenant,
        ]));
    }

    customer_api_send_error('invalid_credentials', 'Invalid email or password', 401);
}

function customer_api_tenant_require_lease_header_match(int $pathLeaseId): int {
    $hdr = customer_api_tenant_lease_header_value();
    if ($hdr === null) {
        customer_api_send_error('missing_lease_header', 'X-Tenant-Lease-Id header is required', 400);
    }
    if ($hdr !== $pathLeaseId) {
        customer_api_send_error(
            'lease_header_mismatch',
            'X-Tenant-Lease-Id must match the lease id in the URL',
            400
        );
    }
    return $hdr;
}

function customer_api_tenant_assert_lease_allowed(PDO $conn, array $ctx, int $leaseId): void {
    $allowed = customer_api_tenant_allowed_lease_ids($conn, $ctx);
    if (!in_array($leaseId, $allowed, true)) {
        customer_api_send_error('forbidden', 'You do not have access to this lease', 403);
    }
}

function customer_api_tenant_require_dashboard_lease(PDO $conn, array $ctx): int {
    $hdr = customer_api_tenant_lease_header_value();
    if ($hdr === null) {
        customer_api_send_error('missing_lease_header', 'X-Tenant-Lease-Id header is required', 400);
    }
    customer_api_tenant_assert_lease_allowed($conn, $ctx, $hdr);
    return $hdr;
}

/**
 * @return array<string,mixed>|null
 */
function customer_api_tenant_load_lease_detail(PDO $conn, int $leaseId): ?array {
    $stmt = $conn->prepare("
        SELECT l.*, l.id AS lease_id,
               u.id AS unit_id, u.unit_number, u.unit_type, u.area_sqm,
               b.id AS building_id, b.name AS building_name, b.address AS building_address,
               b.primary_photo_path AS building_primary_photo_path
        FROM re_leases l
        JOIN re_units u ON u.id = l.unit_id
        JOIN re_buildings b ON b.id = u.building_id
        WHERE l.id = ?
        LIMIT 1
    ");
    $stmt->execute([$leaseId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function customer_api_tenant_handle_me(PDO $conn): void {
    $ctx = customer_api_tenant_require_access($conn);
    customer_api_send_ok(customer_api_tenant_payload_for_response($conn, $ctx, null));
}

function customer_api_tenant_handle_set_active_lease(PDO $conn): void {
    $ctx = customer_api_tenant_require_access($conn);
    $body = customer_api_read_json_body();
    $leaseId = (int)($body['lease_id'] ?? 0);
    if ($leaseId <= 0) {
        customer_api_send_error('validation_error', 'lease_id is required', 400);
    }
    customer_api_tenant_assert_lease_allowed($conn, $ctx, $leaseId);
    customer_api_send_ok(['active_lease_id' => $leaseId]);
}

function customer_api_tenant_handle_dashboard(PDO $conn): void {
    $ctx = customer_api_tenant_require_access($conn);
    $leaseId = customer_api_tenant_require_dashboard_lease($conn, $ctx);
    $companyId = (int)$ctx['company_id'];
    $tenantId = (int)$ctx['tenant_id'];

    require_once __DIR__ . '/../../../includes/re_building_photo_helper.php';
    re_building_photo_ensure_schema($conn);

    $lease = customer_api_tenant_load_lease_detail($conn, $leaseId);
    if (!$lease || (int)($lease['company_id'] ?? 0) !== $companyId) {
        customer_api_send_error('not_found', 'Lease not found', 404);
    }

    $mode = (string)($lease['accounting_mode'] ?? 'legacy');
    if (!in_array($mode, ['legacy', 'invoice'], true)) {
        $mode = 'legacy';
    }

    $summary = re_lease_financial_summary($conn, $companyId, $leaseId, $tenantId);
    $financial = customer_api_tenant_financial_payload_for_dashboard($conn, $companyId, $leaseId, $summary);

    $leaseExpiryFlag = 'active';
    $daysToEnd = null;
    if (!empty($lease['end_date'])) {
        try {
            $end = new DateTimeImmutable((string)$lease['end_date']);
            $today = new DateTimeImmutable('today');
            $daysToEnd = (int)floor(($end->getTimestamp() - $today->getTimestamp()) / 86400);
            if ($daysToEnd < 0) {
                $leaseExpiryFlag = 'expired';
            } elseif ($daysToEnd <= 120) {
                $leaseExpiryFlag = 'expiring_soon';
            } else {
                $leaseExpiryFlag = 'active';
            }
        } catch (Throwable $e) {
            $leaseExpiryFlag = 'active';
            $daysToEnd = null;
        }
    }

    $buildingId = (int)($lease['building_id'] ?? 0);
    $unitId = (int)($lease['unit_id'] ?? 0);
    $photoPath = trim((string)($lease['building_primary_photo_path'] ?? ''));
    $buildingPhotoUrl = null;
    if ($photoPath !== '' && strpos($photoPath, '..') === false) {
        $buildingPhotoUrl = customer_api_absolute_url(customer_api_public_path_url($photoPath));
    }

    $announcements = [];
    try {
        require_once __DIR__ . '/../../../includes/re_tenant_announcements_helper.php';
        re_ann_ensure_schema($conn);
        $portalUserId = !empty($ctx['tenant_portal_user_id']) ? (int)$ctx['tenant_portal_user_id'] : null;
        $rawAnn = re_ann_active_for_tenant_context(
            $conn,
            $companyId,
            $tenantId,
            $buildingId > 0 ? $buildingId : null,
            $unitId > 0 ? $unitId : null,
            $portalUserId
        );
        foreach (array_slice($rawAnn, 0, 20) as $a) {
            $img = trim((string)($a['image_path'] ?? ''));
            $announcements[] = [
                'id' => (int)($a['id'] ?? 0),
                'title' => (string)($a['title'] ?? ''),
                'body' => (string)($a['body'] ?? ''),
                'priority' => (string)($a['priority'] ?? 'normal'),
                'category_code' => (string)($a['category_code'] ?? ''),
                'category_name' => (string)($a['category_name'] ?? ''),
                'publish_at' => (string)($a['publish_at'] ?? ''),
                'expire_at' => (string)($a['expire_at'] ?? ''),
                'image_url' => ($img !== '' && strpos($img, '..') === false)
                    ? customer_api_absolute_url(customer_api_public_path_url($img))
                    : null,
                'attachments' => [],
            ];
        }

        // Attachments for listed announcements (optional PDF/docs).
        if ($announcements !== []) {
            $annIds = array_map(static fn($row) => (int)$row['id'], $announcements);
            $annIds = array_values(array_filter($annIds, static fn($id) => $id > 0));
            if ($annIds !== []) {
                $ph = implode(',', array_fill(0, count($annIds), '?'));
                $attSt = $conn->prepare("
                    SELECT announcement_id, file_path, file_name, mime_type
                    FROM re_announcement_attachments
                    WHERE company_id = ? AND announcement_id IN ($ph)
                    ORDER BY id ASC
                ");
                $attSt->execute(array_merge([$companyId], $annIds));
                $byAnn = [];
                foreach ($attSt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $att) {
                    $aid = (int)($att['announcement_id'] ?? 0);
                    $fp = trim((string)($att['file_path'] ?? ''));
                    if ($aid <= 0 || $fp === '' || strpos($fp, '..') !== false) {
                        continue;
                    }
                    $byAnn[$aid][] = [
                        'file_name' => (string)($att['file_name'] ?? 'Attachment'),
                        'mime_type' => (string)($att['mime_type'] ?? ''),
                        'url' => customer_api_absolute_url(customer_api_public_path_url($fp)),
                    ];
                }
                foreach ($announcements as &$annRow) {
                    $annRow['attachments'] = $byAnn[(int)$annRow['id']] ?? [];
                }
                unset($annRow);
            }
        }
    } catch (Throwable $e) {
        $announcements = [];
    }

    customer_api_send_ok([
        'lease' => [
            'lease_id' => $leaseId,
            'start_date' => (string)($lease['start_date'] ?? ''),
            'end_date' => (string)($lease['end_date'] ?? ''),
            'unit_id' => $unitId,
            'unit_number' => (string)($lease['unit_number'] ?? ''),
            'building_id' => $buildingId,
            'building_name' => (string)($lease['building_name'] ?? ''),
            'building_address' => (string)($lease['building_address'] ?? ''),
            'building_photo_url' => $buildingPhotoUrl,
            'status' => (string)($lease['status'] ?? ''),
            'accounting_mode' => $mode,
        ],
        'financial' => $financial,
        'lease_expiry' => [
            'flag' => $leaseExpiryFlag,
            'days_to_end' => $daysToEnd,
        ],
        'announcements' => $announcements,
    ]);
}

function customer_api_tenant_handle_financial_summary(PDO $conn, int $pathLeaseId): void {
    $ctx = customer_api_tenant_require_access($conn);
    customer_api_tenant_require_lease_header_match($pathLeaseId);
    customer_api_tenant_assert_lease_allowed($conn, $ctx, $pathLeaseId);
    $companyId = (int)$ctx['company_id'];
    $tenantId = (int)$ctx['tenant_id'];

    $finCtx = re_lease_load_financial_context($conn, $companyId, $pathLeaseId);
    if (!$finCtx['ok']) {
        customer_api_send_error('not_found', 'Lease not found', 404);
    }

    $summary = re_lease_financial_summary($conn, $companyId, $pathLeaseId, $tenantId);
    customer_api_send_ok($summary);
}

function customer_api_tenant_handle_outstanding_items(PDO $conn, int $pathLeaseId): void {
    $ctx = customer_api_tenant_require_access($conn);
    customer_api_tenant_require_lease_header_match($pathLeaseId);
    customer_api_tenant_assert_lease_allowed($conn, $ctx, $pathLeaseId);
    $companyId = (int)$ctx['company_id'];

    $limit = customer_api_tenant_query_limit(200, 200);
    $payload = re_lease_outstanding_items($conn, $companyId, $pathLeaseId, [
        'open_only' => false,
        'limit' => $limit,
    ]);
    $payload['limit'] = $limit;
    $payload['offset'] = 0;
    $payload['returned'] = count($payload['items'] ?? []);
    customer_api_send_ok($payload);
}

function customer_api_tenant_handle_payment_history(PDO $conn, int $pathLeaseId): void {
    $ctx = customer_api_tenant_require_access($conn);
    customer_api_tenant_require_lease_header_match($pathLeaseId);
    customer_api_tenant_assert_lease_allowed($conn, $ctx, $pathLeaseId);
    $companyId = (int)$ctx['company_id'];

    $limit = customer_api_tenant_query_limit(50, 100);
    $payload = re_lease_payment_history($conn, $companyId, $pathLeaseId, $limit);
    $payload['limit'] = $limit;
    $payload['returned'] = count($payload['receipts'] ?? []);
    customer_api_send_ok($payload);
}

function customer_api_tenant_handle_payment_timeline(PDO $conn, int $pathLeaseId): void {
    $ctx = customer_api_tenant_require_access($conn);
    customer_api_tenant_require_lease_header_match($pathLeaseId);
    customer_api_tenant_assert_lease_allowed($conn, $ctx, $pathLeaseId);
    $companyId = (int)$ctx['company_id'];

    $limit = customer_api_tenant_query_limit(50, 100);
    $payload = re_lease_payment_timeline($conn, $companyId, $pathLeaseId, $limit);
    $payload['limit'] = $limit;
    $payload['returned'] = is_array($payload['events'] ?? null)
        ? count($payload['events'])
        : (is_array($payload['timeline'] ?? null) ? count($payload['timeline']) : 0);
    customer_api_send_ok($payload);
}

function customer_api_tenant_handle_installments(PDO $conn, int $pathLeaseId): void {
    $ctx = customer_api_tenant_require_access($conn);
    customer_api_tenant_require_lease_header_match($pathLeaseId);
    customer_api_tenant_assert_lease_allowed($conn, $ctx, $pathLeaseId);
    $companyId = (int)$ctx['company_id'];
    $tenantId = (int)$ctx['tenant_id'];

    $sched = re_lease_schedule_installments($conn, $companyId, $pathLeaseId);
    $summary = re_lease_financial_summary($conn, $companyId, $pathLeaseId, $tenantId);

    $items = [];
    foreach ($sched['installments'] ?? [] as $r) {
        $items[] = [
            'layer' => 'operational',
            'id' => (int)($r['id'] ?? 0),
            'sequence' => (int)($r['sequence'] ?? 0),
            'installment_date' => (string)($r['installment_date'] ?? ''),
            'amount' => (string)($r['amount'] ?? '0.00'),
            'paid_amount' => (string)($r['paid_amount'] ?? '0.00'),
            'remaining_amount' => (string)($r['remaining_amount'] ?? '0.00'),
            'status' => (string)($r['status'] ?? ''),
            'display_status' => (string)($r['display_status'] ?? $r['status'] ?? ''),
            'cheque_number' => $r['cheque_number'] ?? null,
            'cheque_status' => $r['cheque_status'] ?? null,
            'settlement_source' => $r['settlement_source'] ?? null,
            'paid_at' => $r['paid_at'] ?? null,
            'payment_id' => $r['payment_id'] ?? null,
            'notes' => $r['notes'] ?? null,
        ];
    }

    $limit = customer_api_tenant_query_limit(200, 200);
    $truncated = count($items) > $limit;
    if ($truncated) {
        $items = array_slice($items, 0, $limit);
    }

    customer_api_send_ok([
        'layer' => 'operational',
        'warning' => (string)($sched['warning'] ?? 'Installment schedule is operational only and is not Invoice Mode accounts receivable.'),
        'accounting_mode' => (string)($sched['accounting_mode'] ?? $summary['accounting_mode'] ?? 'legacy'),
        'financial_schema_version' => (int)($summary['financial_schema_version'] ?? 2),
        'installments' => $items,
        'cleared_cheque_count' => (int)($sched['cleared_cheque_count'] ?? 0),
        'open_cheque_count' => (int)($sched['open_cheque_count'] ?? 0),
        'schedule_complete' => !empty($sched['schedule_complete']),
        // Compat alias: rent outstanding from ERP summary (not installment status SUM).
        'outstanding' => [
            'rent_installments' => (string)($summary['rent_outstanding'] ?? '0.00'),
        ],
        'rent_outstanding' => (string)($summary['rent_outstanding'] ?? '0.00'),
        'outstanding_total' => (string)($summary['outstanding_total'] ?? '0.00'),
        'limit' => $limit,
        'returned' => count($items),
        'truncated' => $truncated,
    ]);
}

function customer_api_tenant_handle_payments(PDO $conn, int $pathLeaseId): void {
    $ctx = customer_api_tenant_require_access($conn);
    customer_api_tenant_require_lease_header_match($pathLeaseId);
    customer_api_tenant_assert_lease_allowed($conn, $ctx, $pathLeaseId);
    $companyId = (int)$ctx['company_id'];

    $limit = customer_api_tenant_query_limit(100, 100);
    $hist = re_lease_payment_history($conn, $companyId, $pathLeaseId, $limit);
    $items = [];
    foreach ($hist['receipts'] ?? [] as $r) {
        $items[] = [
            'id' => (int)($r['payment_id'] ?? 0),
            'payment_id' => (int)($r['payment_id'] ?? 0),
            'payment_date' => (string)($r['payment_date'] ?? ''),
            'cleared_date' => $r['cleared_date'] ?? null,
            'amount' => (string)($r['amount'] ?? '0.00'),
            'payment_method' => (string)($r['payment_method'] ?? ''),
            'reference_number' => $r['reference_number'] ?? null,
            'receipt_number' => $r['receipt_number'] ?? null,
            'notes' => null,
            'created_at' => (string)($r['payment_date'] ?? ''),
            'allocated_amount' => (string)($r['allocated_amount'] ?? '0.00'),
            'unallocated_amount' => (string)($r['unallocated_amount'] ?? '0.00'),
            'accounting_mode' => (string)($r['accounting_mode'] ?? $hist['accounting_mode'] ?? ''),
            'allocation_status' => $r['allocation_status'] ?? null,
            'receipt_status' => $r['receipt_status'] ?? null,
            'display_status' => (string)($r['display_status'] ?? 'recorded'),
            'cheque_number' => $r['cheque_number'] ?? null,
            'bank_name' => $r['bank_name'] ?? null,
            'covered_period_start' => $r['covered_period_start'] ?? null,
            'covered_period_end' => $r['covered_period_end'] ?? null,
            'covered_period_label' => $r['covered_period_label'] ?? null,
            'download_path' => $r['download_path'] ?? null,
            'document_source' => $r['document_source'] ?? null,
            'document_ref_id' => $r['document_ref_id'] ?? null,
            'layer' => 'accounting',
        ];
    }

    customer_api_send_ok([
        'accounting_mode' => (string)($hist['accounting_mode'] ?? 'legacy'),
        'financial_schema_version' => 2,
        'payments' => $items,
        'receipts' => $items,
        'receipts_total' => (string)($hist['receipts_total'] ?? '0.00'),
        'receipt_count' => (int)($hist['receipt_count'] ?? count($items)),
        'paid_this_year' => (string)($hist['paid_this_year'] ?? '0.00'),
        'paid_this_year_label' => (string)($hist['paid_this_year_label'] ?? date('Y')),
        'limit' => $limit,
        'returned' => count($items),
    ]);
}

function customer_api_tenant_handle_penalties(PDO $conn, int $pathLeaseId): void {
    $ctx = customer_api_tenant_require_access($conn);
    customer_api_tenant_require_lease_header_match($pathLeaseId);
    customer_api_tenant_assert_lease_allowed($conn, $ctx, $pathLeaseId);
    $companyId = (int)$ctx['company_id'];

    $pack = re_lease_penalty_rows($conn, $companyId, $pathLeaseId);
    $limit = customer_api_tenant_query_limit(200, 200);
    $all = $pack['penalties'] ?? [];
    $truncated = count($all) > $limit;
    $slice = array_slice($all, 0, $limit);
    $items = [];
    foreach ($slice as $r) {
        $items[] = [
            'id' => (int)($r['id'] ?? 0),
            'billing_date' => (string)($r['billing_date'] ?? ''),
            'due_date' => (string)($r['due_date'] ?? ''),
            'item_name' => (string)($r['item_name'] ?? ''),
            'total_amount' => (string)($r['total_amount'] ?? '0.00'),
            'paid_amount' => (string)($r['paid_amount'] ?? '0.00'),
            'outstanding_amount' => (string)($r['outstanding_amount'] ?? '0.00'),
            // Compat: status uses display_status from service (not gross Legacy status SUM).
            'status' => (string)($r['display_status'] ?? $r['status'] ?? ''),
            'display_status' => (string)($r['display_status'] ?? ''),
            'settlement_source' => (string)($r['settlement_source'] ?? $pack['settlement_source'] ?? ''),
        ];
    }

    customer_api_send_ok([
        'accounting_mode' => (string)($pack['accounting_mode'] ?? 'legacy'),
        'settlement_source' => (string)($pack['settlement_source'] ?? ''),
        'financial_schema_version' => 2,
        'penalties' => $items,
        'outstanding' => [
            'penalties' => (string)($pack['outstanding'] ?? '0.00'),
        ],
        'limit' => $limit,
        'returned' => count($items),
        'truncated' => $truncated,
    ]);
}

function customer_api_tenant_handle_invoices(PDO $conn, int $pathLeaseId): void {
    $ctx = customer_api_tenant_require_access($conn);
    customer_api_tenant_require_lease_header_match($pathLeaseId);
    customer_api_tenant_assert_lease_allowed($conn, $ctx, $pathLeaseId);
    $companyId = (int)$ctx['company_id'];

    $limit = customer_api_tenant_query_limit(200, 200);
    $payload = re_lease_outstanding_items($conn, $companyId, $pathLeaseId, [
        'open_only' => false,
        'limit' => $limit,
    ]);

    $items = [];
    foreach ($payload['items'] ?? [] as $r) {
        if (($r['item_type'] ?? '') !== 'invoice' && ($r['layer'] ?? '') === 'operational') {
            // Skip legacy installment rows on invoices endpoint.
            continue;
        }
        if (($r['item_type'] ?? 'invoice') !== 'invoice' && ($r['item_type'] ?? '') === 'installment') {
            continue;
        }
        if (($r['item_type'] ?? '') === 'installment') {
            continue;
        }
        $items[] = [
            'id' => (int)($r['id'] ?? 0),
            'invoice_number' => (string)($r['document_number'] ?? ''),
            'invoice_date' => (string)($r['document_date'] ?? ''),
            'due_date' => (string)($r['due_date'] ?? ''),
            'total_amount' => (string)($r['total_amount'] ?? '0.00'),
            'paid_amount' => (string)($r['paid_amount'] ?? '0.00'),
            'outstanding_amount' => (string)($r['outstanding_amount'] ?? '0.00'),
            // Flutter chips: prefer Payment Manager display_status.
            'status' => (string)($r['display_status'] ?? $r['status'] ?? ''),
            'display_status' => (string)($r['display_status'] ?? ''),
            'raw_status' => (string)($r['status'] ?? ''),
            'class' => (string)($r['class'] ?? ''),
            'description' => (string)($r['description'] ?? ''),
            'layer' => 'accounting',
        ];
    }

    customer_api_send_ok([
        'accounting_mode' => (string)($payload['accounting_mode'] ?? 'legacy'),
        'financial_schema_version' => 2,
        'invoices' => $items,
        'truncated' => !empty($payload['truncated']),
        'limit' => $limit,
        'returned' => count($items),
    ]);
}

function customer_api_tenant_handle_service_charges(PDO $conn, int $pathLeaseId): void {
    $ctx = customer_api_tenant_require_access($conn);
    customer_api_tenant_require_lease_header_match($pathLeaseId);
    customer_api_tenant_assert_lease_allowed($conn, $ctx, $pathLeaseId);
    $companyId = (int)$ctx['company_id'];

    $pack = re_lease_service_charge_rows($conn, $companyId, $pathLeaseId);
    $limit = customer_api_tenant_query_limit(200, 200);
    $all = $pack['service_charges'] ?? [];
    $truncated = count($all) > $limit;
    $slice = array_slice($all, 0, $limit);
    $items = [];
    foreach ($slice as $r) {
        $items[] = [
            'id' => (int)($r['id'] ?? 0),
            'due_date' => (string)($r['due_date'] ?? ''),
            'billing_date' => (string)($r['billing_date'] ?? ''),
            'service_name' => (string)($r['service_name'] ?? ''),
            'item_name' => (string)($r['item_name'] ?? ''),
            'item_description' => $r['item_description'] ?? null,
            'billing_period_start' => $r['billing_period_start'] ?? null,
            'billing_period_end' => $r['billing_period_end'] ?? null,
            'total_amount' => (string)($r['total_amount'] ?? '0.00'),
            'paid_amount' => (string)($r['paid_amount'] ?? '0.00'),
            'outstanding_amount' => (string)($r['outstanding_amount'] ?? '0.00'),
            'status' => (string)($r['display_status'] ?? $r['status'] ?? ''),
            'display_status' => (string)($r['display_status'] ?? ''),
            'is_recurring' => !empty($r['is_recurring']),
            'recurrence_type' => $r['recurrence_type'] ?? null,
            'settlement_source' => (string)($r['settlement_source'] ?? $pack['settlement_source'] ?? ''),
        ];
    }

    customer_api_send_ok([
        'accounting_mode' => (string)($pack['accounting_mode'] ?? 'legacy'),
        'settlement_source' => (string)($pack['settlement_source'] ?? ''),
        'financial_schema_version' => 2,
        'service_charges' => $items,
        'outstanding' => [
            'service_charges' => (string)($pack['outstanding'] ?? '0.00'),
        ],
        'limit' => $limit,
        'returned' => count($items),
        'truncated' => $truncated,
    ]);
}

/**
 * Find tenant portal account eligible for password reset (new or legacy).
 */
function customer_api_tenant_find_reset_account(PDO $conn, string $email): ?array {
    $email = strtolower(trim($email));

    $stmt = $conn->prepare("
        SELECT id, email, display_name, 'tenant_portal_users' AS account_type
        FROM tenant_portal_users
        WHERE LOWER(TRIM(email)) = ? AND status = 'approved'
        LIMIT 1
    ");
    $stmt->execute([$email]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($user) {
        return $user;
    }

    $stmt = $conn->prepare("
        SELECT u.id,
               COALESCE(NULLIF(u.email, ''), u.username) AS email,
               COALESCE(NULLIF(u.fullname, ''), u.username) AS display_name,
               'legacy_user' AS account_type
        FROM user u
        JOIN tenant_portal_accounts tpa ON tpa.user_id = u.id AND tpa.status = 'approved'
        WHERE (LOWER(TRIM(u.email)) = ? OR LOWER(TRIM(u.username)) = ?)
          AND COALESCE(u.is_active, 1) = 1
        LIMIT 1
    ");
    $stmt->execute([$email, $email]);
    $legacy = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($legacy && filter_var($legacy['email'] ?? '', FILTER_VALIDATE_EMAIL)) {
        return $legacy;
    }

    return null;
}

function customer_api_tenant_portal_link(string $path): string {
    if (!function_exists('tenant_portal_absolute_url')) {
        require_once dirname(__DIR__, 3) . '/includes/url_helper.php';
    }
    return tenant_portal_absolute_url($path);
}

function customer_api_tenant_handle_register(PDO $conn): void {
    $body = customer_api_read_json_body();
    $leaseNumber = trim((string)($body['lease_number'] ?? ''));
    $contact = trim((string)($body['contact'] ?? ''));

    if ($leaseNumber === '' || $contact === '') {
        customer_api_send_error(
            'validation_error',
            'Please enter lease number and email or phone number.',
            400
        );
    }

    $isEmail = filter_var($contact, FILTER_VALIDATE_EMAIL) !== false;
    $isPhone = preg_match('/^[\d\s\-\+\(\)]{8,20}$/', $contact) === 1;
    if (!$isEmail && !$isPhone) {
        customer_api_send_error(
            'validation_error',
            'Please enter a valid email address or phone number.',
            400
        );
    }

    $stmt = $conn->prepare("
        SELECT l.id AS lease_id, l.company_id, l.lease_number, l.tenant_id,
               t.first_name, t.last_name, t.email, t.phone
        FROM re_leases l
        JOIN re_tenants t ON t.id = l.tenant_id
        WHERE l.lease_number = ? AND l.status IN ('active', 'expired')
        LIMIT 1
    ");
    $stmt->execute([$leaseNumber]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        customer_api_send_error(
            'lease_not_found',
            'No active or recent lease found with this lease number. Please check and try again, or contact management.',
            404
        );
    }

    $tenantEmail = $row['email'] ? trim(strtolower($row['email'])) : '';
    $tenantPhone = preg_replace('/\s+/', '', $row['phone'] ?? '');
    $contactNormalized = $isEmail ? strtolower($contact) : preg_replace('/\s+/', '', $contact);
    $match = false;
    if ($isEmail && $tenantEmail && $tenantEmail === $contactNormalized) {
        $match = true;
    }
    if ($isPhone && $tenantPhone && $tenantPhone === $contactNormalized) {
        $match = true;
    }
    if ($isPhone && $tenantPhone && strlen($contactNormalized) >= 8
        && substr($tenantPhone, -8) === substr($contactNormalized, -8)) {
        $match = true;
    }

    if (!$match) {
        customer_api_send_error(
            'contact_mismatch',
            'The email or phone you entered does not match our records for this lease. Please use the contact details on your lease, or contact management.',
            400
        );
    }

    $chk = $conn->prepare("
        (SELECT 1 FROM tenant_portal_users WHERE lease_id = ? AND status = 'approved' LIMIT 1)
        UNION ALL
        (SELECT 1 FROM tenant_portal_accounts WHERE lease_id = ? AND status = 'approved' LIMIT 1)
    ");
    $chk->execute([(int)$row['lease_id'], (int)$row['lease_id']]);
    if ($chk->fetchColumn()) {
        customer_api_send_error(
            'account_exists',
            'A portal account already exists for this lease. Please use the login page or reset your password.',
            409
        );
    }

    if (!$isEmail) {
        customer_api_send_ok([
            'message' => 'Verification by phone is not yet available. Please contact management with your lease number and we will send you an invitation by email.',
        ]);
    }

    $tenantName = trim($row['first_name'] . ' ' . $row['last_name']);
    $leaseNum = $row['lease_number'];
    $token = bin2hex(random_bytes(32));
    $tokenHash = hash('sha256', $token);
    $expires = date('Y-m-d H:i:s', time() + 24 * 3600);

    $conn->prepare("
        INSERT INTO tenant_portal_verification_codes (lease_id, email_or_phone, code_hash, expires_at)
        VALUES (?, ?, ?, ?)
    ")->execute([(int)$row['lease_id'], $contact, $tokenHash, $expires]);

    $verifyUrl = customer_api_tenant_portal_link('verify.php?token=' . urlencode($token));
    $settingsStmt = $conn->prepare('SELECT * FROM app_email_settings WHERE id = 1 AND is_enabled = 1');
    $settingsStmt->execute();
    $emailSettings = $settingsStmt->fetch(PDO::FETCH_ASSOC);

    if (!$emailSettings) {
        $conn->prepare('DELETE FROM tenant_portal_verification_codes WHERE lease_id = ? AND code_hash = ?')
            ->execute([(int)$row['lease_id'], $tokenHash]);
        customer_api_send_error(
            'email_not_configured',
            'Email is not configured. Please contact management to complete registration.',
            503
        );
    }

    require_once dirname(__DIR__, 3) . '/includes/mailer.php';
    $subject = 'Complete your Tenant Portal registration';
    $html = "
    <html><body style='font-family: Arial, sans-serif; line-height: 1.6;'>
    <p>Hello " . htmlspecialchars($tenantName) . ",</p>
    <p>You requested access to the Tenant Portal for lease <strong>" . htmlspecialchars($leaseNum) . "</strong>.</p>
    <p>Click the link below to set your password and complete registration (link valid for 24 hours):</p>
    <p><a href='" . htmlspecialchars($verifyUrl) . "' style='display:inline-block; padding:10px 20px; background:#007bff; color:#fff; text-decoration:none; border-radius:6px;'>Set password &amp; complete registration</a></p>
    <p>Or copy this link into your browser:</p>
    <p style='word-break:break-all; color:#666;'>" . htmlspecialchars($verifyUrl) . "</p>
    <p>If you did not request this, please ignore this email.</p>
    <p>— Property Management</p>
    </body></html>";
    $result = send_smtp_mail($emailSettings, $contact, $subject, $html);

    if (!$result['ok']) {
        $conn->prepare('DELETE FROM tenant_portal_verification_codes WHERE lease_id = ? AND code_hash = ?')
            ->execute([(int)$row['lease_id'], $tokenHash]);
        customer_api_send_error(
            'email_send_failed',
            'We could not send the verification email. Please try again later or contact management.',
            502
        );
    }

    customer_api_send_ok([
        'message' => 'A verification link has been sent to your email. Please check your inbox and click the link to set your password and complete registration. The link expires in 24 hours.',
    ]);
}

function customer_api_tenant_handle_forgot_password(PDO $conn): void {
    $body = customer_api_read_json_body();
    $email = trim((string)($body['email'] ?? ''));

    if ($email === '') {
        customer_api_send_error('validation_error', 'Please enter your email address.', 400);
    }
    if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
        customer_api_send_error('validation_error', 'Please enter a valid email address.', 400);
    }

    $emailLower = strtolower($email);
    $genericMessage = "If that email is registered, we've sent a reset link. Please check your inbox. The link expires in 1 hour.";

    $user = customer_api_tenant_find_reset_account($conn, $emailLower);
    if (!$user) {
        customer_api_send_ok(['message' => $genericMessage]);
    }

    $token = bin2hex(random_bytes(32));
    $tokenHash = hash('sha256', $token);
    $expiresAt = date('Y-m-d H:i:s', time() + 3600);

    $conn->prepare('DELETE FROM tenant_portal_password_resets WHERE email = ?')->execute([$emailLower]);
    $conn->prepare("
        INSERT INTO tenant_portal_password_resets (email, token_hash, expires_at)
        VALUES (?, ?, ?)
    ")->execute([$emailLower, $tokenHash, $expiresAt]);

    $resetUrl = customer_api_tenant_portal_link('reset_password.php?token=' . urlencode($token));
    $settingsStmt = $conn->prepare('SELECT * FROM app_email_settings WHERE id = 1 AND is_enabled = 1');
    $settingsStmt->execute();
    $emailSettings = $settingsStmt->fetch(PDO::FETCH_ASSOC);

    if (!$emailSettings) {
        $conn->prepare('DELETE FROM tenant_portal_password_resets WHERE email = ? AND token_hash = ?')
            ->execute([$emailLower, $tokenHash]);
        customer_api_send_error(
            'email_not_configured',
            'Email is not configured. Please contact management to reset your password.',
            503
        );
    }

    require_once dirname(__DIR__, 3) . '/includes/mailer.php';
    $displayName = $user['display_name'] ?: $user['email'];
    $subject = 'Reset your Tenant Portal password';
    $html = "
    <html><body style='font-family: Arial, sans-serif; line-height: 1.6;'>
    <p>Hello " . htmlspecialchars($displayName) . ",</p>
    <p>You requested a password reset for the Tenant Portal.</p>
    <p>Click the link below to set a new password (link valid for 1 hour):</p>
    <p><a href='" . htmlspecialchars($resetUrl) . "' style='display:inline-block; padding:10px 20px; background:#0f4c75; color:#fff; text-decoration:none; border-radius:6px;'>Reset password</a></p>
    <p>Or copy this link into your browser:</p>
    <p style='word-break:break-all; color:#666;'>" . htmlspecialchars($resetUrl) . "</p>
    <p>If you did not request this, please ignore this email. Your password will not be changed.</p>
    <p>— Property Management</p>
    </body></html>";
    $result = send_smtp_mail($emailSettings, $user['email'], $subject, $html);

    if (!$result['ok']) {
        $conn->prepare('DELETE FROM tenant_portal_password_resets WHERE email = ? AND token_hash = ?')
            ->execute([$emailLower, $tokenHash]);
        customer_api_send_error(
            'email_send_failed',
            'We could not send the reset email. Please try again later or contact management.',
            502
        );
    }

    customer_api_send_ok(['message' => $genericMessage]);
}
