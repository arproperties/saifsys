<?php
/**
 * Invoice Mode Payment Manager — read-only financial overview helpers.
 *
 * Reuses official invoice / receipt / allocation totals. No writes.
 */
declare(strict_types=1);

require_once __DIR__ . '/payment_allocation_helper.php';
require_once __DIR__ . '/security_deposit_helper.php';

if (!function_exists('re_pm_im_money')) {
    function re_pm_im_money($value): float
    {
        return round((float)$value, 2);
    }
}

if (!function_exists('re_pm_im_display_status')) {
    /**
     * Document status for Payment Manager tables (badge + text).
     */
    function re_pm_im_display_status(array $invoice, string $today): string
    {
        $status = strtolower((string)($invoice['status'] ?? ''));
        if (in_array($status, ['cancelled', 'void', 'voided'], true)) {
            return 'cancelled';
        }
        $outstanding = (float)($invoice['outstanding_amount'] ?? 0);
        $dueDate = (string)($invoice['due_date'] ?? '');
        if ($outstanding <= 0.005) {
            return 'paid';
        }
        if ($status === 'partial' || ((float)($invoice['paid_amount'] ?? 0) > 0.005 && $outstanding > 0.005)) {
            if ($dueDate !== '' && $dueDate < $today) {
                return 'overdue';
            }
            return 'partial';
        }
        if ($dueDate !== '' && $dueDate < $today) {
            return 'overdue';
        }
        if ($dueDate !== '' && $dueDate > $today) {
            return 'future';
        }
        return $status !== '' ? $status : 'sent';
    }
}

