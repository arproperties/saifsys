<?php
/**
 * Lease Financial Summary Service — canonical read-only financial DTOs for RE leases.
 *
 * Source of truth:
 * - Invoice Mode: Payment Manager helpers (re_pm_im_*) + obligation allocation maps
 * - Legacy: existing allocation read helpers only (DEC-004 — do not extend Legacy posting)
 *
 * Consumers (Phase 2+): Customer API thin handlers. Future clients may reuse this facade.
 * This module performs no writes (no INSERT/UPDATE/DELETE, no posting, no allocations).
 *
 * @see docs/CUSTOMER_APP_FINANCIAL_SYNC_IMPLEMENTATION_PLAN.md
 */
declare(strict_types=1);

require_once __DIR__ . '/lease_payment_manager_im_helper.php';
require_once __DIR__ . '/payment_allocation_helper.php';
require_once __DIR__ . '/receipt_allocation_read_helper.php';

/**
 * Request-scoped memo for financial reads.
 * Key MUST include company_id + lease_id (and limit where relevant).
 * Never shared across HTTP requests; static clears with PHP request lifecycle.
 *
 * @return array<string,mixed>
 */
function &re_lease_fs_request_cache_bag(): array
{
    static $bag = [];
    return $bag;
}

function re_lease_fs_request_cache_get(string $key)
{
    $bag = &re_lease_fs_request_cache_bag();
    return array_key_exists($key, $bag) ? $bag[$key] : null;
}

function re_lease_fs_request_cache_set(string $key, $value): void
{
    $bag = &re_lease_fs_request_cache_bag();
    $bag[$key] = $value;
}

function re_lease_fs_request_cache_has(string $key): bool
{
    $bag = &re_lease_fs_request_cache_bag();
    return array_key_exists($key, $bag);
}

/**
 * Request-memoized invoices-by-class (same DTO; fewer round-trips within one request).
 *
 * @return array<string,mixed>
 */
function re_lease_fs_cached_invoices_by_class(PDO $conn, int $companyId, int $leaseId, int $limit = 50): array
{
    $limit = max(1, min(500, $limit));
    $key = 'invclass:' . $companyId . ':' . $leaseId . ':' . $limit;
    if (re_lease_fs_request_cache_has($key)) {
        /** @var array<string,mixed> $cached */
        $cached = re_lease_fs_request_cache_get($key);
        return $cached;
    }
    $value = re_pm_im_invoices_by_class($conn, $companyId, $leaseId, $limit);
    re_lease_fs_request_cache_set($key, $value);
    return $value;
}

/**
 * @return array<string,mixed>
 */
function re_lease_fs_cached_kpis(PDO $conn, int $companyId, int $leaseId, int $tenantId = 0): array
{
    $key = 'kpis:' . $companyId . ':' . $leaseId . ':' . $tenantId;
    if (re_lease_fs_request_cache_has($key)) {
        /** @var array<string,mixed> $cached */
        $cached = re_lease_fs_request_cache_get($key);
        return $cached;
    }
    $value = re_pm_im_kpis($conn, $companyId, $leaseId, $tenantId);
    re_lease_fs_request_cache_set($key, $value);
    return $value;
}

/**
 * @return array{receipts:list<array<string,mixed>>,allocations:list<array<string,mixed>>}
 */
function re_lease_fs_cached_recent_receipts(PDO $conn, int $companyId, int $leaseId, int $limit = 25): array
{
    $limit = max(1, min(100, $limit));
    $key = 'receipts:' . $companyId . ':' . $leaseId . ':' . $limit;
    if (re_lease_fs_request_cache_has($key)) {
        /** @var array{receipts:list<array<string,mixed>>,allocations:list<array<string,mixed>>} $cached */
        $cached = re_lease_fs_request_cache_get($key);
        return $cached;
    }
    $value = re_pm_im_recent_receipts($conn, $companyId, $leaseId, $limit);
    re_lease_fs_request_cache_set($key, $value);
    return $value;
}

if (!function_exists('re_lease_fs_money_str')) {
    /** API-facing money: always two decimal places as string. */
    function re_lease_fs_money_str($value): string
    {
        return number_format(round((float)$value, 2), 2, '.', '');
    }
}

if (!function_exists('re_lease_fs_money_float')) {
    function re_lease_fs_money_float($value): float
    {
        return round((float)$value, 2);
    }
}

if (!function_exists('re_lease_fs_normalize_mode')) {
    /**
     * Matches re_accounting_normalize_mode without pulling auth-bound accounting_mode_helper.
     */
    function re_lease_fs_normalize_mode(?string $mode): string
    {
        return in_array($mode, ['legacy', 'invoice'], true) ? $mode : 'legacy';
    }
}

if (!function_exists('re_lease_fs_empty_summary')) {
    /**
     * @return array<string,mixed>
     */
    function re_lease_fs_empty_summary(int $companyId, int $leaseId, int $tenantId = 0, string $mode = 'legacy'): array
    {
        return [
            'financial_schema_version' => 2,
            'lease_id' => $leaseId,
            'company_id' => $companyId,
            'tenant_id' => $tenantId,
            'accounting_mode' => $mode,
            'currency' => 'AED',
            'as_of_date' => date('Y-m-d'),
            'display_status' => 'up_to_date',
            'outstanding_total' => '0.00',
            'rent_outstanding' => '0.00',
            'service_charge_outstanding' => '0.00',
            'penalty_outstanding' => '0.00',
            'overdue_amount' => '0.00',
            'due_now_amount' => '0.00',
            'invoice_total' => '0.00',
            'paid_total' => '0.00',
            'receipts_total' => '0.00',
            'allocated_total' => '0.00',
            'unallocated_receipts' => '0.00',
            'vat_total' => null,
            'vat_available' => false,
            'tenant_credit' => '0.00',
            'invoice_count' => 0,
            'receipt_count' => 0,
            'due_now_count' => 0,
            'overdue_count' => 0,
            'last_payment' => null,
            'next_due' => null,
        ];
    }
}

if (!function_exists('re_lease_fs_rollup_display_status')) {
    function re_lease_fs_rollup_display_status(float $overdue, float $dueNow, float $outstanding): string
    {
        if ($overdue > 0.005) {
            return 'overdue';
        }
        if ($dueNow > 0.005) {
            return 'due';
        }
        if ($outstanding > 0.005) {
            return 'open';
        }
        return 'up_to_date';
    }
}

if (!function_exists('re_lease_fs_receipt_display_status')) {
    /**
     * Derive display status from stored receipt fields only (no invented statuses).
     */
    function re_lease_fs_receipt_display_status(array $receipt): string
    {
        $receiptStatus = strtolower(trim((string)($receipt['receipt_status'] ?? '')));
        if ($receiptStatus !== '') {
            return $receiptStatus;
        }
        $alloc = strtolower(trim((string)($receipt['allocation_status'] ?? '')));
        if ($alloc !== '') {
            return $alloc;
        }
        $unallocated = (float)($receipt['unallocated_amount'] ?? 0);
        $allocated = (float)($receipt['allocated_amount'] ?? 0);
        if ($unallocated > 0.005 && $allocated > 0.005) {
            return 'partial';
        }
        if ($unallocated > 0.005) {
            return 'unallocated';
        }
        if ($allocated > 0.005) {
            return 'allocated';
        }
        return 'recorded';
    }
}

