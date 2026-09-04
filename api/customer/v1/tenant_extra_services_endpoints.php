<?php
/**
 * Tenant Extra Services — parking, storage, other (lease-scoped, X-Tenant-Lease-Id).
 */

declare(strict_types=1);

/**
 * @return list<string>
 */
function customer_api_tenant_extra_service_allowed_types(): array {
    return ['extra_parking', 'storage', 'other'];
}

/**
 * @return list<string>
 */
function customer_api_tenant_extra_service_monthly_billed_types(): array {
    return ['extra_parking', 'storage'];
}

function customer_api_tenant_extra_service_type_label(string $serviceType): string {
    return match ($serviceType) {
        'extra_parking' => 'Extra parking',
        'storage' => 'Storage',
        'other' => 'Other',
        default => ucwords(str_replace('_', ' ', $serviceType)),
    };
}

function customer_api_tenant_extra_service_payment_status_label(string $status): string {
    return match ($status) {
        'pending_payment' => 'Pending payment',
        'paid' => 'Paid',
        'n_a' => 'Not applicable',
        default => ucwords(str_replace('_', ' ', $status)),
    };
}

function customer_api_tenant_extra_service_tables_exist(PDO $conn): bool {
    try {
        $conn->query('SELECT 1 FROM tenant_extra_service_requests LIMIT 1');
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

function customer_api_tenant_extra_service_has_pricing_columns(PDO $conn): bool {
    static $cache = [];
    $key = spl_object_hash($conn);
    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }
    try {
        $conn->query(
            'SELECT period_from, period_to, monthly_rate_aed, total_amount_aed, payment_status FROM tenant_extra_service_requests LIMIT 1'
        );
        $cache[$key] = true;
    } catch (Throwable $e) {
        $cache[$key] = false;
    }
    return $cache[$key];
}

function customer_api_tenant_extra_service_has_cash_column(PDO $conn): bool {
    static $cache = [];
    $key = spl_object_hash($conn);
    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }
    try {
        $conn->query('SELECT cash_payment_request_id FROM tenant_extra_service_requests LIMIT 1');
        $cache[$key] = true;
    } catch (Throwable $e) {
        $cache[$key] = false;
    }
    return $cache[$key];
}

function customer_api_tenant_extra_service_calculate_total(
    string $periodFrom,
    string $periodTo,
    float $monthlyRate
): ?float {
    $fromTs = strtotime($periodFrom);
    $toTs = strtotime($periodTo);
    if (!$fromTs || !$toTs || $toTs < $fromTs || $monthlyRate <= 0) {
        return null;
    }

    $days = max(1, ($toTs - $fromTs) / 86400);
    $fromD = (int)date('j', $fromTs);
    $fromM = (int)date('n', $fromTs);
    $fromY = (int)date('Y', $fromTs);
    $toD = (int)date('j', $toTs);
    $toM = (int)date('n', $toTs);
    $toY = (int)date('Y', $toTs);
    $calMonths = ($toY - $fromY) * 12 + ($toM - $fromM);
    $extraDays = ($toD >= $fromD) ? ($toD - $fromD + 1) : 0;

    if ($days >= 364 && $days <= 366) {
        return round(12 * $monthlyRate, 2);
    }

    return round($calMonths * $monthlyRate + ($extraDays / 30.44) * $monthlyRate, 2);
}

/**
 * @return array<string,mixed>
 */
function customer_api_tenant_extra_service_quote_for_type(
    PDO $conn,
    int $companyId,
    string $serviceType,
    string $periodFrom,
    string $periodTo,
    bool $hasPricing
): array {
    $out = [
        'service_type' => $serviceType,
        'service_type_label' => customer_api_tenant_extra_service_type_label($serviceType),
        'period_from' => $periodFrom,
        'period_to' => $periodTo,
        'monthly_rate_aed' => null,
        'total_amount_aed' => null,
        'payment_status' => 'n_a',
        'payment_status_label' => customer_api_tenant_extra_service_payment_status_label('n_a'),
    ];

    if (!$hasPricing || !in_array($serviceType, customer_api_tenant_extra_service_monthly_billed_types(), true)) {
        return $out;
    }

    $from = DateTime::createFromFormat('Y-m-d', $periodFrom);
    $to = DateTime::createFromFormat('Y-m-d', $periodTo);
    if (!$from instanceof DateTime || !$to instanceof DateTime) {
        customer_api_send_error('validation_error', 'period_from and period_to must be YYYY-MM-DD', 400);
    }
    if ($to < $from) {
        customer_api_send_error('validation_error', 'period_to must be on or after period_from', 400);
    }

    $rateStmt = $conn->prepare(
        'SELECT monthly_amount_aed FROM re_extra_service_rates WHERE company_id = ? AND service_type = ? AND is_active = 1 LIMIT 1'
    );
    $rateStmt->execute([$companyId, $serviceType]);
    $rateRow = $rateStmt->fetch(PDO::FETCH_ASSOC);
    if (!$rateRow || (float)$rateRow['monthly_amount_aed'] <= 0) {
        return $out;
    }

    $monthly = (float)$rateRow['monthly_amount_aed'];
    $total = customer_api_tenant_extra_service_calculate_total($periodFrom, $periodTo, $monthly);
    if ($total === null) {
        return $out;
    }

    $out['monthly_rate_aed'] = number_format($monthly, 2, '.', '');
    $out['total_amount_aed'] = number_format($total, 2, '.', '');
    if ($total > 0) {
        $out['payment_status'] = 'pending_payment';
        $out['payment_status_label'] = customer_api_tenant_extra_service_payment_status_label('pending_payment');
    }

    return $out;
}

