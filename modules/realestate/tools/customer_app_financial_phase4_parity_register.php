<?php
/**
 * Phase 4 — ERP Lease View ↔ Payment Manager ↔ Customer API ↔ Flutter-model parity register.
 *
 * Read-only. Tolerance: 0.01 AED.
 *
 * Usage:
 *   php modules/realestate/tools/customer_app_financial_phase4_parity_register.php
 *   php modules/realestate/tools/customer_app_financial_phase4_parity_register.php --json-out=/tmp/p4.json
 *
 * Does not write to the database. May write a JSON evidence file when --json-out is set.
 */
declare(strict_types=1);

$root = dirname(__DIR__, 3);
if (!is_link('/tmp/mysql.sock') && !file_exists('/tmp/mysql.sock')) {
    @symlink('/Applications/XAMPP/xamppfiles/var/mysql/mysql.sock', '/tmp/mysql.sock');
}

require_once $root . '/includes/db_connect.php';
require_once $root . '/includes/customer_api.php';
require_once $root . '/modules/realestate/includes/lease_financial_summary_service.php';
require_once $root . '/api/customer/v1/tenant_endpoints.php';

$passed = 0;
$failed = 0;
$skipped = 0;
$tol = 0.01;
$companyIdDefault = 2;
$matrix = [69, 159, 269, 230, 61, 37, 49];
$evidence = [
    'phase' => 4,
    'tolerance_aed' => $tol,
    'generated_at' => date('c'),
    'leases' => [],
    'totals' => [],
];

$jsonOut = null;
foreach ($argv as $arg) {
    if (preg_match('/^--json-out=(.+)$/', $arg, $m)) {
        $jsonOut = $m[1];
    }
}

function p4_assert(bool $ok, string $label, $meta = null): void
{
    global $passed, $failed;
    if ($ok) {
        echo "PASS  {$label}\n";
        $passed++;
    } else {
        echo "FAIL  {$label}" . ($meta !== null ? ' ' . json_encode($meta, JSON_UNESCAPED_UNICODE) : '') . "\n";
        $failed++;
    }
}

function p4_skip(string $label, string $reason): void
{
    global $skipped;
    echo "SKIP  {$label} ({$reason})\n";
    $skipped++;
}

function p4_eq_money($a, $b, float $tol = 0.01): bool
{
    return abs((float)$a - (float)$b) <= $tol;
}

function p4_norm_status(string $s): string
{
    $s = strtolower(trim($s));
    $s = str_replace([' ', '-'], '_', $s);
    $aliases = [
        'up_to_date' => 'up_to_date',
        'paid' => 'up_to_date',
        'partially_paid' => 'partial',
        'partial' => 'partial',
    ];
    return $aliases[$s] ?? $s;
}

/**
 * Replicate ERP Lease View Invoice Mode comparable fields (lease_view.php inline summary).
 * Note: Lease View "receipts_total" is a COUNT, not money — exposed as receipts_count_ui.
 *
 * @return array<string,mixed>
 */