if (!function_exists('re_pm_im_kpis')) {
    /**
     * Official IM KPI strip for a lease. Fail-closed when company/lease missing.
     *
     * @return array{
     *   total_invoiced: float,
     *   total_outstanding: float,
     *   total_due_now: float,
     *   total_overdue: float,
     *   total_collected: float,
     *   allocated_total: float,
     *   unallocated_receipts: float,
     *   tenant_credit: float,
     *   invoice_count: int,
     *   receipt_count: int,
     *   due_now_count: int,
     *   overdue_count: int
     * }
     */
    function re_pm_im_kpis(PDO $conn, int $companyId, int $leaseId, int $tenantId = 0): array
    {
        $empty = [
            'total_invoiced' => 0.0,
            'total_outstanding' => 0.0,
            'total_due_now' => 0.0,
            'total_overdue' => 0.0,
            'total_collected' => 0.0,
            'allocated_total' => 0.0,
            'unallocated_receipts' => 0.0,
            'tenant_credit' => 0.0,
            'invoice_count' => 0,
            'receipt_count' => 0,
            'due_now_count' => 0,
            'overdue_count' => 0,
        ];
        if ($companyId <= 0 || $leaseId <= 0) {
            return $empty;
        }

        $out = $empty;
        try {
            $stmt = $conn->prepare("
                SELECT
                    COUNT(*) AS invoice_count,
                    COALESCE(SUM(total_amount), 0) AS total_invoiced,
                    COALESCE(SUM(outstanding_amount), 0) AS total_outstanding,
                    COALESCE(SUM(CASE
                        WHEN outstanding_amount > 0.005 AND due_date <= CURDATE()
                        THEN outstanding_amount ELSE 0 END), 0) AS total_due_now,
                    COALESCE(SUM(CASE
                        WHEN outstanding_amount > 0.005 AND due_date < CURDATE()
                        THEN outstanding_amount ELSE 0 END), 0) AS total_overdue,
                    COALESCE(SUM(CASE
                        WHEN outstanding_amount > 0.005 AND due_date <= CURDATE()
                        THEN 1 ELSE 0 END), 0) AS due_now_count,
                    COALESCE(SUM(CASE
                        WHEN outstanding_amount > 0.005 AND due_date < CURDATE()
                        THEN 1 ELSE 0 END), 0) AS overdue_count
                FROM re_invoices
                WHERE company_id = ? AND lease_id = ? AND status <> 'cancelled'
            ");
            $stmt->execute([$companyId, $leaseId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
            $out['invoice_count'] = (int)($row['invoice_count'] ?? 0);
            $out['total_invoiced'] = re_pm_im_money($row['total_invoiced'] ?? 0);
            $out['total_outstanding'] = re_pm_im_money($row['total_outstanding'] ?? 0);
            $out['total_due_now'] = re_pm_im_money($row['total_due_now'] ?? 0);
            $out['total_overdue'] = re_pm_im_money($row['total_overdue'] ?? 0);
            $out['due_now_count'] = (int)($row['due_now_count'] ?? 0);
            $out['overdue_count'] = (int)($row['overdue_count'] ?? 0);
        } catch (Throwable $e) {
            // leave zeros
        }

        try {
            $stmt = $conn->prepare("
                SELECT COUNT(*) AS receipt_count,
                       COALESCE(SUM(amount), 0) AS total_collected
                FROM re_payments
                WHERE company_id = ? AND lease_id = ? AND accounting_mode = 'invoice'
            ");
            $stmt->execute([$companyId, $leaseId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
            $out['receipt_count'] = (int)($row['receipt_count'] ?? 0);
            $out['total_collected'] = re_pm_im_money($row['total_collected'] ?? 0);
        } catch (Throwable $e) {
        }

        try {
            $stmt = $conn->prepare("
                SELECT COALESCE(SUM(amount_allocated), 0)
                FROM re_receipt_allocations
                WHERE company_id = ? AND lease_id = ?
            ");
            $stmt->execute([$companyId, $leaseId]);
            $out['allocated_total'] = re_pm_im_money($stmt->fetchColumn());
        } catch (Throwable $e) {
        }

        $out['unallocated_receipts'] = re_pm_im_money(max(0.0, $out['total_collected'] - $out['allocated_total']));

        if ($tenantId > 0) {
            $out['tenant_credit'] = re_pm_im_money(get_tenant_credit_balance($conn, $tenantId, $companyId));
        }

        return $out;
    }
}

if (!function_exists('re_pm_im_invoice_class')) {
    function re_pm_im_invoice_class(?string $obligationType): string
    {
        $type = strtolower(trim((string)$obligationType));
        if ($type === 'rent') {
            return 'rent';
        }
        if ($type === 'service') {
            return 'service';
        }
        if ($type === 'security_deposit') {
            return 'deposit';
        }
        return 'other';
    }
}

if (!function_exists('re_pm_im_service_kind')) {
    /**
     * Distinguish lease chiller/AMC service invoices from Extra Service Charge invoices.
     */
    function re_pm_im_service_kind(?string $sourceType): string
    {
        return strtolower(trim((string)$sourceType)) === 'billing_item' ? 'extra_service' : 'lease_service';
    }
}

if (!function_exists('re_pm_im_invoice_description')) {
    /**
     * Human-readable Description for Payment Manager invoice tables.
     */
    function re_pm_im_invoice_description(array $row): string
    {
        $type = strtolower(trim((string)($row['obligation_type'] ?? '')));
        $raw = trim((string)($row['line_description'] ?? ''));
        if ($raw !== '' && strcasecmp($raw, $type) === 0) {
            $raw = ''; // ignore useless fallback like description="service"
        }
        $chargeName = trim((string)($row['service_charge_name'] ?? ''));
        $kind = re_pm_im_service_kind($row['source_type'] ?? null);

        if ($type === 'service' && $kind === 'extra_service') {
            $name = $chargeName !== '' ? $chargeName : ($raw !== '' ? $raw : 'Extra service');
            return 'Extra service: ' . $name;
        }
        if ($type === 'service') {
            if ($raw !== '') {
                return 'Lease service: ' . $raw;
            }
            return 'Lease service (chiller / AMC)';
        }
        if ($type === 'admin_fee') {
            return $raw !== '' ? $raw : 'Admin fee';
        }
        if ($type === 'other') {
            return $raw !== '' ? $raw : 'Other fee';
        }
        if ($raw !== '') {
            return $raw;
        }
        return $type !== '' ? str_replace('_', ' ', $type) : '—';
    }
}

if (!function_exists('re_pm_im_invoices_by_class')) {
    /**
     * Batched invoice list grouped by rent / service / other / deposit.
     * Caps display rows at $limit (default 50); totals still cover all non-cancelled.
     *
     * @return array{
     *   rent: list<array<string,mixed>>,
     *   service: list<array<string,mixed>>,
     *   lease_service: list<array<string,mixed>>,
     *   extra_service: list<array<string,mixed>>,
     *   other: list<array<string,mixed>>,
     *   deposit: list<array<string,mixed>>,
     *   totals: array<string,array{invoiced:float,outstanding:float,paid:float,count:int}>,
     *   truncated: bool,
     *   total_count: int
     * }
     */
    function re_pm_im_invoices_by_class(PDO $conn, int $companyId, int $leaseId, int $limit = 50): array
    {
        $groups = [
            'rent' => [],
            'service' => [],
            'lease_service' => [],
            'extra_service' => [],
            'other' => [],
            'deposit' => [],
        ];
        $totals = [
            'rent' => ['invoiced' => 0.0, 'outstanding' => 0.0, 'paid' => 0.0, 'count' => 0],
            'service' => ['invoiced' => 0.0, 'outstanding' => 0.0, 'paid' => 0.0, 'count' => 0],
            'lease_service' => ['invoiced' => 0.0, 'outstanding' => 0.0, 'paid' => 0.0, 'count' => 0],
            'extra_service' => ['invoiced' => 0.0, 'outstanding' => 0.0, 'paid' => 0.0, 'count' => 0],
            'other' => ['invoiced' => 0.0, 'outstanding' => 0.0, 'paid' => 0.0, 'count' => 0],
            'deposit' => ['invoiced' => 0.0, 'outstanding' => 0.0, 'paid' => 0.0, 'count' => 0],
        ];
        $result = [
            'rent' => [],
            'service' => [],
            'lease_service' => [],
            'extra_service' => [],
            'other' => [],
            'deposit' => [],
            'totals' => $totals,
            'truncated' => false,
            'total_count' => 0,
        ];
        if ($companyId <= 0 || $leaseId <= 0) {
            return $result;
        }

        $today = date('Y-m-d');
        $rows = [];
        try {
            // One row per invoice; pick primary obligation type (prefer rent, then service).
            // CAST descriptions to one collation — mixed utf8mb4 collations break MAX() and
            // previously forced a fallback that only showed obligation_type ("service").
            $stmt = $conn->prepare("
                SELECT
                    i.id,
                    i.invoice_number,
                    i.invoice_date,
                    i.due_date,
                    i.status,
                    i.total_amount,
                    i.paid_amount,
                    i.outstanding_amount,
                    COALESCE(
                        MAX(CASE WHEN o.obligation_type = 'rent' THEN o.obligation_type END),
                        MAX(CASE WHEN o.obligation_type = 'service' THEN o.obligation_type END),
                        MAX(CASE WHEN o.obligation_type = 'security_deposit' THEN o.obligation_type END),
                        MAX(o.obligation_type)
                    ) AS obligation_type,
                    MAX(CASE WHEN o.source_type = 'billing_item' THEN 'billing_item' ELSE o.source_type END) AS source_type,
                    MAX(
                        CAST(
                            COALESCE(
                                NULLIF(TRIM(o.description), ''),
                                NULLIF(TRIM(ii.item_name), ''),
                                NULLIF(TRIM(ii.item_description), '')
                            ) AS CHAR CHARACTER SET utf8mb4
                        ) COLLATE utf8mb4_unicode_ci
                    ) AS line_description,
                    MAX(
                        CAST(NULLIF(TRIM(sc.charge_name), '') AS CHAR CHARACTER SET utf8mb4)
                        COLLATE utf8mb4_unicode_ci
                    ) AS service_charge_name
                FROM re_invoices i
                LEFT JOIN re_invoice_items ii
                    ON ii.invoice_id = i.id AND ii.company_id = i.company_id
                LEFT JOIN re_obligations o
                    ON o.id = ii.obligation_id AND o.company_id = i.company_id
                LEFT JOIN re_billing_items bi
                    ON bi.id = o.source_id AND o.source_type = 'billing_item' AND bi.company_id = o.company_id
                LEFT JOIN re_service_charges sc
                    ON sc.id = bi.service_charge_id AND sc.company_id = bi.company_id
                WHERE i.company_id = ? AND i.lease_id = ? AND i.status <> 'cancelled'
                GROUP BY i.id, i.invoice_number, i.invoice_date, i.due_date, i.status,
                         i.total_amount, i.paid_amount, i.outstanding_amount
                ORDER BY i.due_date ASC, i.id ASC
            ");
            $stmt->execute([$companyId, $leaseId]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            // Fallback without description joins if schema/collation still differs
            try {
                $stmt = $conn->prepare("
                    SELECT
                        i.id,
                        i.invoice_number,
                        i.invoice_date,
                        i.due_date,
                        i.status,
                        i.total_amount,
                        i.paid_amount,
                        i.outstanding_amount,
                        COALESCE(
                            MAX(CASE WHEN o.obligation_type = 'rent' THEN o.obligation_type END),
                            MAX(CASE WHEN o.obligation_type = 'service' THEN o.obligation_type END),
                            MAX(CASE WHEN o.obligation_type = 'security_deposit' THEN o.obligation_type END),
                            MAX(o.obligation_type)
                        ) AS obligation_type,
                        MAX(CASE WHEN o.source_type = 'billing_item' THEN 'billing_item' ELSE o.source_type END) AS source_type,
                        MAX(o.obligation_type) AS line_description,
                        NULL AS service_charge_name
                    FROM re_invoices i
                    LEFT JOIN re_invoice_items ii
                        ON ii.invoice_id = i.id AND ii.company_id = i.company_id
                    LEFT JOIN re_obligations o
                        ON o.id = ii.obligation_id AND o.company_id = i.company_id
                    WHERE i.company_id = ? AND i.lease_id = ? AND i.status <> 'cancelled'
                    GROUP BY i.id, i.invoice_number, i.invoice_date, i.due_date, i.status,
                             i.total_amount, i.paid_amount, i.outstanding_amount
                    ORDER BY i.due_date ASC, i.id ASC
                ");
                $stmt->execute([$companyId, $leaseId]);
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            } catch (Throwable $e2) {
                return $result;
            }
        }

        $result['total_count'] = count($rows);
        $result['truncated'] = $result['total_count'] > $limit;
        $shown = 0;

        foreach ($rows as $row) {
            $class = re_pm_im_invoice_class($row['obligation_type'] ?? null);
            $paid = re_pm_im_money($row['paid_amount'] ?? 0);
            if ($paid <= 0.005) {
                $paid = re_pm_im_money(max(0, (float)($row['total_amount'] ?? 0) - (float)($row['outstanding_amount'] ?? 0)));
            }
            $row['paid_amount'] = $paid;
            $row['display_status'] = re_pm_im_display_status($row, $today);
            $row['invoice_class'] = $class;
            $row['service_kind'] = ($class === 'service')
                ? re_pm_im_service_kind($row['source_type'] ?? null)
                : '';
            $row['display_description'] = re_pm_im_invoice_description($row);

            $totals[$class]['invoiced'] = re_pm_im_money($totals[$class]['invoiced'] + (float)$row['total_amount']);
            $totals[$class]['outstanding'] = re_pm_im_money($totals[$class]['outstanding'] + (float)$row['outstanding_amount']);
            $totals[$class]['paid'] = re_pm_im_money($totals[$class]['paid'] + $paid);
            $totals[$class]['count']++;

            if ($class === 'service') {
                $kind = (string)$row['service_kind'];
                $totals[$kind]['invoiced'] = re_pm_im_money($totals[$kind]['invoiced'] + (float)$row['total_amount']);
                $totals[$kind]['outstanding'] = re_pm_im_money($totals[$kind]['outstanding'] + (float)$row['outstanding_amount']);
                $totals[$kind]['paid'] = re_pm_im_money($totals[$kind]['paid'] + $paid);
                $totals[$kind]['count']++;
            }

            if ($shown < $limit) {
                $groups[$class][] = $row;
                if ($class === 'service') {
                    $groups[(string)$row['service_kind']][] = $row;
                }
                $shown++;
            }
        }

        $result['rent'] = $groups['rent'];
        $result['service'] = $groups['service'];
        $result['lease_service'] = $groups['lease_service'];
        $result['extra_service'] = $groups['extra_service'];
        $result['other'] = $groups['other'];
        $result['deposit'] = $groups['deposit'];
        $result['totals'] = $totals;
        return $result;
    }
}

if (!function_exists('re_pm_im_recent_receipts')) {
    /**
     * Recent IM receipts with allocation summary + recent allocation lines.
     *
     * @return array{
     *   receipts: list<array<string,mixed>>,
     *   allocations: list<array<string,mixed>>
     * }
     */
    function re_pm_im_recent_receipts(PDO $conn, int $companyId, int $leaseId, int $limit = 25): array
    {
        $out = ['receipts' => [], 'allocations' => []];
        if ($companyId <= 0 || $leaseId <= 0) {
            return $out;
        }
        $limit = max(1, min(100, $limit));

        try {
            $stmt = $conn->prepare("
                SELECT p.id, p.receipt_number, p.payment_date, p.cleared_date, p.amount, p.payment_method,
                       p.reference_number, p.allocation_status,
                       COALESCE(SUM(ra.amount_allocated), 0) AS allocated_amount,
                       COALESCE(SUM(CASE WHEN ra.target_type = 'tenant_credit' THEN ra.amount_allocated ELSE 0 END), 0) AS tenant_credit_amount
                FROM re_payments p
                LEFT JOIN re_receipt_allocations ra ON ra.payment_id = p.id AND ra.company_id = p.company_id
                WHERE p.company_id = ? AND p.lease_id = ? AND p.accounting_mode = 'invoice'
                GROUP BY p.id
                ORDER BY COALESCE(p.cleared_date, p.payment_date) DESC, p.id DESC
                LIMIT {$limit}
            ");
            $stmt->execute([$companyId, $leaseId]);
            $receipts = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            foreach ($receipts as &$r) {
                $amount = re_pm_im_money($r['amount'] ?? 0);
                $allocated = re_pm_im_money($r['allocated_amount'] ?? 0);
                $r['amount'] = $amount;
                $r['allocated_amount'] = $allocated;
                $r['tenant_credit_amount'] = re_pm_im_money($r['tenant_credit_amount'] ?? 0);
                $r['unallocated_amount'] = re_pm_im_money(max(0.0, $amount - $allocated));
            }
            unset($r);
            $out['receipts'] = $receipts;
        } catch (Throwable $e) {
            $out['receipts'] = [];
        }

        try {
            $stmt = $conn->prepare("
                SELECT ra.id, ra.payment_id, ra.target_type, ra.amount_allocated, ra.created_at,
                       ra.invoice_id, ra.obligation_id,
                       p.receipt_number, p.payment_date, p.cleared_date,
                       i.invoice_number,
                       o.obligation_type, o.description AS obligation_description
                FROM re_receipt_allocations ra
                JOIN re_payments p ON p.id = ra.payment_id AND p.company_id = ra.company_id
                LEFT JOIN re_invoices i ON i.id = ra.invoice_id AND i.company_id = ra.company_id
                LEFT JOIN re_obligations o ON o.id = ra.obligation_id AND o.company_id = ra.company_id
                WHERE ra.company_id = ? AND ra.lease_id = ?
                ORDER BY ra.id DESC
                LIMIT {$limit}
            ");
            $stmt->execute([$companyId, $leaseId]);
            $out['allocations'] = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            $out['allocations'] = [];
        }

        return $out;
    }
}

if (!function_exists('re_pm_im_overview')) {
    /**
     * Full IM Payment Manager payload for one lease.
     *
     * @return array<string,mixed>
     */
    function re_pm_im_overview(PDO $conn, int $companyId, int $leaseId, int $tenantId = 0): array
    {
        return [
            'kpis' => re_pm_im_kpis($conn, $companyId, $leaseId, $tenantId),
            'invoices' => re_pm_im_invoices_by_class($conn, $companyId, $leaseId, 50),
            'receipts' => re_pm_im_recent_receipts($conn, $companyId, $leaseId, 25),
            'deposit' => re_sd_summary($conn, $companyId, $leaseId),
        ];
    }
}