/**
 * @param array<string,mixed> $row
 * @return array<string,mixed>
 */
function customer_api_tenant_format_extra_service_row(array $row, bool $includeDetail = false): array {
    $serviceType = (string)($row['service_type'] ?? 'other');
    $status = (string)($row['status'] ?? 'pending');
    $paymentStatus = (string)($row['payment_status'] ?? 'n_a');

    $out = [
        'id' => (int)($row['id'] ?? 0),
        'service_type' => $serviceType,
        'service_type_label' => customer_api_tenant_extra_service_type_label($serviceType),
        'description' => $row['description'] !== null ? (string)$row['description'] : null,
        'status' => $status,
        'admin_notes' => $row['admin_notes'] !== null ? (string)$row['admin_notes'] : null,
        'created_at' => $row['created_at'] !== null ? (string)$row['created_at'] : null,
        'approved_at' => $row['approved_at'] !== null ? (string)$row['approved_at'] : null,
        'period_from' => isset($row['period_from']) && $row['period_from'] !== null
            ? (string)$row['period_from']
            : null,
        'period_to' => isset($row['period_to']) && $row['period_to'] !== null
            ? (string)$row['period_to']
            : null,
        'monthly_rate_aed' => isset($row['monthly_rate_aed']) && $row['monthly_rate_aed'] !== null
            ? number_format((float)$row['monthly_rate_aed'], 2, '.', '')
            : null,
        'total_amount_aed' => isset($row['total_amount_aed']) && $row['total_amount_aed'] !== null
            ? number_format((float)$row['total_amount_aed'], 2, '.', '')
            : null,
        'payment_status' => $paymentStatus,
        'payment_status_label' => customer_api_tenant_extra_service_payment_status_label($paymentStatus),
    ];

    if (array_key_exists('cash_payment_request_id', $row)) {
        $out['cash_payment_request_id'] = $row['cash_payment_request_id'] !== null
            ? (int)$row['cash_payment_request_id']
            : null;
    }

    if ($includeDetail) {
        $out['timeline'] = customer_api_tenant_extra_service_timeline($row);
    }

    return $out;
}

/**
 * @param array<string,mixed> $row
 * @return list<array<string,mixed>>
 */
function customer_api_tenant_extra_service_timeline(array $row): array {
    $status = (string)($row['status'] ?? 'pending');
    $created = $row['created_at'] ?? null;
    $approved = $row['approved_at'] ?? null;

    return [
        [
            'key' => 'submitted',
            'label' => 'Submitted',
            'at' => $created !== null ? (string)$created : null,
            'completed' => true,
            'current' => $status === 'pending',
        ],
        [
            'key' => 'review',
            'label' => $status === 'rejected' ? 'Rejected' : 'Approved',
            'at' => $approved !== null ? (string)$approved : null,
            'completed' => in_array($status, ['approved', 'rejected'], true),
            'current' => in_array($status, ['approved', 'rejected'], true),
        ],
    ];
}