if (!function_exists('re_lease_load_financial_context')) {
    /**
     * Fail-closed lease load scoped by company_id + lease_id.
     *
     * @return array{
     *   ok: bool,
     *   error?: string,
     *   company_id: int,
     *   lease_id: int,
     *   tenant_id: int,
     *   accounting_mode: string,
     *   lease: ?array<string,mixed>
     * }
     */
    function re_lease_load_financial_context(PDO $conn, int $companyId, int $leaseId): array
    {
        $cacheKey = 'ctx:' . $companyId . ':' . $leaseId;
        if (re_lease_fs_request_cache_has($cacheKey)) {
            /** @var array<string,mixed> $cached */
            $cached = re_lease_fs_request_cache_get($cacheKey);
            return $cached;
        }

        $base = [
            'ok' => false,
            'company_id' => $companyId,
            'lease_id' => $leaseId,
            'tenant_id' => 0,
            'accounting_mode' => 'legacy',
            'lease' => null,
        ];

        if ($companyId <= 0 || $leaseId <= 0) {
            $base['error'] = 'invalid_identifiers';
            re_lease_fs_request_cache_set($cacheKey, $base);
            return $base;
        }

        try {
            $stmt = $conn->prepare("
                SELECT id, company_id, tenant_id, unit_id, lease_number, status,
                       start_date, end_date, COALESCE(accounting_mode, 'legacy') AS accounting_mode
                FROM re_leases
                WHERE id = ? AND company_id = ?
                LIMIT 1
            ");
            $stmt->execute([$leaseId, $companyId]);
            $lease = $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            $base['error'] = 'lease_lookup_failed';
            re_lease_fs_request_cache_set($cacheKey, $base);
            return $base;
        }

        if (!$lease) {
            $base['error'] = 'lease_not_found';
            re_lease_fs_request_cache_set($cacheKey, $base);
            return $base;
        }

        $mode = re_lease_fs_normalize_mode((string)($lease['accounting_mode'] ?? 'legacy'));
        $lease['accounting_mode'] = $mode;

        $ok = [
            'ok' => true,
            'company_id' => (int)$lease['company_id'],
            'lease_id' => (int)$lease['id'],
            'tenant_id' => (int)($lease['tenant_id'] ?? 0),
            'accounting_mode' => $mode,
            'lease' => $lease,
        ];
        re_lease_fs_request_cache_set($cacheKey, $ok);
        return $ok;
    }
}

if (!function_exists('re_lease_fs_sum_invoice_vat')) {
    /**
     * New SQL: SUM(tax_amount) on re_invoices — no existing PM helper exposes VAT aggregate.
     * Returns null when column/query unavailable (vat_available=false); never invents values.
     */
    function re_lease_fs_sum_invoice_vat(PDO $conn, int $companyId, int $leaseId): ?float
    {
        if ($companyId <= 0 || $leaseId <= 0) {
            return null;
        }
        try {
            $stmt = $conn->prepare("
                SELECT COALESCE(SUM(tax_amount), 0)
                FROM re_invoices
                WHERE company_id = ? AND lease_id = ? AND status <> 'cancelled'
            ");
            $stmt->execute([$companyId, $leaseId]);
            return re_lease_fs_money_float($stmt->fetchColumn());
        } catch (Throwable $e) {
            return null;
        }
    }
}

if (!function_exists('re_lease_fs_legacy_outstanding')) {
    /**
     * Read-only Legacy AR remaining using installment allocations
     * (same pattern as reporting_mode_helper / outstandings_report).
     *
     * @return array{outstanding: float, rent_outstanding: float}
     */
    function re_lease_fs_legacy_outstanding(PDO $conn, int $companyId, int $leaseId): array
    {
        $empty = ['outstanding' => 0.0, 'rent_outstanding' => 0.0];
        if ($companyId <= 0 || $leaseId <= 0) {
            return $empty;
        }
        try {
            $stmt = $conn->prepare("
                SELECT COALESCE(SUM(GREATEST(li.amount - COALESCE(paid.total_paid, 0), 0)), 0)
                FROM re_lease_installments li
                LEFT JOIN (
                    SELECT installment_id, COALESCE(SUM(amount_allocated), 0) AS total_paid
                    FROM re_payment_allocations
                    GROUP BY installment_id
                ) paid ON paid.installment_id = li.id
                WHERE li.company_id = ?
                  AND li.lease_id = ?
                  AND li.status NOT IN ('paid', 'cancelled', 'waived', 'returned')
            ");
            $stmt->execute([$companyId, $leaseId]);
            $outstanding = re_lease_fs_money_float($stmt->fetchColumn());
            return [
                'outstanding' => $outstanding,
                'rent_outstanding' => $outstanding,
            ];
        } catch (Throwable $e) {
            return $empty;
        }
    }
}

if (!function_exists('re_lease_fs_legacy_billing_outstanding')) {
    /**
     * Legacy billing-item outstanding for a given item_type (batched paid map).
     *
     * @return array{outstanding: float, rows: list<array<string,mixed>>}
     */
    function re_lease_fs_legacy_billing_outstanding(PDO $conn, int $companyId, int $leaseId, string $itemType): array
    {
        $out = ['outstanding' => 0.0, 'rows' => []];
        if ($companyId <= 0 || $leaseId <= 0 || $itemType === '') {
            return $out;
        }

        try {
            $stmt = $conn->prepare("
                SELECT bi.id, bi.billing_date, bi.due_date, bi.item_name, bi.item_description,
                       bi.total_amount, bi.status, bi.billing_period_start, bi.billing_period_end,
                       bi.item_type, bi.service_charge_id,
                       sc.charge_name AS service_name, sc.is_recurring, sc.recurrence_type
                FROM re_billing_items bi
                LEFT JOIN re_service_charges sc
                    ON sc.id = bi.service_charge_id AND sc.company_id = bi.company_id
                WHERE bi.company_id = ?
                  AND bi.lease_id = ?
                  AND bi.item_type = ?
                ORDER BY bi.due_date ASC, bi.id ASC
            ");
            $stmt->execute([$companyId, $leaseId, $itemType]);
            $raw = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            return $out;
        }

        $ids = [];
        foreach ($raw as $row) {
            $ids[] = (int)$row['id'];
        }

        $paidMap = [];
        if ($ids !== []) {
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            try {
                $pStmt = $conn->prepare("
                    SELECT billing_item_id, COALESCE(SUM(amount_allocated), 0) AS total_paid
                    FROM re_billing_item_payment_allocations
                    WHERE billing_item_id IN ($placeholders)
                    GROUP BY billing_item_id
                ");
                $pStmt->execute($ids);
                foreach ($pStmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $pr) {
                    $paidMap[(int)$pr['billing_item_id']] = re_lease_fs_money_float($pr['total_paid']);
                }
            } catch (Throwable $e) {
                $paidMap = [];
            }
        }

        $today = date('Y-m-d');
        $open = 0.0;
        foreach ($raw as $row) {
            $id = (int)$row['id'];
            $total = re_lease_fs_money_float($row['total_amount'] ?? 0);
            $status = (string)($row['status'] ?? '');
            $paid = $paidMap[$id] ?? null;
            if ($paid === null) {
                $paid = get_billing_item_total_paid($conn, $id);
            }
            $outstanding = ($status === 'waived') ? 0.0 : max(0.0, re_lease_fs_money_float($total - $paid));

            if ($status === 'waived') {
                $display = 'waived';
            } elseif ($outstanding <= 0.005 && $total > 0) {
                $display = 'paid';
            } elseif ($paid > 0.005) {
                $display = 'partial';
            } elseif (!empty($row['due_date']) && (string)$row['due_date'] < $today) {
                $display = 'overdue';
            } else {
                $display = $status !== '' ? $status : 'pending';
            }

            $out['rows'][] = [
                'id' => $id,
                'item_type' => (string)$itemType,
                'due_date' => (string)($row['due_date'] ?? ''),
                'billing_date' => (string)($row['billing_date'] ?? ''),
                'service_name' => (string)(($row['service_name'] ?? '') ?: ($row['item_name'] ?? '')),
                'item_name' => (string)($row['item_name'] ?? ''),
                'item_description' => $row['item_description'] !== null ? (string)$row['item_description'] : null,
                'billing_period_start' => $row['billing_period_start'] !== null ? (string)$row['billing_period_start'] : null,
                'billing_period_end' => $row['billing_period_end'] !== null ? (string)$row['billing_period_end'] : null,
                'total_amount' => re_lease_fs_money_str($total),
                'paid_amount' => re_lease_fs_money_str($paid),
                'outstanding_amount' => re_lease_fs_money_str($outstanding),
                'status' => $status,
                'display_status' => $display,
                'is_recurring' => !empty($row['is_recurring']),
                'recurrence_type' => $row['recurrence_type'] !== null ? (string)$row['recurrence_type'] : null,
                'settlement_source' => 'legacy_billing_allocations',
            ];
            $open += $outstanding;
        }
        $out['outstanding'] = re_lease_fs_money_float($open);
        return $out;
    }
}

if (!function_exists('re_lease_fs_im_billing_rows')) {
    /**
     * Invoice Mode operational billing rows settled via obligation allocated_amount.
     * Uses re_billing_item_obligation_allocated_map (batched).
     *
     * @return array{outstanding: float, rows: list<array<string,mixed>>}
     */
    function re_lease_fs_im_billing_rows(PDO $conn, int $companyId, int $leaseId, string $itemType): array
    {
        $out = ['outstanding' => 0.0, 'rows' => []];
        if ($companyId <= 0 || $leaseId <= 0) {
            return $out;
        }

        try {
            $stmt = $conn->prepare("
                SELECT bi.id, bi.billing_date, bi.due_date, bi.item_name, bi.item_description,
                       bi.total_amount, bi.status, bi.billing_period_start, bi.billing_period_end,
                       bi.item_type, bi.service_charge_id,
                       sc.charge_name AS service_name, sc.is_recurring, sc.recurrence_type
                FROM re_billing_items bi
                LEFT JOIN re_service_charges sc
                    ON sc.id = bi.service_charge_id AND sc.company_id = bi.company_id
                WHERE bi.company_id = ?
                  AND bi.lease_id = ?
                  AND bi.item_type = ?
                ORDER BY bi.due_date ASC, bi.id ASC
            ");
            $stmt->execute([$companyId, $leaseId, $itemType]);
            $raw = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            return $out;
        }

        $ids = array_map(static fn(array $r): int => (int)$r['id'], $raw);
        $paidMap = re_billing_item_obligation_allocated_map($conn, $companyId, $leaseId, $ids);

        $today = date('Y-m-d');
        $open = 0.0;
        foreach ($raw as $row) {
            $id = (int)$row['id'];
            $total = re_lease_fs_money_float($row['total_amount'] ?? 0);
            $status = (string)($row['status'] ?? '');
            $paid = re_lease_fs_money_float($paidMap[$id] ?? 0.0);
            $outstanding = ($status === 'waived') ? 0.0 : max(0.0, re_lease_fs_money_float($total - $paid));

            if ($status === 'waived') {
                $display = 'waived';
            } elseif ($outstanding <= 0.005 && $total > 0) {
                $display = 'paid';
            } elseif ($paid > 0.005) {
                $display = 'partial';
            } elseif (!empty($row['due_date']) && (string)$row['due_date'] < $today) {
                $display = 'overdue';
            } else {
                $display = $status !== '' ? $status : 'pending';
            }

            $out['rows'][] = [
                'id' => $id,
                'item_type' => (string)$itemType,
                'due_date' => (string)($row['due_date'] ?? ''),
                'billing_date' => (string)($row['billing_date'] ?? ''),
                'service_name' => (string)(($row['service_name'] ?? '') ?: ($row['item_name'] ?? '')),
                'item_name' => (string)($row['item_name'] ?? ''),
                'item_description' => $row['item_description'] !== null ? (string)$row['item_description'] : null,
                'billing_period_start' => $row['billing_period_start'] !== null ? (string)$row['billing_period_start'] : null,
                'billing_period_end' => $row['billing_period_end'] !== null ? (string)$row['billing_period_end'] : null,
                'total_amount' => re_lease_fs_money_str($total),
                'paid_amount' => re_lease_fs_money_str($paid),
                'outstanding_amount' => re_lease_fs_money_str($outstanding),
                'status' => $status,
                'display_status' => $display,
                'is_recurring' => !empty($row['is_recurring']),
                'recurrence_type' => $row['recurrence_type'] !== null ? (string)$row['recurrence_type'] : null,
                'settlement_source' => 'invoice_mode_obligations',
            ];
            $open += $outstanding;
        }
        $out['outstanding'] = re_lease_fs_money_float($open);
        return $out;
    }
}

if (!function_exists('re_lease_fs_build_last_payment')) {
    /**
     * @param list<array<string,mixed>> $receipts
     * @return array<string,mixed>|null
     */
    function re_lease_fs_build_last_payment(array $receipts): ?array
    {
        if ($receipts === []) {
            return null;
        }
        $r = $receipts[0];
        $id = (int)($r['id'] ?? $r['payment_id'] ?? 0);
        return [
            'payment_id' => $id,
            'payment_date' => (string)($r['payment_date'] ?? ''),
            'cleared_date' => isset($r['cleared_date']) && $r['cleared_date'] !== null && $r['cleared_date'] !== ''
                ? (string)$r['cleared_date'] : null,
            'amount' => re_lease_fs_money_str($r['amount'] ?? 0),
            'receipt_number' => isset($r['receipt_number']) && $r['receipt_number'] !== null && $r['receipt_number'] !== ''
                ? (string)$r['receipt_number'] : null,
            'display_status' => re_lease_fs_receipt_display_status($r),
        ];
    }
}

if (!function_exists('re_lease_fs_build_next_due')) {
    /**
     * @param list<array<string,mixed>> $items
     * @return array<string,mixed>|null
     */
    function re_lease_fs_build_next_due(array $items): ?array
    {
        $open = [];
        foreach ($items as $item) {
            if ((float)($item['outstanding_amount'] ?? 0) > 0.005) {
                $open[] = $item;
            }
        }
        if ($open === []) {
            return null;
        }
        usort($open, static function (array $a, array $b): int {
            $da = (string)($a['due_date'] ?? '');
            $db = (string)($b['due_date'] ?? '');
            if ($da === $db) {
                return ((int)($a['id'] ?? 0)) <=> ((int)($b['id'] ?? 0));
            }
            return strcmp($da, $db);
        });
        $n = $open[0];
        return [
            'kind' => 'invoice',
            'reference_id' => (int)($n['id'] ?? 0),
            'reference_number' => (string)($n['document_number'] ?? ''),
            'due_date' => (string)($n['due_date'] ?? ''),
            'amount' => re_lease_fs_money_str($n['outstanding_amount'] ?? 0),
            'display_status' => (string)($n['display_status'] ?? ''),
            'class' => (string)($n['class'] ?? ''),
        ];
    }
}

if (!function_exists('re_lease_outstanding_items')) {
    /**
     * Invoice Mode: invoices with Payment Manager display_status.
     * Legacy: installment remainders (explicit operational layer — not IM AR).
     *
     * @param array{open_only?:bool,limit?:int} $options
     * @return array{accounting_mode:string,items:list<array<string,mixed>>,truncated:bool}
     */
    function re_lease_outstanding_items(PDO $conn, int $companyId, int $leaseId, array $options = []): array
    {
        $openOnly = !empty($options['open_only']);
        $limit = max(1, min(500, (int)($options['limit'] ?? 100)));

        $ctx = re_lease_load_financial_context($conn, $companyId, $leaseId);
        if (!$ctx['ok']) {
            return ['accounting_mode' => 'legacy', 'items' => [], 'truncated' => false, 'error' => $ctx['error'] ?? 'invalid'];
        }

        $companyId = (int)$ctx['company_id'];
        $leaseId = (int)$ctx['lease_id'];
        $mode = (string)$ctx['accounting_mode'];
        $today = date('Y-m-d');

        if ($mode === 'invoice') {
            $byClass = re_lease_fs_cached_invoices_by_class($conn, $companyId, $leaseId, $limit);
            $items = [];
            $seen = [];
            foreach (['rent', 'service', 'other', 'deposit'] as $class) {
                foreach ($byClass[$class] ?? [] as $row) {
                    $id = (int)($row['id'] ?? 0);
                    if (isset($seen[$id])) {
                        continue;
                    }
                    $seen[$id] = true;
                    $outstanding = re_lease_fs_money_float($row['outstanding_amount'] ?? 0);
                    if ($openOnly && $outstanding <= 0.005) {
                        continue;
                    }
                    $items[] = [
                        'item_type' => 'invoice',
                        'id' => $id,
                        'document_number' => (string)($row['invoice_number'] ?? ''),
                        'document_date' => (string)($row['invoice_date'] ?? ''),
                        'due_date' => (string)($row['due_date'] ?? ''),
                        'class' => (string)($row['invoice_class'] ?? $class),
                        'service_kind' => (string)($row['service_kind'] ?? ''),
                        'description' => (string)($row['display_description'] ?? ''),
                        'total_amount' => re_lease_fs_money_str($row['total_amount'] ?? 0),
                        'paid_amount' => re_lease_fs_money_str($row['paid_amount'] ?? 0),
                        'outstanding_amount' => re_lease_fs_money_str($outstanding),
                        'vat_amount' => null,
                        'status' => (string)($row['status'] ?? ''),
                        'display_status' => (string)($row['display_status'] ?? re_pm_im_display_status($row, $today)),
                        'layer' => 'accounting',
                    ];
                }
            }
            usort($items, static function (array $a, array $b): int {
                $c = strcmp((string)$a['due_date'], (string)$b['due_date']);
                return $c !== 0 ? $c : ((int)$a['id'] <=> (int)$b['id']);
            });
            return [
                'accounting_mode' => 'invoice',
                'items' => $items,
                'truncated' => !empty($byClass['truncated']),
                'total_count' => (int)($byClass['total_count'] ?? count($items)),
            ];
        }

        $items = [];
        try {
            $stmt = $conn->prepare("
                SELECT li.id, li.installment_date, li.amount, li.status, li.notes,
                       COALESCE(paid.total_paid, 0) AS total_paid
                FROM re_lease_installments li
                LEFT JOIN (
                    SELECT pa.installment_id, COALESCE(SUM(pa.amount_allocated), 0) AS total_paid
                    FROM re_payment_allocations pa
                    INNER JOIN re_lease_installments li2
                        ON li2.id = pa.installment_id
                       AND li2.company_id = ?
                       AND li2.lease_id = ?
                    GROUP BY pa.installment_id
                ) paid ON paid.installment_id = li.id
                WHERE li.company_id = ? AND li.lease_id = ?
                  AND li.status NOT IN ('cancelled', 'waived', 'returned')
                ORDER BY li.installment_date ASC, li.id ASC
                LIMIT {$limit}
            ");
            $stmt->execute([$companyId, $leaseId, $companyId, $leaseId]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
                $amount = re_lease_fs_money_float($row['amount'] ?? 0);
                $paid = re_lease_fs_money_float($row['total_paid'] ?? 0);
                $remaining = max(0.0, re_lease_fs_money_float($amount - $paid));
                if ($openOnly && $remaining <= 0.005) {
                    continue;
                }
                $status = (string)($row['status'] ?? 'pending');
                $due = (string)($row['installment_date'] ?? '');
                $display = $remaining <= 0.005 ? 'paid' : ($due !== '' && $due < $today ? 'overdue' : ($paid > 0.005 ? 'partial' : $status));
                $items[] = [
                    'item_type' => 'installment',
                    'id' => (int)$row['id'],
                    'document_number' => 'INST-' . (int)$row['id'],
                    'document_date' => $due,
                    'due_date' => $due,
                    'class' => 'rent',
                    'service_kind' => '',
                    'description' => 'Installment',
                    'total_amount' => re_lease_fs_money_str($amount),
                    'paid_amount' => re_lease_fs_money_str($paid),
                    'outstanding_amount' => re_lease_fs_money_str($remaining),
                    'vat_amount' => null,
                    'status' => $status,
                    'display_status' => $display,
                    'layer' => 'operational',
                ];
            }
        } catch (Throwable $e) {
            $items = [];
        }

        return [
            'accounting_mode' => 'legacy',
            'items' => $items,
            'truncated' => false,
        ];
    }
}

if (!function_exists('re_lease_financial_summary')) {
    /**
     * Canonical LeaseFinancialSummary DTO (money fields as two-decimal strings).
     *
     * @return array<string,mixed>
     */
    function re_lease_financial_summary(PDO $conn, int $companyId, int $leaseId, int $tenantId = 0): array
    {
        $ctx = re_lease_load_financial_context($conn, $companyId, $leaseId);
        if (!$ctx['ok']) {
            return re_lease_fs_empty_summary($companyId, $leaseId, $tenantId, 'legacy');
        }

        $companyId = (int)$ctx['company_id'];
        $leaseId = (int)$ctx['lease_id'];
        $tenantId = $tenantId > 0 ? $tenantId : (int)$ctx['tenant_id'];
        $mode = (string)$ctx['accounting_mode'];

        if ($mode === 'invoice') {
            $kpis = re_lease_fs_cached_kpis($conn, $companyId, $leaseId, $tenantId);
            // Single invoices-by-class load (limit 200) — reused for class totals + next_due.
            // Avoids a second re_lease_outstanding_items → re_pm_im_invoices_by_class round-trip.
            $byClass = re_lease_fs_cached_invoices_by_class($conn, $companyId, $leaseId, 200);
            $receiptsPayload = re_lease_fs_cached_recent_receipts($conn, $companyId, $leaseId, 25);
            $receipts = $receiptsPayload['receipts'] ?? [];

            $rentOut = re_lease_fs_money_float($byClass['totals']['rent']['outstanding'] ?? 0);
            $serviceOut = re_lease_fs_money_float($byClass['totals']['service']['outstanding'] ?? 0);
            $penaltyPack = re_lease_fs_im_billing_rows($conn, $companyId, $leaseId, 'penalty');
            $penaltyOut = re_lease_fs_money_float($penaltyPack['outstanding']);

            $vat = re_lease_fs_sum_invoice_vat($conn, $companyId, $leaseId);

            $today = date('Y-m-d');
            $openItems = [];
            $seen = [];
            foreach (['rent', 'service', 'other', 'deposit'] as $class) {
                foreach ($byClass[$class] ?? [] as $row) {
                    $id = (int)($row['id'] ?? 0);
                    if ($id <= 0 || isset($seen[$id])) {
                        continue;
                    }
                    $seen[$id] = true;
                    $outstanding = re_lease_fs_money_float($row['outstanding_amount'] ?? 0);
                    if ($outstanding <= 0.005) {
                        continue;
                    }
                    $openItems[] = [
                        'item_type' => 'invoice',
                        'id' => $id,
                        'document_number' => (string)($row['invoice_number'] ?? ''),
                        'due_date' => (string)($row['due_date'] ?? ''),
                        'class' => (string)($row['invoice_class'] ?? $class),
                        'outstanding_amount' => re_lease_fs_money_str($outstanding),
                        'display_status' => (string)($row['display_status'] ?? re_pm_im_display_status($row, $today)),
                    ];
                }
            }

            $summary = re_lease_fs_empty_summary($companyId, $leaseId, $tenantId, 'invoice');
            $summary['outstanding_total'] = re_lease_fs_money_str($kpis['total_outstanding']);
            $summary['rent_outstanding'] = re_lease_fs_money_str($rentOut);
            $summary['service_charge_outstanding'] = re_lease_fs_money_str($serviceOut);
            $summary['penalty_outstanding'] = re_lease_fs_money_str($penaltyOut);
            $summary['overdue_amount'] = re_lease_fs_money_str($kpis['total_overdue']);
            $summary['due_now_amount'] = re_lease_fs_money_str($kpis['total_due_now']);
            $summary['invoice_total'] = re_lease_fs_money_str($kpis['total_invoiced']);
            $summary['paid_total'] = re_lease_fs_money_str($kpis['total_collected']);
            $summary['receipts_total'] = re_lease_fs_money_str($kpis['total_collected']);
            $summary['allocated_total'] = re_lease_fs_money_str($kpis['allocated_total']);
            $summary['unallocated_receipts'] = re_lease_fs_money_str($kpis['unallocated_receipts']);
            $summary['tenant_credit'] = re_lease_fs_money_str($kpis['tenant_credit']);
            $summary['invoice_count'] = (int)$kpis['invoice_count'];
            $summary['receipt_count'] = (int)$kpis['receipt_count'];
            $summary['due_now_count'] = (int)$kpis['due_now_count'];
            $summary['overdue_count'] = (int)$kpis['overdue_count'];
            if ($vat !== null) {
                $summary['vat_total'] = re_lease_fs_money_str($vat);
                $summary['vat_available'] = true;
            }
            $summary['display_status'] = re_lease_fs_rollup_display_status(
                (float)$kpis['total_overdue'],
                (float)$kpis['total_due_now'],
                (float)$kpis['total_outstanding']
            );
            $summary['last_payment'] = re_lease_fs_build_last_payment($receipts);
            $summary['next_due'] = re_lease_fs_build_next_due($openItems);
            return $summary;
        }

        // Legacy read-only path (DEC-004).
        $leg = re_lease_fs_legacy_outstanding($conn, $companyId, $leaseId);
        $sc = re_lease_fs_legacy_billing_outstanding($conn, $companyId, $leaseId, 'service_charge');
        $pen = re_lease_fs_legacy_billing_outstanding($conn, $companyId, $leaseId, 'penalty');
        $rentOut = re_lease_fs_money_float($leg['rent_outstanding']);
        $scOut = re_lease_fs_money_float($sc['outstanding']);
        $penOut = re_lease_fs_money_float($pen['outstanding']);
        $totalOut = re_lease_fs_money_float($rentOut + $scOut + $penOut);

        $history = re_lease_payment_history($conn, $companyId, $leaseId, 25);
        $receipts = $history['receipts'] ?? [];

        $summary = re_lease_fs_empty_summary($companyId, $leaseId, $tenantId, 'legacy');
        $summary['outstanding_total'] = re_lease_fs_money_str($totalOut);
        $summary['rent_outstanding'] = re_lease_fs_money_str($rentOut);
        $summary['service_charge_outstanding'] = re_lease_fs_money_str($scOut);
        $summary['penalty_outstanding'] = re_lease_fs_money_str($penOut);
        $summary['overdue_amount'] = re_lease_fs_money_str($totalOut);
        $summary['due_now_amount'] = re_lease_fs_money_str($totalOut);
        $summary['paid_total'] = re_lease_fs_money_str($history['receipts_total'] ?? 0);
        $summary['receipts_total'] = re_lease_fs_money_str($history['receipts_total'] ?? 0);
        $summary['receipt_count'] = (int)($history['receipt_count'] ?? 0);
        $summary['display_status'] = re_lease_fs_rollup_display_status($totalOut, $totalOut, $totalOut);
        $summary['last_payment'] = re_lease_fs_build_last_payment($receipts);
        $summary['vat_available'] = false;
        $summary['vat_total'] = null;
        return $summary;
    }
}

if (!function_exists('re_lease_payment_history')) {
    /**
     * Mode-aware payment/receipt history. IM reuses re_pm_im_recent_receipts.
     *
     * @return array{
     *   accounting_mode: string,
     *   receipts: list<array<string,mixed>>,
     *   allocations: list<array<string,mixed>>,
     *   receipts_total: string,
     *   receipt_count: int
     * }
     */
    function re_lease_payment_history(PDO $conn, int $companyId, int $leaseId, int $limit = 25): array
    {
        $limit = max(1, min(100, $limit));
        $empty = [
            'accounting_mode' => 'legacy',
            'receipts' => [],
            'allocations' => [],
            'receipts_total' => '0.00',
            'receipt_count' => 0,
        ];

        $ctx = re_lease_load_financial_context($conn, $companyId, $leaseId);
        if (!$ctx['ok']) {
            $empty['error'] = $ctx['error'] ?? 'invalid';
            return $empty;
        }

        $companyId = (int)$ctx['company_id'];
        $leaseId = (int)$ctx['lease_id'];
        $mode = (string)$ctx['accounting_mode'];

        if ($mode === 'invoice') {
            $payload = re_lease_fs_cached_recent_receipts($conn, $companyId, $leaseId, $limit);
            $receipts = [];
            $total = 0.0;
            foreach ($payload['receipts'] ?? [] as $r) {
                $amount = re_lease_fs_money_float($r['amount'] ?? 0);
                $total += $amount;
                $receipts[] = [
                    'payment_id' => (int)($r['id'] ?? 0),
                    'payment_date' => (string)($r['payment_date'] ?? ''),
                    'cleared_date' => isset($r['cleared_date']) && $r['cleared_date'] !== null && (string)$r['cleared_date'] !== ''
                        ? (string)$r['cleared_date'] : null,
                    'amount' => re_lease_fs_money_str($amount),
                    'allocated_amount' => re_lease_fs_money_str($r['allocated_amount'] ?? 0),
                    'unallocated_amount' => re_lease_fs_money_str($r['unallocated_amount'] ?? 0),
                    'tenant_credit_amount' => re_lease_fs_money_str($r['tenant_credit_amount'] ?? 0),
                    'payment_method' => (string)($r['payment_method'] ?? ''),
                    'receipt_number' => $r['receipt_number'] !== null ? (string)$r['receipt_number'] : null,
                    'reference_number' => $r['reference_number'] !== null ? (string)$r['reference_number'] : null,
                    'accounting_mode' => 'invoice',
                    'receipt_status' => array_key_exists('receipt_status', $r) && $r['receipt_status'] !== null
                        ? (string)$r['receipt_status'] : null,
                    'allocation_status' => isset($r['allocation_status']) ? (string)$r['allocation_status'] : null,
                    'display_status' => re_lease_fs_receipt_display_status($r),
                    'layer' => 'accounting',
                ];
            }

            $allocations = [];
            foreach ($payload['allocations'] ?? [] as $a) {
                $allocations[] = [
                    'id' => (int)($a['id'] ?? 0),
                    'payment_id' => (int)($a['payment_id'] ?? 0),
                    'target_type' => (string)($a['target_type'] ?? ''),
                    'amount_allocated' => re_lease_fs_money_str($a['amount_allocated'] ?? 0),
                    'invoice_id' => isset($a['invoice_id']) && $a['invoice_id'] !== null ? (int)$a['invoice_id'] : null,
                    'obligation_id' => isset($a['obligation_id']) && $a['obligation_id'] !== null ? (int)$a['obligation_id'] : null,
                    'invoice_number' => isset($a['invoice_number']) ? (string)$a['invoice_number'] : null,
                    'created_at' => (string)($a['created_at'] ?? ''),
                ];
            }

            return re_lease_fs_enrich_payment_history_for_tenant(
                $conn,
                $companyId,
                $leaseId,
                [
                    'accounting_mode' => 'invoice',
                    'receipts' => $receipts,
                    'allocations' => $allocations,
                    'receipts_total' => re_lease_fs_money_str($total),
                    'receipt_count' => count($receipts),
                ]
            );
        }

        $receipts = [];
        $total = 0.0;
        try {
            $stmt = $conn->prepare("
                SELECT id, payment_date, amount, payment_method, reference_number, receipt_number,
                       created_at, bank_account_id, cheque_id, installment_id
                FROM re_payments
                WHERE company_id = ? AND lease_id = ?
                ORDER BY payment_date DESC, id DESC
                LIMIT {$limit}
            ");
            $stmt->execute([$companyId, $leaseId]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
                $amount = re_lease_fs_money_float($r['amount'] ?? 0);
                $total += $amount;
                $receipts[] = [
                    'payment_id' => (int)$r['id'],
                    'payment_date' => (string)($r['payment_date'] ?? ''),
                    'cleared_date' => null,
                    'amount' => re_lease_fs_money_str($amount),
                    'allocated_amount' => re_lease_fs_money_str($amount),
                    'unallocated_amount' => '0.00',
                    'tenant_credit_amount' => '0.00',
                    'payment_method' => (string)($r['payment_method'] ?? ''),
                    'receipt_number' => $r['receipt_number'] !== null ? (string)$r['receipt_number'] : null,
                    'reference_number' => $r['reference_number'] !== null ? (string)$r['reference_number'] : null,
                    'accounting_mode' => 'legacy',
                    'receipt_status' => null,
                    'allocation_status' => null,
                    'display_status' => 'recorded',
                    'layer' => 'accounting',
                    'bank_account_id' => isset($r['bank_account_id']) ? (int)$r['bank_account_id'] : null,
                    'cheque_id' => isset($r['cheque_id']) ? (int)$r['cheque_id'] : null,
                    'installment_id' => isset($r['installment_id']) ? (int)$r['installment_id'] : null,
                ];
            }
        } catch (Throwable $e) {
            $receipts = [];
            $total = 0.0;
        }

        return re_lease_fs_enrich_payment_history_for_tenant(
            $conn,
            $companyId,
            $leaseId,
            [
                'accounting_mode' => 'legacy',
                'receipts' => $receipts,
                'allocations' => [],
                'receipts_total' => re_lease_fs_money_str($total),
                'receipt_count' => count($receipts),
            ]
        );
    }
}

if (!function_exists('re_lease_fs_enrich_payment_history_for_tenant')) {
    /**
     * Attach tenant-facing receipt presentation fields from existing ERP rows.
     * Does not change allocation amounts or posting.
     *
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    function re_lease_fs_enrich_payment_history_for_tenant(
        PDO $conn,
        int $companyId,
        int $leaseId,
        array $payload
    ): array {
        $receipts = $payload['receipts'] ?? [];
        if (!is_array($receipts) || $receipts === []) {
            $payload['paid_this_year'] = '0.00';
            $payload['paid_this_year_label'] = (string)date('Y');
            return $payload;
        }

        $ids = [];
        foreach ($receipts as $r) {
            $pid = (int)($r['payment_id'] ?? 0);
            if ($pid > 0) {
                $ids[$pid] = true;
            }
        }
        $idList = array_keys($ids);

        $paymentMeta = [];
        if ($idList !== []) {
            $placeholders = implode(',', array_fill(0, count($idList), '?'));
            try {
                $params = array_merge([$companyId, $leaseId], $idList);
                $st = $conn->prepare("
                    SELECT p.id, p.bank_account_id, p.cheque_id, p.installment_id, p.receipt_status,
                           ba.bank_name AS deposit_bank_name, ba.account_name AS bank_account_name
                    FROM re_payments p
                    LEFT JOIN re_bank_accounts ba
                      ON ba.id = p.bank_account_id AND ba.company_id = p.company_id
                    WHERE p.company_id = ? AND p.lease_id = ? AND p.id IN ({$placeholders})
                ");
                $st->execute($params);
                foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
                    $paymentMeta[(int)$row['id']] = $row;
                }
            } catch (Throwable $e) {
                $paymentMeta = [];
            }
        }

        // Cheques linked by payment_id or settlement_payment_id.
        $chequeByPayment = [];
        try {
            $st = $conn->prepare("
                SELECT id, payment_id, settlement_payment_id, cheque_number, bank_name, installment_id, cheque_date, status
                FROM re_post_dated_cheques
                WHERE company_id = ? AND lease_id = ?
                  AND (payment_id IS NOT NULL OR settlement_payment_id IS NOT NULL)
            ");
            $st->execute([$companyId, $leaseId]);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
                foreach (['payment_id', 'settlement_payment_id'] as $key) {
                    $pid = (int)($row[$key] ?? 0);
                    if ($pid > 0) {
                        $chequeByPayment[$pid] = $row;
                    }
                }
            }
        } catch (Throwable $e) {
            // ignore
        }

        // Invoice due dates for covered period (from history allocations).
        $invoiceDueByPayment = [];
        foreach ($payload['allocations'] ?? [] as $a) {
            $pid = (int)($a['payment_id'] ?? 0);
            $invId = isset($a['invoice_id']) ? (int)$a['invoice_id'] : 0;
            if ($pid <= 0 || $invId <= 0) {
                continue;
            }
            $invoiceDueByPayment[$pid][] = $invId;
        }
        $dueByInvoice = [];
        $allInvIds = [];
        foreach ($invoiceDueByPayment as $list) {
            foreach ($list as $iid) {
                $allInvIds[$iid] = true;
            }
        }
        if ($allInvIds !== []) {
            $invList = array_keys($allInvIds);
            $placeholders = implode(',', array_fill(0, count($invList), '?'));
            try {
                $params = array_merge([$companyId, $leaseId], $invList);
                $st = $conn->prepare("
                    SELECT id, due_date, invoice_date
                    FROM re_invoices
                    WHERE company_id = ? AND lease_id = ? AND id IN ({$placeholders})
                ");
                $st->execute($params);
                foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
                    $dueByInvoice[(int)$row['id']] = (string)(($row['due_date'] ?? '') ?: ($row['invoice_date'] ?? ''));
                }
            } catch (Throwable $e) {
                $dueByInvoice = [];
            }
        }

        // Installment dates for cheque-linked coverage.
        $instDates = [];
        try {
            $st = $conn->prepare("
                SELECT id, installment_date FROM re_lease_installments
                WHERE company_id = ? AND lease_id = ?
            ");
            $st->execute([$companyId, $leaseId]);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
                $instDates[(int)$row['id']] = (string)($row['installment_date'] ?? '');
            }
        } catch (Throwable $e) {
            $instDates = [];
        }

        $year = (int)date('Y');
        $paidThisYear = 0.0;
        $enriched = [];
        foreach ($receipts as $r) {
            $pid = (int)($r['payment_id'] ?? 0);
            $meta = $paymentMeta[$pid] ?? [];
            $cheque = $chequeByPayment[$pid] ?? null;
            if ($cheque === null && !empty($meta['cheque_id'])) {
                try {
                    $st = $conn->prepare("
                        SELECT id, payment_id, settlement_payment_id, cheque_number, bank_name, installment_id, cheque_date, status
                        FROM re_post_dated_cheques
                        WHERE company_id = ? AND lease_id = ? AND id = ?
                        LIMIT 1
                    ");
                    $st->execute([$companyId, $leaseId, (int)$meta['cheque_id']]);
                    $cheque = $st->fetch(PDO::FETCH_ASSOC) ?: null;
                } catch (Throwable $e) {
                    $cheque = null;
                }
            }

            $bankName = '';
            if ($cheque && trim((string)($cheque['bank_name'] ?? '')) !== '') {
                $bankName = trim((string)$cheque['bank_name']);
            } elseif (!empty($meta['deposit_bank_name'])) {
                $bankName = trim((string)$meta['deposit_bank_name']);
            } elseif (!empty($meta['bank_account_name'])) {
                $bankName = trim((string)$meta['bank_account_name']);
            }

            $periodDates = [];
            foreach ($invoiceDueByPayment[$pid] ?? [] as $invId) {
                $d = $dueByInvoice[$invId] ?? '';
                if ($d !== '') {
                    $periodDates[] = $d;
                }
            }
            $instId = (int)(($cheque['installment_id'] ?? 0) ?: ($meta['installment_id'] ?? 0));
            if ($instId > 0 && isset($instDates[$instId]) && $instDates[$instId] !== '') {
                $periodDates[] = $instDates[$instId];
            }
            $periodDates = array_values(array_unique(array_filter($periodDates)));
            sort($periodDates);
            $periodStart = $periodDates[0] ?? null;
            $periodEnd = count($periodDates) > 1 ? $periodDates[count($periodDates) - 1] : $periodStart;

            $payDate = (string)($r['payment_date'] ?? '');
            if ($payDate !== '' && (int)substr($payDate, 0, 4) === $year) {
                $paidThisYear += re_lease_fs_money_float($r['amount'] ?? 0);
            }

            $r['cheque_number'] = $cheque && trim((string)($cheque['cheque_number'] ?? '')) !== ''
                ? (string)$cheque['cheque_number']
                : null;
            $r['bank_name'] = $bankName !== '' ? $bankName : null;
            $r['covered_period_start'] = $periodStart;
            $r['covered_period_end'] = $periodEnd;
            $r['covered_period_label'] = re_lease_fs_format_covered_period_label($periodStart, $periodEnd);
            $r['download_path'] = 'tenant/leases/' . $leaseId . '/documents/payment-receipt/' . $pid . '/download';
            $r['document_source'] = 'payment_receipt';
            $r['document_ref_id'] = $pid;
            // Prefer cleared/receipt_status for tenant surfaces when overpaid/allocated.
            if (isset($meta['receipt_status']) && strtolower((string)$meta['receipt_status']) === 'cleared') {
                if (in_array(strtolower((string)($r['display_status'] ?? '')), ['overpaid', 'allocated', 'fully_allocated', 'recorded', ''], true)) {
                    $r['display_status'] = 'cleared';
                }
            }
            unset($r['bank_account_id'], $r['cheque_id'], $r['installment_id']);
            $enriched[] = $r;
        }

        $payload['receipts'] = $enriched;
        $payload['paid_this_year'] = re_lease_fs_money_str($paidThisYear);
        $payload['paid_this_year_label'] = (string)$year;
        return $payload;
    }
}

if (!function_exists('re_lease_fs_format_covered_period_label')) {
    function re_lease_fs_format_covered_period_label(?string $start, ?string $end): ?string
    {
        if ($start === null || $start === '') {
            return null;
        }
        if ($end === null || $end === '' || $end === $start) {
            return $start;
        }
        return $start . ' – ' . $end;
    }
}

if (!function_exists('re_lease_payment_timeline')) {
    /**
     * Chronological merge of invoices/items and receipts for UI timelines.
     *
     * @return array{accounting_mode:string,events:list<array<string,mixed>>}
     */
    function re_lease_payment_timeline(PDO $conn, int $companyId, int $leaseId, int $limit = 50): array
    {
        $limit = max(1, min(200, $limit));
        $ctx = re_lease_load_financial_context($conn, $companyId, $leaseId);
        if (!$ctx['ok']) {
            return ['accounting_mode' => 'legacy', 'events' => [], 'error' => $ctx['error'] ?? 'invalid'];
        }

        $mode = (string)$ctx['accounting_mode'];
        $companyId = (int)$ctx['company_id'];
        $leaseId = (int)$ctx['lease_id'];

        $events = [];
        $items = re_lease_outstanding_items($conn, $companyId, $leaseId, ['open_only' => false, 'limit' => $limit]);
        foreach ($items['items'] ?? [] as $inv) {
            $events[] = [
                'event_type' => 'invoice',
                'event_date' => (string)($inv['document_date'] ?? $inv['due_date'] ?? ''),
                'sort_date' => (string)($inv['due_date'] ?? $inv['document_date'] ?? ''),
                'id' => (int)($inv['id'] ?? 0),
                'document_number' => (string)($inv['document_number'] ?? ''),
                'amount' => (string)($inv['total_amount'] ?? '0.00'),
                'outstanding_amount' => (string)($inv['outstanding_amount'] ?? '0.00'),
                'display_status' => (string)($inv['display_status'] ?? ''),
                'class' => (string)($inv['class'] ?? ''),
                'layer' => (string)($inv['layer'] ?? 'accounting'),
            ];
        }

        $history = re_lease_payment_history($conn, $companyId, $leaseId, $limit);
        foreach ($history['receipts'] ?? [] as $r) {
            $events[] = [
                'event_type' => 'receipt',
                'event_date' => (string)($r['payment_date'] ?? ''),
                'sort_date' => (string)(($r['cleared_date'] ?? '') ?: ($r['payment_date'] ?? '')),
                'id' => (int)($r['payment_id'] ?? 0),
                'document_number' => (string)($r['receipt_number'] ?? ''),
                'amount' => (string)($r['amount'] ?? '0.00'),
                'outstanding_amount' => '0.00',
                'display_status' => (string)($r['display_status'] ?? ''),
                'class' => '',
                'layer' => 'accounting',
            ];
        }

        usort($events, static function (array $a, array $b): int {
            $c = strcmp((string)$b['sort_date'], (string)$a['sort_date']);
            if ($c !== 0) {
                return $c;
            }
            return ((int)$b['id']) <=> ((int)$a['id']);
        });

        if (count($events) > $limit) {
            $events = array_slice($events, 0, $limit);
        }

        return [
            'accounting_mode' => $mode,
            'events' => $events,
        ];
    }
}

if (!function_exists('re_lease_schedule_installments')) {
    /**
     * Operational installment schedule only — never Invoice Mode AR.
     *
     * @return array{
     *   layer: string,
     *   warning: string,
     *   accounting_mode: string,
     *   installments: list<array<string,mixed>>
     * }
     */
    function re_lease_schedule_installments(PDO $conn, int $companyId, int $leaseId): array
    {
        $base = [
            'layer' => 'operational',
            'warning' => 'Installment schedule is operational only and is not Invoice Mode accounts receivable.',
            'accounting_mode' => 'legacy',
            'installments' => [],
        ];

        $ctx = re_lease_load_financial_context($conn, $companyId, $leaseId);
        if (!$ctx['ok']) {
            $base['error'] = $ctx['error'] ?? 'invalid';
            return $base;
        }

        $companyId = (int)$ctx['company_id'];
        $leaseId = (int)$ctx['lease_id'];
        $base['accounting_mode'] = (string)$ctx['accounting_mode'];

        try {
            $stmt = $conn->prepare("
                SELECT li.id, li.installment_date, li.amount, li.status, li.paid_at, li.payment_id, li.notes,
                       COALESCE(paid.total_paid, 0) AS total_paid
                FROM re_lease_installments li
                LEFT JOIN (
                    SELECT pa.installment_id, COALESCE(SUM(pa.amount_allocated), 0) AS total_paid
                    FROM re_payment_allocations pa
                    INNER JOIN re_lease_installments li2
                        ON li2.id = pa.installment_id
                       AND li2.company_id = ?
                       AND li2.lease_id = ?
                    GROUP BY pa.installment_id
                ) paid ON paid.installment_id = li.id
                WHERE li.company_id = ? AND li.lease_id = ?
                ORDER BY li.installment_date ASC, li.id ASC
            ");
            $stmt->execute([$companyId, $leaseId, $companyId, $leaseId]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            return $base;
        }

        $needHelperIds = [];
        $pendingRows = [];
        foreach ($rows as $row) {
            $amount = re_lease_fs_money_float($row['amount'] ?? 0);
            $paid = re_lease_fs_money_float($row['total_paid'] ?? 0);
            $id = (int)$row['id'];
            if ($paid <= 0.005) {
                $needHelperIds[] = $id;
            }
            $pendingRows[] = [
                'row' => $row,
                'amount' => $amount,
                'paid' => $paid,
                'id' => $id,
            ];
        }

        $helperMap = [];
        if ($needHelperIds !== [] && function_exists('get_installments_total_paid_map')) {
            $helperMap = get_installments_total_paid_map($conn, $needHelperIds);
        } elseif ($needHelperIds !== [] && function_exists('get_installment_total_paid')) {
            foreach ($needHelperIds as $hid) {
                $helperMap[$hid] = get_installment_total_paid($conn, $hid);
            }
        }

        // Operational cheque lifecycle (Invoice Mode often settles via PDC clear,
        // not installment_id allocations — those remain AR/invoice allocations).
        $chequeByInstallment = re_lease_fs_operational_cheque_map($conn, $companyId, $leaseId);

        $seq = 0;
        foreach ($pendingRows as $pending) {
            $seq++;
            $row = $pending['row'];
            $amount = $pending['amount'];
            $paid = $pending['paid'];
            $id = $pending['id'];
            if ($paid <= 0.005 && isset($helperMap[$id]) && (float)$helperMap[$id] > $paid) {
                $paid = re_lease_fs_money_float($helperMap[$id]);
            }

            $instStatus = strtolower(trim((string)($row['status'] ?? '')));
            $cheque = $chequeByInstallment[$id] ?? null;
            $chequeStatus = $cheque ? strtolower(trim((string)($cheque['status'] ?? ''))) : '';
            $chequeAmount = $cheque ? re_lease_fs_money_float($cheque['cheque_amount'] ?? 0) : 0.0;
            $settlementSource = 'allocations';
            $settledByStatus = false;

            // Fully waived/cancelled/paid installments are settled operationally.
            if (in_array($instStatus, ['paid', 'waived', 'cancelled'], true) || !empty($row['paid_at'])) {
                $paid = max($paid, $amount);
                $settlementSource = 'installment_status';
                $settledByStatus = true;
            }

            // Cleared cheque covering this schedule row (Invoice Mode operational settlement).
            // Bounced/returned must NOT count as settlement.
            if (!$settledByStatus && $cheque && $chequeStatus === 'cleared') {
                $paid = max($paid, min($amount, $chequeAmount > 0.005 ? $chequeAmount : $amount));
                $settlementSource = 'cheque_cleared';
            } elseif (!$settledByStatus && $cheque && in_array($chequeStatus, ['bounced', 'returned'], true)) {
                $settlementSource = 'cheque_' . $chequeStatus;
            }

            $remaining = max(0.0, re_lease_fs_money_float($amount - $paid));
            // Authoritative settle threshold for Next Cheque / schedule consumers.
            if ($remaining <= 0.01) {
                $remaining = 0.0;
            }

            $installmentDate = (string)($row['installment_date'] ?? '');
            $lifecycle = re_lease_fs_tenant_cheque_lifecycle_status(
                $remaining,
                $paid,
                $chequeStatus,
                $instStatus,
                $installmentDate
            );

            $paymentId = null;
            if ($row['payment_id'] !== null && $row['payment_id'] !== '') {
                $paymentId = (int)$row['payment_id'];
            } elseif ($cheque && isset($cheque['payment_id']) && (int)$cheque['payment_id'] > 0) {
                $paymentId = (int)$cheque['payment_id'];
            }

            $base['installments'][] = [
                'layer' => 'operational',
                'id' => $id,
                'sequence' => $seq,
                'installment_date' => $installmentDate,
                'amount' => re_lease_fs_money_str($amount),
                'paid_amount' => re_lease_fs_money_str(min($amount, $paid)),
                'remaining_amount' => re_lease_fs_money_str($remaining),
                'status' => (string)($row['status'] ?? ''),
                'display_status' => $lifecycle,
                'cheque_number' => $cheque ? (string)($cheque['cheque_number'] ?? '') : null,
                'cheque_status' => $chequeStatus !== '' ? $chequeStatus : null,
                'settlement_source' => $settlementSource,
                'paid_at' => $row['paid_at'] !== null ? (string)$row['paid_at'] : null,
                'payment_id' => $paymentId,
                'notes' => $row['notes'] !== null ? (string)$row['notes'] : null,
            ];
        }

        $clearedCount = 0;
        $openCount = 0;
        foreach ($base['installments'] as $inst) {
            if (re_lease_fs_money_float($inst['remaining_amount'] ?? 0) <= 0.01) {
                $clearedCount++;
            } else {
                $openCount++;
            }
        }
        $base['cleared_cheque_count'] = $clearedCount;
        $base['open_cheque_count'] = $openCount;
        $base['schedule_complete'] = $base['installments'] !== [] && $openCount === 0;

        return $base;
    }
}

if (!function_exists('re_lease_fs_tenant_cheque_lifecycle_status')) {
    /**
     * Tenant-facing cheque lifecycle for chips/labels only.
     * Does not change settlement remaining amounts.
     */
    function re_lease_fs_tenant_cheque_lifecycle_status(
        float $remaining,
        float $paid,
        string $chequeStatus,
        string $installmentStatus,
        string $installmentDate
    ): string {
        if (in_array($chequeStatus, ['bounced', 'returned'], true)) {
            return $chequeStatus;
        }
        if ($remaining <= 0.01) {
            if (in_array($installmentStatus, ['cancelled', 'waived'], true)) {
                return $installmentStatus;
            }
            return 'cleared';
        }
        if ($paid > 0.01) {
            return 'partial';
        }
        if (in_array($chequeStatus, ['deposited', 'pending_deposit'], true)) {
            return 'pending_deposit';
        }
        $today = date('Y-m-d');
        if ($installmentDate !== '' && $installmentDate < $today) {
            return 'overdue';
        }
        if ($installmentDate !== '') {
            $soon = date('Y-m-d', strtotime('+14 days'));
            if ($installmentDate <= $soon) {
                return 'due_soon';
            }
            return 'upcoming';
        }
        return $chequeStatus !== '' ? $chequeStatus : 'pending';
    }
}

if (!function_exists('re_lease_fs_operational_cheque_map')) {
    /**
     * Map installment_id => latest operational cheque row for a lease.
     * Prefers re_post_dated_cheques; falls back to re_lease_cheques.
     *
     * @return array<int,array<string,mixed>>
     */
    function re_lease_fs_operational_cheque_map(PDO $conn, int $companyId, int $leaseId): array
    {
        $fromPdc = [];
        $fromLease = [];
        try {
            $st = $conn->prepare(
                "SELECT installment_id, cheque_number, cheque_amount, status, cleared_date, bounced_date, payment_id, bank_name
                 FROM re_post_dated_cheques
                 WHERE company_id = ? AND lease_id = ? AND installment_id IS NOT NULL
                 ORDER BY id ASC"
            );
            $st->execute([$companyId, $leaseId]);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
                $iid = (int)($row['installment_id'] ?? 0);
                if ($iid > 0) {
                    $fromPdc[$iid] = $row; // later id wins
                }
            }
        } catch (Throwable $e) {
            // Table may not exist on older DBs.
        }
        try {
            $st = $conn->prepare(
                "SELECT installment_id, cheque_number, cheque_amount, status, cleared_date, bounced_date, settlement_payment_id AS payment_id, bank_name
                 FROM re_lease_cheques
                 WHERE company_id = ? AND lease_id = ? AND installment_id IS NOT NULL
                 ORDER BY id ASC"
            );
            $st->execute([$companyId, $leaseId]);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
                $iid = (int)($row['installment_id'] ?? 0);
                if ($iid > 0) {
                    $fromLease[$iid] = $row; // later id wins
                }
            }
        } catch (Throwable $e) {
            // Table may not exist on older DBs.
        }
        // PDC is authoritative when present; fill gaps from lease_cheques.
        return $fromPdc + array_diff_key($fromLease, $fromPdc);
    }
}

if (!function_exists('re_lease_next_unsettled_installment')) {
    /**
     * Next operational cheque/installment for tenant surfaces.
     * Skips fully settled rows (remaining <= 0.01).
     * Prioritizes bounced/returned unsettled rows, then due date / sequence.
     *
     * @return array{next:?array<string,mixed>,installments:list<array<string,mixed>>}
     */
    function re_lease_next_unsettled_installment(PDO $conn, int $companyId, int $leaseId): array
    {
        $sched = re_lease_schedule_installments($conn, $companyId, $leaseId);
        $rows = $sched['installments'] ?? [];
        $bounced = null;
        $next = null;
        foreach ($rows as $row) {
            $remaining = re_lease_fs_money_float($row['remaining_amount'] ?? 0);
            if ($remaining <= 0.01) {
                continue;
            }
            $cs = strtolower((string)($row['cheque_status'] ?? $row['display_status'] ?? ''));
            if (in_array($cs, ['bounced', 'returned'], true)) {
                if ($bounced === null) {
                    $bounced = $row;
                }
                continue;
            }
            if ($next === null) {
                $next = $row;
            }
        }
        return [
            'next' => $bounced ?? $next,
            'installments' => $rows,
            'accounting_mode' => $sched['accounting_mode'] ?? 'legacy',
            'warning' => $sched['warning'] ?? '',
        ];
    }
}

if (!function_exists('re_lease_service_charge_rows')) {
    /**
     * @return array{
     *   accounting_mode: string,
     *   settlement_source: string,
     *   outstanding: string,
     *   service_charges: list<array<string,mixed>>
     * }
     */
    function re_lease_service_charge_rows(PDO $conn, int $companyId, int $leaseId): array
    {
        $ctx = re_lease_load_financial_context($conn, $companyId, $leaseId);
        if (!$ctx['ok']) {
            return [
                'accounting_mode' => 'legacy',
                'settlement_source' => 'none',
                'outstanding' => '0.00',
                'service_charges' => [],
                'error' => $ctx['error'] ?? 'invalid',
            ];
        }

        $companyId = (int)$ctx['company_id'];
        $leaseId = (int)$ctx['lease_id'];
        $mode = (string)$ctx['accounting_mode'];

        if ($mode === 'invoice') {
            $pack = re_lease_fs_im_billing_rows($conn, $companyId, $leaseId, 'service_charge');
            return [
                'accounting_mode' => 'invoice',
                'settlement_source' => 'invoice_mode_obligations',
                'outstanding' => re_lease_fs_money_str($pack['outstanding']),
                'service_charges' => $pack['rows'],
            ];
        }

        $pack = re_lease_fs_legacy_billing_outstanding($conn, $companyId, $leaseId, 'service_charge');
        return [
            'accounting_mode' => 'legacy',
            'settlement_source' => 'legacy_billing_allocations',
            'outstanding' => re_lease_fs_money_str($pack['outstanding']),
            'service_charges' => $pack['rows'],
        ];
    }
}

if (!function_exists('re_lease_penalty_rows')) {
    /**
     * @return array{
     *   accounting_mode: string,
     *   settlement_source: string,
     *   outstanding: string,
     *   penalties: list<array<string,mixed>>
     * }
     */
    function re_lease_penalty_rows(PDO $conn, int $companyId, int $leaseId): array
    {
        $ctx = re_lease_load_financial_context($conn, $companyId, $leaseId);
        if (!$ctx['ok']) {
            return [
                'accounting_mode' => 'legacy',
                'settlement_source' => 'none',
                'outstanding' => '0.00',
                'penalties' => [],
                'error' => $ctx['error'] ?? 'invalid',
            ];
        }

        $companyId = (int)$ctx['company_id'];
        $leaseId = (int)$ctx['lease_id'];
        $mode = (string)$ctx['accounting_mode'];

        if ($mode === 'invoice') {
            $pack = re_lease_fs_im_billing_rows($conn, $companyId, $leaseId, 'penalty');
            return [
                'accounting_mode' => 'invoice',
                'settlement_source' => 'invoice_mode_obligations',
                'outstanding' => re_lease_fs_money_str($pack['outstanding']),
                'penalties' => $pack['rows'],
            ];
        }

        $pack = re_lease_fs_legacy_billing_outstanding($conn, $companyId, $leaseId, 'penalty');
        return [
            'accounting_mode' => 'legacy',
            'settlement_source' => 'legacy_billing_allocations',
            'outstanding' => re_lease_fs_money_str($pack['outstanding']),
            'penalties' => $pack['rows'],
        ];
    }
}