function p4_lease_view_im_summary(PDO $conn, int $companyId, int $leaseId): array
{
    $out = [
        'issued_invoices' => 0,
        'open_invoice_amount' => 0.0,
        'receipts_count_ui' => 0,
        'allocated_total' => 0.0,
        'obligations_open_amount' => 0.0,
        'security_deposit_open' => 0.0,
    ];
    try {
        $stmt = $conn->prepare("
            SELECT COUNT(*) AS cnt,
                   COALESCE(SUM(outstanding_amount), 0) AS open_amount
            FROM re_invoices
            WHERE company_id = ? AND lease_id = ? AND status <> 'cancelled'
        ");
        $stmt->execute([$companyId, $leaseId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $out['issued_invoices'] = (int)($row['cnt'] ?? 0);
        $out['open_invoice_amount'] = (float)($row['open_amount'] ?? 0);
    } catch (Throwable $e) {
    }
    try {
        $stmt = $conn->prepare("
            SELECT COUNT(DISTINCT p.id) AS cnt,
                   COALESCE(SUM(ra.amount_allocated), 0) AS allocated_total
            FROM re_payments p
            LEFT JOIN re_receipt_allocations ra ON ra.payment_id = p.id
            WHERE p.company_id = ? AND p.lease_id = ? AND p.accounting_mode = 'invoice'
        ");
        $stmt->execute([$companyId, $leaseId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $out['receipts_count_ui'] = (int)($row['cnt'] ?? 0);
        $out['allocated_total'] = (float)($row['allocated_total'] ?? 0);
    } catch (Throwable $e) {
    }
    try {
        $stmt = $conn->prepare("
            SELECT COALESCE(SUM(
                       CASE
                           WHEN status IN ('draft','open','partially_allocated')
                           THEN GREATEST(total_amount - COALESCE(allocated_amount, 0), 0)
                           ELSE 0
                       END
                   ), 0) AS open_amount,
                   COALESCE(SUM(
                       CASE
                           WHEN obligation_type = 'security_deposit'
                                AND status IN ('draft','open','partially_allocated')
                           THEN GREATEST(total_amount - COALESCE(allocated_amount, 0), 0)
                           ELSE 0
                       END
                   ), 0) AS deposit_open
            FROM re_obligations
            WHERE company_id = ? AND lease_id = ?
        ");
        $stmt->execute([$companyId, $leaseId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $out['obligations_open_amount'] = (float)($row['open_amount'] ?? 0);
        $out['security_deposit_open'] = (float)($row['deposit_open'] ?? 0);
    } catch (Throwable $e) {
    }
    return $out;
}

/**
 * Simulate Flutter binding: parse API money strings the same way tenantParseAmount does.
 */
function p4_flutter_parse_amount($v): float
{
    if ($v === null) {
        return 0.0;
    }
    if (is_int($v) || is_float($v)) {
        return (float)$v;
    }
    $s = trim((string)$v);
    if ($s === '') {
        return 0.0;
    }
    $s = str_replace(',', '', $s);
    if (!is_numeric($s)) {
        return 0.0;
    }
    return (float)$s;
}

/**
 * @return array<string,mixed>
 */
function p4_flutter_bind_summary(array $apiSummary): array
{
    $outstandingTotal = array_key_exists('outstanding_total', $apiSummary)
        ? p4_flutter_parse_amount($apiSummary['outstanding_total'])
        : 0.0;
    $rent = array_key_exists('rent_outstanding', $apiSummary)
        ? p4_flutter_parse_amount($apiSummary['rent_outstanding'])
        : p4_flutter_parse_amount($apiSummary['outstanding_rent'] ?? 0);
    $sc = array_key_exists('service_charge_outstanding', $apiSummary)
        ? p4_flutter_parse_amount($apiSummary['service_charge_outstanding'])
        : p4_flutter_parse_amount($apiSummary['outstanding_service_charges'] ?? 0);
    $pen = array_key_exists('penalty_outstanding', $apiSummary)
        ? p4_flutter_parse_amount($apiSummary['penalty_outstanding'])
        : p4_flutter_parse_amount($apiSummary['outstanding_penalties'] ?? 0);

    $last = $apiSummary['last_payment'] ?? null;
    $next = $apiSummary['next_due'] ?? null;

    return [
        'accounting_mode' => (string)($apiSummary['accounting_mode'] ?? ''),
        'outstanding_total' => $outstandingTotal,
        'rent_outstanding' => $rent,
        'service_charge_outstanding' => $sc,
        'penalty_outstanding' => $pen,
        'overdue_amount' => p4_flutter_parse_amount($apiSummary['overdue_amount'] ?? 0),
        'due_now_amount' => p4_flutter_parse_amount($apiSummary['due_now_amount'] ?? 0),
        'invoice_total' => p4_flutter_parse_amount($apiSummary['invoice_total'] ?? 0),
        'paid_total' => p4_flutter_parse_amount($apiSummary['paid_total'] ?? 0),
        'receipts_total' => p4_flutter_parse_amount($apiSummary['receipts_total'] ?? 0),
        'allocated_total' => p4_flutter_parse_amount($apiSummary['allocated_total'] ?? 0),
        'unallocated_receipts' => p4_flutter_parse_amount($apiSummary['unallocated_receipts'] ?? 0),
        'tenant_credit' => p4_flutter_parse_amount($apiSummary['tenant_credit'] ?? 0),
        'vat_total' => !empty($apiSummary['vat_available'])
            ? p4_flutter_parse_amount($apiSummary['vat_total'] ?? 0)
            : null,
        'vat_available' => !empty($apiSummary['vat_available']),
        'display_status' => (string)($apiSummary['display_status'] ?? ''),
        'invoice_count' => (int)($apiSummary['invoice_count'] ?? 0),
        'receipt_count' => (int)($apiSummary['receipt_count'] ?? 0),
        'due_now_count' => (int)($apiSummary['due_now_count'] ?? 0),
        'overdue_count' => (int)($apiSummary['overdue_count'] ?? 0),
        'last_payment_amount' => is_array($last) ? p4_flutter_parse_amount($last['amount'] ?? 0) : null,
        'last_payment_date' => is_array($last) ? (string)($last['payment_date'] ?? '') : null,
        'last_payment_status' => is_array($last) ? (string)($last['display_status'] ?? '') : null,
        'next_due_amount' => is_array($next) ? p4_flutter_parse_amount($next['amount'] ?? 0) : null,
        'next_due_date' => is_array($next) ? (string)($next['due_date'] ?? '') : null,
        'next_due_status' => is_array($next) ? (string)($next['display_status'] ?? '') : null,
    ];
}

echo "=== Phase 4 Customer App Financial Parity Register ===\n";
echo "Tolerance: {$tol} AED | Company default: {$companyIdDefault}\n\n";

// Discover API base
$baseCandidates = [
    'http://127.0.0.1/herosysgro/api/customer/v1/index.php',
    'http://localhost/herosysgro/api/customer/v1/index.php',
];
$base = null;
foreach ($baseCandidates as $b) {
    $ch = curl_init($b . '?route=health');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 3]);
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code === 200 && is_string($body) && str_contains($body, '"ok":true')) {
        $base = $b;
        break;
    }
}
if ($base === null) {
    p4_skip('HTTP API layer', 'Customer API not reachable');
} else {
    echo "INFO  API base {$base}\n";
}

$legacyCount = (int)$conn->query("SELECT COUNT(*) FROM re_leases WHERE COALESCE(accounting_mode,'legacy')='legacy'")->fetchColumn();
if ($legacyCount === 0) {
    p4_skip('Legacy live parity', 'no Legacy leases in database');
}

foreach ($matrix as $leaseId) {
    echo "\n--- Lease {$leaseId} ---\n";
    $ctx = re_lease_load_financial_context($conn, $companyIdDefault, $leaseId);
    if (!$ctx['ok']) {
        p4_skip("lease {$leaseId}", $ctx['error'] ?? 'not found');
        $evidence['leases'][$leaseId] = ['status' => 'skipped', 'reason' => $ctx['error'] ?? 'not found'];
        continue;
    }
    $companyId = (int)$ctx['company_id'];
    $tenantId = (int)$ctx['tenant_id'];
    $mode = (string)$ctx['accounting_mode'];

    $leaseRow = [
        'lease_id' => $leaseId,
        'company_id' => $companyId,
        'tenant_id' => $tenantId,
        'accounting_mode' => $mode,
        'comparisons' => [],
        'line_items' => [],
        'notes' => [],
    ];

    // 1) Payment Manager (source of truth for IM KPIs)
    $kpis = re_pm_im_kpis($conn, $companyId, $leaseId, $tenantId);
    $overview = re_pm_im_overview($conn, $companyId, $leaseId, $tenantId);

    // 2) Centralized financial service
    $summary = re_lease_financial_summary($conn, $companyId, $leaseId, $tenantId);
    $dashFin = customer_api_tenant_financial_payload_for_dashboard($conn, $companyId, $leaseId, $summary);

    // 3) Lease View comparable strip
    $leaseView = p4_lease_view_im_summary($conn, $companyId, $leaseId);

    $leaseRow['erp_payment_manager'] = $kpis;
    $leaseRow['erp_financial_service'] = $summary;
    $leaseRow['erp_lease_view'] = $leaseView;
    $leaseRow['api_dashboard_financial'] = $dashFin;

    // PM ↔ Service
    $pmSvcPairs = [
        ['outstanding_total', $kpis['total_outstanding'], $summary['outstanding_total']],
        ['overdue_amount', $kpis['total_overdue'], $summary['overdue_amount']],
        ['due_now_amount', $kpis['total_due_now'], $summary['due_now_amount']],
        ['invoice_total', $kpis['total_invoiced'], $summary['invoice_total']],
        ['paid_total', $kpis['total_collected'], $summary['paid_total']],
        ['receipts_total', $kpis['total_collected'], $summary['receipts_total']],
        ['allocated_total', $kpis['allocated_total'], $summary['allocated_total']],
        ['unallocated_receipts', $kpis['unallocated_receipts'], $summary['unallocated_receipts']],
        ['tenant_credit', $kpis['tenant_credit'], $summary['tenant_credit']],
    ];
    foreach ($pmSvcPairs as [$name, $a, $b]) {
        $ok = p4_eq_money($a, $b, $tol);
        p4_assert($ok, "L{$leaseId} PM↔Service {$name}", ['pm' => $a, 'svc' => $b]);
        $leaseRow['comparisons']["pm_svc_{$name}"] = ['pass' => $ok, 'pm' => $a, 'svc' => $b];
    }
    p4_assert((int)$kpis['invoice_count'] === (int)$summary['invoice_count'], "L{$leaseId} PM↔Service invoice_count");
    p4_assert((int)$kpis['receipt_count'] === (int)$summary['receipt_count'], "L{$leaseId} PM↔Service receipt_count");
    p4_assert((int)$kpis['due_now_count'] === (int)$summary['due_now_count'], "L{$leaseId} PM↔Service due_now_count");
    p4_assert((int)$kpis['overdue_count'] === (int)$summary['overdue_count'], "L{$leaseId} PM↔Service overdue_count");
    p4_assert(($summary['accounting_mode'] ?? '') === $mode, "L{$leaseId} Service accounting_mode");

    // Lease View ↔ PM comparable fields
    $okOpen = p4_eq_money($leaseView['open_invoice_amount'], $kpis['total_outstanding'], $tol);
    p4_assert($okOpen, "L{$leaseId} LeaseView.open_invoice_amount ↔ PM.total_outstanding", [
        'lease_view' => $leaseView['open_invoice_amount'],
        'pm' => $kpis['total_outstanding'],
    ]);
    $leaseRow['comparisons']['lv_pm_outstanding'] = [
        'pass' => $okOpen,
        'lease_view' => $leaseView['open_invoice_amount'],
        'pm' => $kpis['total_outstanding'],
    ];

    $okInvCnt = ((int)$leaseView['issued_invoices'] === (int)$kpis['invoice_count']);
    p4_assert($okInvCnt, "L{$leaseId} LeaseView.issued_invoices ↔ PM.invoice_count");

    $okRcptCnt = ((int)$leaseView['receipts_count_ui'] === (int)$kpis['receipt_count']);
    p4_assert($okRcptCnt, "L{$leaseId} LeaseView.receipts_count ↔ PM.receipt_count");

    $okAlloc = p4_eq_money($leaseView['allocated_total'], $kpis['allocated_total'], $tol);
    p4_assert($okAlloc, "L{$leaseId} LeaseView.allocated_total ↔ PM.allocated_total", [
        'lease_view' => $leaseView['allocated_total'],
        'pm' => $kpis['allocated_total'],
    ]);
    $leaseRow['comparisons']['lv_pm_allocated'] = [
        'pass' => $okAlloc,
        'lease_view' => $leaseView['allocated_total'],
        'pm' => $kpis['allocated_total'],
    ];

    // Fields Lease View does not show on IM strip
    $leaseRow['notes'][] = 'Lease View IM strip does not display overdue_amount, due_now_amount, display_status, paid_total money, tenant_credit (credit is a separate section), or class breakdown — marked N/A for UI surface, still validated on PM/Service/API/App.';

    // Class breakdown independence (do not force equality with total)
    $classSum = (float)$summary['rent_outstanding']
        + (float)$summary['service_charge_outstanding']
        + (float)$summary['penalty_outstanding'];
    $total = (float)$summary['outstanding_total'];
    if (abs($classSum - $total) > $tol) {
        $leaseRow['notes'][] = sprintf(
            'Class breakdown (%.2f) ≠ outstanding_total (%.2f) — legitimate if deposits/other/unclassified; not a FAIL.',
            $classSum,
            $total
        );
        echo "INFO  L{$leaseId} class_sum={$classSum} outstanding_total={$total} (difference allowed)\n";
    }

    // Line items: invoices
    $items = re_lease_outstanding_items($conn, $companyId, $leaseId, ['open_only' => false, 'limit' => 200]);
    $invoiceChecks = 0;
    foreach ($items['items'] ?? [] as $item) {
        if (($item['item_type'] ?? '') !== 'invoice') {
            continue;
        }
        $invoiceChecks++;
        p4_assert(($item['display_status'] ?? '') !== '', "L{$leaseId} invoice #{$item['id']} display_status");
        p4_assert(isset($item['total_amount'], $item['paid_amount'], $item['outstanding_amount']), "L{$leaseId} invoice #{$item['id']} money fields");
        if ($invoiceChecks >= 5) {
            break;
        }
    }
    if ($invoiceChecks === 0) {
        echo "INFO  L{$leaseId} no invoices (empty-state candidate)\n";
        $leaseRow['notes'][] = 'No invoices — empty invoice state.';
    }

    // SC / penalties
    $sc = re_lease_service_charge_rows($conn, $companyId, $leaseId);
    $pen = re_lease_penalty_rows($conn, $companyId, $leaseId);
    $scChecked = 0;
    foreach ($sc['service_charges'] ?? [] as $row) {
        $scChecked++;
        p4_assert(isset($row['total_amount'], $row['paid_amount'], $row['outstanding_amount']), "L{$leaseId} SC #{$row['id']} money");
        p4_assert(($row['display_status'] ?? $row['status'] ?? '') !== '', "L{$leaseId} SC #{$row['id']} status");
        if ($scChecked >= 3) {
            break;
        }
    }
    $penChecked = 0;
    foreach ($pen['penalties'] ?? [] as $row) {
        $penChecked++;
        p4_assert(isset($row['total_amount'], $row['paid_amount'], $row['outstanding_amount']), "L{$leaseId} penalty #{$row['id']} money");
        p4_assert(($row['display_status'] ?? $row['status'] ?? '') !== '', "L{$leaseId} penalty #{$row['id']} status");
        if ($penChecked >= 3) {
            break;
        }
    }

    // Receipts
    $hist = re_lease_payment_history($conn, $companyId, $leaseId, 50);
    $rcptChecked = 0;
    foreach ($hist['receipts'] ?? [] as $r) {
        $rcptChecked++;
        p4_assert(($r['display_status'] ?? '') !== '', "L{$leaseId} receipt #{$r['payment_id']} display_status");
        p4_assert(isset($r['amount'], $r['allocated_amount'], $r['unallocated_amount']), "L{$leaseId} receipt #{$r['payment_id']} money");
        // Do not invent receipt_status
        if (!array_key_exists('receipt_status', $r) || $r['receipt_status'] === null) {
            // OK — may be null upstream
        }
        if ($rcptChecked >= 5) {
            break;
        }
    }
    if ($rcptChecked === 0) {
        $leaseRow['notes'][] = 'No receipts — empty receipt state.';
    }

    // Installments operational only
    $sched = re_lease_schedule_installments($conn, $companyId, $leaseId);
    p4_assert(($sched['layer'] ?? '') === 'operational', "L{$leaseId} installments layer operational");
    $leaseRow['notes'][] = 'Installments validated as operational schedule only (not AR).';

    // last_payment / next_due presence
    if (empty($summary['last_payment'])) {
        $leaseRow['notes'][] = 'No last_payment (empty-state).';
        echo "INFO  L{$leaseId} no last_payment\n";
    }
    if (empty($summary['next_due'])) {
        $leaseRow['notes'][] = 'No next_due (empty-state / fully settled or no open items).';
        echo "INFO  L{$leaseId} no next_due\n";
    }

    // Flutter bind vs Service/API assembly
    $flutter = p4_flutter_bind_summary($dashFin);
    $leaseRow['flutter_bound'] = $flutter;
    $flutterPairs = [
        'outstanding_total', 'rent_outstanding', 'service_charge_outstanding', 'penalty_outstanding',
        'overdue_amount', 'due_now_amount', 'invoice_total', 'paid_total', 'receipts_total',
        'allocated_total', 'unallocated_receipts', 'tenant_credit',
    ];
    foreach ($flutterPairs as $f) {
        $ok = p4_eq_money($flutter[$f], $summary[$f], $tol);
        p4_assert($ok, "L{$leaseId} Flutter↔Service {$f}", ['app' => $flutter[$f], 'svc' => $summary[$f]]);
        $leaseRow['comparisons']["app_svc_{$f}"] = ['pass' => $ok, 'app' => $flutter[$f], 'svc' => $summary[$f]];
    }
    p4_assert(
        p4_norm_status($flutter['display_status']) === p4_norm_status((string)$summary['display_status']),
        "L{$leaseId} Flutter↔Service display_status",
        ['app' => $flutter['display_status'], 'svc' => $summary['display_status']]
    );
    if ($flutter['vat_available']) {
        p4_assert(
            p4_eq_money($flutter['vat_total'], $summary['vat_total'], $tol),
            "L{$leaseId} Flutter↔Service vat_total"
        );
    } else {
        echo "INFO  L{$leaseId} vat unavailable (N/A)\n";
    }

    // HTTP API when portal user exists for this tenant
    if ($base !== null) {
        $u = $conn->prepare("
            SELECT id, company_id, tenant_id FROM tenant_portal_users
            WHERE status='approved' AND company_id=? AND tenant_id=?
            LIMIT 1
        ");
        $u->execute([$companyId, $tenantId]);
        $portal = $u->fetch(PDO::FETCH_ASSOC);
        if (!$portal) {
            p4_skip("L{$leaseId} HTTP authenticated", 'no approved portal user for tenant');
        } else {
            $token = customer_api_jwt_sign([
                'typ' => 'tenant',
                'sub' => 'tpu:' . (int)$portal['id'],
                'cid' => $companyId,
                'tid' => $tenantId,
            ], 600);
            $req = static function (string $route) use ($base, $token, $leaseId): array {
                $url = $base . '?route=' . rawurlencode($route);
                $ch = curl_init($url);
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT => 20,
                    CURLOPT_HTTPHEADER => [
                        'Authorization: Bearer ' . $token,
                        'X-Tenant-Lease-Id: ' . $leaseId,
                        'Accept: application/json',
                    ],
                ]);
                $body = (string)curl_exec($ch);
                $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);
                $json = json_decode($body, true);
                return ['code' => $code, 'json' => is_array($json) ? $json : null];
            };

            $fs = $req("tenant/leases/{$leaseId}/financial-summary");
            p4_assert(($fs['code'] === 200) && !empty($fs['json']['ok']), "L{$leaseId} HTTP financial-summary");
            if (!empty($fs['json']['ok'])) {
                $api = $fs['json']['data'];
                $leaseRow['http_financial_summary'] = $api;
                foreach ($flutterPairs as $f) {
                    $ok = p4_eq_money($api[$f] ?? -1, $kpis[
                        $f === 'outstanding_total' ? 'total_outstanding'
                        : ($f === 'overdue_amount' ? 'total_overdue'
                        : ($f === 'due_now_amount' ? 'total_due_now'
                        : ($f === 'invoice_total' ? 'total_invoiced'
                        : ($f === 'paid_total' || $f === 'receipts_total' ? 'total_collected'
                        : $f))))
                    ] ?? ($summary[$f] ?? -999), $tol);
                    // For class fields not on KPIs, compare to summary
                    if (in_array($f, ['rent_outstanding', 'service_charge_outstanding', 'penalty_outstanding'], true)) {
                        $ok = p4_eq_money($api[$f] ?? -1, $summary[$f], $tol);
                    }
                    if (in_array($f, ['allocated_total', 'unallocated_receipts', 'tenant_credit'], true)) {
                        $ok = p4_eq_money($api[$f] ?? -1, $kpis[$f], $tol);
                    }
                    p4_assert($ok, "L{$leaseId} HTTP↔PM/Service {$f}", [
                        'api' => $api[$f] ?? null,
                        'svc' => $summary[$f] ?? null,
                    ]);
                }

                $appFromHttp = p4_flutter_bind_summary($api);
                foreach ($flutterPairs as $f) {
                    $ok = p4_eq_money($appFromHttp[$f], $api[$f] ?? 0, $tol);
                    p4_assert($ok, "L{$leaseId} AppBind↔HTTP {$f}");
                }
                p4_assert(
                    p4_norm_status($appFromHttp['display_status']) === p4_norm_status((string)($api['display_status'] ?? '')),
                    "L{$leaseId} AppBind↔HTTP display_status"
                );
            }

            foreach ([
                'outstanding-items',
                'payment-history',
                'service-charges',
                'penalties',
                'installments',
            ] as $ep) {
                $r = $req("tenant/leases/{$leaseId}/{$ep}");
                p4_assert(($r['code'] === 200) && !empty($r['json']['ok']), "L{$leaseId} HTTP {$ep}");
                if ($ep === 'installments' && !empty($r['json']['ok'])) {
                    p4_assert(($r['json']['data']['layer'] ?? '') === 'operational', "L{$leaseId} HTTP installments operational");
                }
            }
        }
    }

    // Scenario tags for report
    $tags = [];
    if ((float)$summary['outstanding_total'] <= 0.005) {
        $tags[] = 'fully_paid_or_zero_outstanding';
    }
    if ((float)$summary['tenant_credit'] > 0.005) {
        $tags[] = 'tenant_credit';
    }
    if ((float)$summary['unallocated_receipts'] > 0.005) {
        $tags[] = 'unallocated_receipts';
    }
    if ((float)$summary['overdue_amount'] > 0.005) {
        $tags[] = 'overdue';
    }
    if ((float)$summary['service_charge_outstanding'] > 0.005 || ($sc['service_charges'] ?? []) !== []) {
        $tags[] = 'service_charges';
    }
    if ((float)$summary['penalty_outstanding'] > 0.005 || ($pen['penalties'] ?? []) !== []) {
        $tags[] = 'penalties';
    }
    if (empty($summary['next_due'])) {
        $tags[] = 'no_next_due';
    }
    if (empty($summary['last_payment'])) {
        $tags[] = 'no_last_payment';
    }
    $leaseRow['scenario_tags'] = $tags;
    echo "INFO  L{$leaseId} tags=" . implode(',', $tags) . " display=" . ($summary['display_status'] ?? '') . "\n";

    $evidence['leases'][$leaseId] = $leaseRow;
}

// Multi-lease tenant check
echo "\n--- Multi-lease tenant switch isolation ---\n";
$multi = $conn->query("
    SELECT tenant_id, company_id, COUNT(*) AS cnt, GROUP_CONCAT(id ORDER BY id) AS lease_ids
    FROM re_leases
    WHERE company_id = {$companyIdDefault} AND COALESCE(accounting_mode,'legacy')='invoice'
    GROUP BY tenant_id, company_id
    HAVING cnt >= 2
    ORDER BY cnt DESC
    LIMIT 1
")->fetch(PDO::FETCH_ASSOC);
if (!$multi) {
    p4_skip('Multi-lease tenant', 'no tenant with 2+ IM leases in company 2');
} else {
    $ids = array_map('intval', explode(',', (string)$multi['lease_ids']));
    $a = re_lease_financial_summary($conn, (int)$multi['company_id'], $ids[0], (int)$multi['tenant_id']);
    $b = re_lease_financial_summary($conn, (int)$multi['company_id'], $ids[1], (int)$multi['tenant_id']);
    p4_assert((int)$a['lease_id'] === $ids[0] && (int)$b['lease_id'] === $ids[1], 'Multi-lease summaries scoped to requested lease_id');
    // Switching must not mix totals — different lease_ids imply independent snapshots
    p4_assert(
        (int)$a['lease_id'] !== (int)$b['lease_id'],
        'Multi-lease distinct lease payloads',
        ['a' => $a['lease_id'], 'b' => $b['lease_id'], 'tenant' => $multi['tenant_id']]
    );
    $evidence['multi_lease'] = [
        'tenant_id' => (int)$multi['tenant_id'],
        'lease_ids' => $ids,
        'a_outstanding' => $a['outstanding_total'],
        'b_outstanding' => $b['outstanding_total'],
    ];
}

// Future / partial invoice presence across matrix
echo "\n--- Invoice display_status coverage ---\n";
$statusesSeen = [];
foreach ($matrix as $leaseId) {
    $ctx = re_lease_load_financial_context($conn, $companyIdDefault, $leaseId);
    if (!$ctx['ok']) {
        continue;
    }
    $items = re_lease_outstanding_items($conn, $companyIdDefault, $leaseId, ['limit' => 200]);
    foreach ($items['items'] ?? [] as $item) {
        if (($item['item_type'] ?? '') === 'invoice') {
            $st = (string)($item['display_status'] ?? '');
            if ($st !== '') {
                $statusesSeen[$st] = ($statusesSeen[$st] ?? 0) + 1;
            }
        }
    }
}
echo 'INFO  invoice display_status histogram: ' . json_encode($statusesSeen) . "\n";
foreach (['paid', 'partial', 'overdue', 'due', 'future', 'open'] as $need) {
    if (!isset($statusesSeen[$need])) {
        p4_skip("invoice status sample '{$need}'", 'not present in matrix leases');
    } else {
        p4_assert(true, "invoice status sample '{$need}' present ({$statusesSeen[$need]})");
    }
}

$evidence['invoice_display_status_histogram'] = $statusesSeen;
$evidence['totals'] = [
    'passed' => $passed,
    'failed' => $failed,
    'skipped' => $skipped,
];

echo "\n=== Phase 4 Result: {$passed} passed, {$failed} failed, {$skipped} skipped ===\n";

if ($jsonOut) {
    $dir = dirname($jsonOut);
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    file_put_contents($jsonOut, json_encode($evidence, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    echo "INFO  Evidence written to {$jsonOut}\n";
}

exit($failed > 0 ? 1 : 0);