function customer_api_tenant_handle_extra_service_rates(PDO $conn, int $leaseId): void {
    $ctx = customer_api_tenant_require_access($conn);
    $leaseCtx = customer_api_tenant_services_lease_context($conn, $ctx, $leaseId);
    if ($leaseCtx === null) {
        customer_api_send_error('not_found', 'Lease not found', 404);
    }
    if (!customer_api_tenant_extra_service_tables_exist($conn)) {
        customer_api_send_ok(['configured' => false]);
    }

    $hasPricing = customer_api_tenant_extra_service_has_pricing_columns($conn);
    $rates = [];
    if ($hasPricing) {
        try {
            $stmt = $conn->prepare(
                'SELECT service_type, monthly_amount_aed FROM re_extra_service_rates WHERE company_id = ? AND is_active = 1'
            );
            $stmt->execute([$leaseCtx['company_id']]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $rateRow) {
                $type = (string)($rateRow['service_type'] ?? '');
                if (!in_array($type, customer_api_tenant_extra_service_allowed_types(), true)) {
                    continue;
                }
                $rates[] = [
                    'service_type' => $type,
                    'service_type_label' => customer_api_tenant_extra_service_type_label($type),
                    'monthly_amount_aed' => number_format((float)$rateRow['monthly_amount_aed'], 2, '.', ''),
                ];
            }
        } catch (Throwable $e) {
            // re_extra_service_rates may not exist on older DBs
        }
    }

    customer_api_send_ok([
        'configured' => true,
        'has_pricing' => $hasPricing,
        'rates' => $rates,
        'monthly_billed_types' => customer_api_tenant_extra_service_monthly_billed_types(),
        'allowed_types' => customer_api_tenant_extra_service_allowed_types(),
    ]);
}

function customer_api_tenant_handle_extra_service_quote(PDO $conn, int $leaseId): void {
    $ctx = customer_api_tenant_require_access($conn);
    $leaseCtx = customer_api_tenant_services_lease_context($conn, $ctx, $leaseId);
    if ($leaseCtx === null) {
        customer_api_send_error('not_found', 'Lease not found', 404);
    }
    if (!customer_api_tenant_extra_service_tables_exist($conn)) {
        customer_api_send_ok(['configured' => false]);
    }

    $serviceType = trim((string)($_GET['service_type'] ?? ''));
    if (!in_array($serviceType, customer_api_tenant_extra_service_allowed_types(), true)) {
        customer_api_send_error('validation_error', 'service_type is required', 400);
    }

    $periodFrom = trim((string)($_GET['period_from'] ?? ''));
    $periodTo = trim((string)($_GET['period_to'] ?? ''));
    if ($periodFrom === '' || $periodTo === '') {
        customer_api_send_error('validation_error', 'period_from and period_to are required', 400);
    }

    $hasPricing = customer_api_tenant_extra_service_has_pricing_columns($conn);
    $quote = customer_api_tenant_extra_service_quote_for_type(
        $conn,
        $leaseCtx['company_id'],
        $serviceType,
        $periodFrom,
        $periodTo,
        $hasPricing
    );
    $quote['configured'] = true;
    $quote['has_pricing'] = $hasPricing;

    customer_api_send_ok($quote);
}

function customer_api_tenant_handle_extra_service_list(PDO $conn, int $leaseId): void {
    $ctx = customer_api_tenant_require_access($conn);
    if (!customer_api_tenant_services_lease_context($conn, $ctx, $leaseId)) {
        customer_api_send_error('not_found', 'Lease not found', 404);
    }
    if (!customer_api_tenant_extra_service_tables_exist($conn)) {
        customer_api_send_ok(['requests' => [], 'configured' => false]);
    }

    $hasPricing = customer_api_tenant_extra_service_has_pricing_columns($conn);
    $hasCash = customer_api_tenant_extra_service_has_cash_column($conn);

    $selectCols = 'id, service_type, description, status, admin_notes, created_at, approved_at';
    if ($hasPricing) {
        $selectCols .= ', period_from, period_to, monthly_rate_aed, total_amount_aed, payment_status';
    }
    if ($hasCash) {
        $selectCols .= ', cash_payment_request_id';
    }

    $stmt = $conn->prepare("
        SELECT {$selectCols}
        FROM tenant_extra_service_requests
        WHERE lease_id = ?
        ORDER BY created_at DESC
    ");
    $stmt->execute([$leaseId]);
    $items = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $items[] = customer_api_tenant_format_extra_service_row($row, false);
    }

    customer_api_send_ok([
        'requests' => $items,
        'configured' => true,
        'has_pricing' => $hasPricing,
    ]);
}

function customer_api_tenant_handle_extra_service_detail(PDO $conn, int $leaseId, int $requestId): void {
    $ctx = customer_api_tenant_require_access($conn);
    if (!customer_api_tenant_services_lease_context($conn, $ctx, $leaseId)) {
        customer_api_send_error('not_found', 'Lease not found', 404);
    }

    $stmt = $conn->prepare(
        'SELECT * FROM tenant_extra_service_requests WHERE id = ? AND lease_id = ? LIMIT 1'
    );
    $stmt->execute([$requestId, $leaseId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        customer_api_send_error('not_found', 'Extra service request not found', 404);
    }

    customer_api_send_ok([
        'request' => customer_api_tenant_format_extra_service_row($row, true),
    ]);
}

function customer_api_tenant_handle_extra_service_create(PDO $conn, int $leaseId): void {
    $ctx = customer_api_tenant_require_access($conn);
    $leaseCtx = customer_api_tenant_services_lease_context($conn, $ctx, $leaseId);
    if ($leaseCtx === null) {
        customer_api_send_error('not_found', 'Lease not found', 404);
    }
    if (!customer_api_tenant_extra_service_tables_exist($conn)) {
        customer_api_send_error('not_configured', 'Extra service requests are not available', 503);
    }

    $body = customer_api_read_json_body();
    $serviceType = trim((string)($body['service_type'] ?? 'other'));
    if (!in_array($serviceType, customer_api_tenant_extra_service_allowed_types(), true)) {
        customer_api_send_error(
            'validation_error',
            'service_type must be one of: extra_parking, storage, other',
            400
        );
    }

    $description = trim((string)($body['description'] ?? ''));
    $periodFromRaw = trim((string)($body['period_from'] ?? ''));
    $periodToRaw = trim((string)($body['period_to'] ?? ''));

    $hasPricing = customer_api_tenant_extra_service_has_pricing_columns($conn);
    $periodFromSql = null;
    $periodToSql = null;
    $monthlyRateSql = null;
    $totalAmountSql = null;
    $paymentStatusSql = 'n_a';

    if (
        $hasPricing
        && in_array($serviceType, customer_api_tenant_extra_service_monthly_billed_types(), true)
        && $periodFromRaw !== ''
        && $periodToRaw !== ''
    ) {
        $from = DateTime::createFromFormat('Y-m-d', $periodFromRaw);
        $to = DateTime::createFromFormat('Y-m-d', $periodToRaw);
        if (!$from instanceof DateTime || !$to instanceof DateTime) {
            customer_api_send_error('validation_error', 'period_from and period_to must be YYYY-MM-DD', 400);
        }
        if ($to < $from) {
            customer_api_send_error('validation_error', 'period_to must be on or after period_from', 400);
        }

        $periodFromSql = $from->format('Y-m-d');
        $periodToSql = $to->format('Y-m-d');

        $quote = customer_api_tenant_extra_service_quote_for_type(
            $conn,
            $leaseCtx['company_id'],
            $serviceType,
            $periodFromSql,
            $periodToSql,
            true
        );
        if ($quote['monthly_rate_aed'] !== null) {
            $monthlyRateSql = (float)$quote['monthly_rate_aed'];
        }
        if ($quote['total_amount_aed'] !== null) {
            $totalAmountSql = (float)$quote['total_amount_aed'];
        }
        $paymentStatusSql = (string)($quote['payment_status'] ?? 'n_a');
    }

    if ($hasPricing) {
        $ins = $conn->prepare("
            INSERT INTO tenant_extra_service_requests
                (tenant_id, lease_id, company_id, service_type, description, status,
                 period_from, period_to, monthly_rate_aed, total_amount_aed, payment_status)
            VALUES (?, ?, ?, ?, ?, 'pending', ?, ?, ?, ?, ?)
        ");
        $ins->execute([
            $leaseCtx['tenant_id'],
            $leaseId,
            $leaseCtx['company_id'],
            $serviceType,
            $description !== '' ? $description : null,
            $periodFromSql,
            $periodToSql,
            $monthlyRateSql,
            $totalAmountSql,
            $paymentStatusSql,
        ]);
    } else {
        $ins = $conn->prepare("
            INSERT INTO tenant_extra_service_requests
                (tenant_id, lease_id, company_id, service_type, description, status)
            VALUES (?, ?, ?, ?, ?, 'pending')
        ");
        $ins->execute([
            $leaseCtx['tenant_id'],
            $leaseId,
            $leaseCtx['company_id'],
            $serviceType,
            $description !== '' ? $description : null,
        ]);
    }

    $requestId = (int)$conn->lastInsertId();

    $emailHelper = dirname(__DIR__, 3) . '/modules/realestate/includes/re_email_helper.php';
    if ($requestId > 0 && file_exists($emailHelper)) {
        require_once $emailHelper;
        if (function_exists('send_extra_service_request_notification')) {
            send_extra_service_request_notification($conn, $requestId, $leaseCtx['company_id']);
        }
    }

    $stmt = $conn->prepare('SELECT * FROM tenant_extra_service_requests WHERE id = ? LIMIT 1');
    $stmt->execute([$requestId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

    customer_api_send_ok([
        'request' => customer_api_tenant_format_extra_service_row($row, true),
    ], 201);
}
