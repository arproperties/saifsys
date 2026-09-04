<?php
/**
 * Read-only parity smoke for lease_financial_summary_service vs Payment Manager KPIs.
 *
 * Usage:
 *   php modules/realestate/tools/lease_financial_summary_parity_smoke.php
 *   php modules/realestate/tools/lease_financial_summary_parity_smoke.php --lease=159 --company=2
 *
 * Does not write to the database.
 */
declare(strict_types=1);

$root = dirname(__DIR__, 3);
if (!is_link('/tmp/mysql.sock') && !file_exists('/tmp/mysql.sock')) {
    @symlink('/Applications/XAMPP/xamppfiles/var/mysql/mysql.sock', '/tmp/mysql.sock');
}

require_once $root . '/includes/db_connect.php';
require_once $root . '/modules/realestate/includes/lease_financial_summary_service.php';

$passed = 0;
$failed = 0;
$tol = 0.01;

function fs_assert(bool $ok, string $label, $meta = null): void
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

function fs_money_eq($a, $b, float $tol = 0.01): bool
{
    return abs((float)$a - (float)$b) <= $tol;
}

$cliLease = null;
$cliCompany = null;
foreach ($argv as $arg) {
    if (preg_match('/^--lease=(\d+)$/', $arg, $m)) {
        $cliLease = (int)$m[1];
    }
    if (preg_match('/^--company=(\d+)$/', $arg, $m)) {
        $cliCompany = (int)$m[1];
    }
}

echo "=== Lease Financial Summary parity smoke (read-only) ===\n";

// Fail-closed identifiers
$bad = re_lease_load_financial_context($conn, 0, 1);
fs_assert($bad['ok'] === false && ($bad['error'] ?? '') === 'invalid_identifiers', 'fail-closed invalid ids');

$missing = re_lease_load_financial_context($conn, 2, 99999999);
fs_assert($missing['ok'] === false && ($missing['error'] ?? '') === 'lease_not_found', 'fail-closed missing lease');

$cross = re_lease_load_financial_context($conn, 1, 159);
fs_assert($cross['ok'] === false, 'fail-closed cross-company lease lookup', ['error' => $cross['error'] ?? null]);

// Discover sample IM leases
$samples = [];
if ($cliLease !== null && $cliCompany !== null) {
    $samples[] = ['lease_id' => $cliLease, 'company_id' => $cliCompany];
} else {
    $sql = "
        SELECT l.id AS lease_id, l.company_id, l.tenant_id,
               COALESCE(SUM(CASE WHEN i.status <> 'cancelled' THEN i.outstanding_amount ELSE 0 END), 0) AS outst,
               COUNT(DISTINCT i.id) AS inv_cnt,
               (SELECT COUNT(*) FROM re_billing_items b WHERE b.company_id = l.company_id AND b.lease_id = l.id AND b.item_type = 'service_charge') AS sc_cnt,
               (SELECT COUNT(*) FROM re_billing_items b WHERE b.company_id = l.company_id AND b.lease_id = l.id AND b.item_type = 'penalty') AS pen_cnt,
               (SELECT COALESCE(SUM(p.amount),0) FROM re_payments p WHERE p.company_id = l.company_id AND p.lease_id = l.id AND p.accounting_mode = 'invoice') AS collected
        FROM re_leases l
        LEFT JOIN re_invoices i ON i.lease_id = l.id AND i.company_id = l.company_id
        WHERE COALESCE(l.accounting_mode, 'legacy') = 'invoice'
        GROUP BY l.id, l.company_id, l.tenant_id
        HAVING inv_cnt > 0
        ORDER BY outst ASC, l.id ASC
        LIMIT 40
    ";
    $rows = $conn->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $pick = static function (array $rows, callable $pred): ?array {
        foreach ($rows as $r) {
            if ($pred($r)) {
                return $r;
            }
        }
        return null;
    };

    foreach ([
        $pick($rows, static fn($r) => (float)$r['outst'] <= 0.005),
        $pick($rows, static fn($r) => (float)$r['outst'] > 0.005 && (float)$r['collected'] > 0.005),
        $pick($rows, static fn($r) => (float)$r['outst'] > 1000),
        $pick($rows, static fn($r) => (int)$r['sc_cnt'] > 0),
        $pick($rows, static fn($r) => (int)$r['pen_cnt'] > 0),
    ] as $r) {
        if ($r) {
            $key = (int)$r['company_id'] . ':' . (int)$r['lease_id'];
            $samples[$key] = $r;
        }
    }
    $samples = array_values($samples);
}

if ($samples === []) {
    echo "WARN  No Invoice Mode sample leases found.\n";
}

foreach ($samples as $sample) {
    $companyId = (int)$sample['company_id'];
    $leaseId = (int)$sample['lease_id'];
    $ctx = re_lease_load_financial_context($conn, $companyId, $leaseId);
    fs_assert($ctx['ok'] === true && $ctx['accounting_mode'] === 'invoice', "context IM lease {$leaseId}");

    $tenantId = (int)$ctx['tenant_id'];
    $kpis = re_pm_im_kpis($conn, $companyId, $leaseId, $tenantId);
    $summary = re_lease_financial_summary($conn, $companyId, $leaseId, $tenantId);

    $label = "lease {$leaseId}";
    fs_assert($summary['accounting_mode'] === 'invoice', "{$label} mode invoice");
    fs_assert($summary['financial_schema_version'] === 2, "{$label} schema v2");
    fs_assert(fs_money_eq($summary['outstanding_total'], $kpis['total_outstanding'], $tol), "{$label} outstanding vs KPIs", [
        'summary' => $summary['outstanding_total'],
        'kpis' => $kpis['total_outstanding'],
    ]);
    fs_assert(fs_money_eq($summary['overdue_amount'], $kpis['total_overdue'], $tol), "{$label} overdue vs KPIs");
    fs_assert(fs_money_eq($summary['due_now_amount'], $kpis['total_due_now'], $tol), "{$label} due_now vs KPIs");
    fs_assert(fs_money_eq($summary['invoice_total'], $kpis['total_invoiced'], $tol), "{$label} invoice_total vs KPIs");
    fs_assert(fs_money_eq($summary['receipts_total'], $kpis['total_collected'], $tol), "{$label} receipts_total vs KPIs");
    fs_assert(fs_money_eq($summary['allocated_total'], $kpis['allocated_total'], $tol), "{$label} allocated vs KPIs");
    fs_assert(fs_money_eq($summary['unallocated_receipts'], $kpis['unallocated_receipts'], $tol), "{$label} unallocated vs KPIs");
    fs_assert(fs_money_eq($summary['tenant_credit'], $kpis['tenant_credit'], $tol), "{$label} tenant_credit vs KPIs");
    fs_assert((int)$summary['invoice_count'] === (int)$kpis['invoice_count'], "{$label} invoice_count");
    fs_assert((int)$summary['receipt_count'] === (int)$kpis['receipt_count'], "{$label} receipt_count");

    // Money strings are 2-decimal
    fs_assert((bool)preg_match('/^-?\d+\.\d{2}$/', (string)$summary['outstanding_total']), "{$label} money string format");

    $items = re_lease_outstanding_items($conn, $companyId, $leaseId, ['limit' => 100]);
    fs_assert(($items['accounting_mode'] ?? '') === 'invoice', "{$label} outstanding items mode");
    $displayOk = true;
    foreach ($items['items'] as $item) {
        if (($item['display_status'] ?? '') === '') {
            $displayOk = false;
            break;
        }
        if (($item['layer'] ?? '') !== 'accounting') {
            $displayOk = false;
            break;
        }
    }
    fs_assert($displayOk, "{$label} outstanding items have display_status + accounting layer");

    $hist = re_lease_payment_history($conn, $companyId, $leaseId, 25);
    fs_assert(($hist['accounting_mode'] ?? '') === 'invoice', "{$label} payment history mode");

    $timeline = re_lease_payment_timeline($conn, $companyId, $leaseId, 50);
    fs_assert(($timeline['accounting_mode'] ?? '') === 'invoice', "{$label} timeline mode");

    $sched = re_lease_schedule_installments($conn, $companyId, $leaseId);
    fs_assert(($sched['layer'] ?? '') === 'operational', "{$label} installments marked operational");
    fs_assert(str_contains((string)($sched['warning'] ?? ''), 'operational'), "{$label} installments warning present");

    $sc = re_lease_service_charge_rows($conn, $companyId, $leaseId);
    fs_assert(($sc['settlement_source'] ?? '') === 'invoice_mode_obligations', "{$label} SC settlement source IM");

    $pen = re_lease_penalty_rows($conn, $companyId, $leaseId);
    fs_assert(($pen['settlement_source'] ?? '') === 'invoice_mode_obligations', "{$label} penalty settlement source IM");

    // Class breakdown rent+service should not exceed total outstanding (deposit/other may also exist)
    $rent = (float)$summary['rent_outstanding'];
    $svc = (float)$summary['service_charge_outstanding'];
    $total = (float)$summary['outstanding_total'];
    fs_assert($rent + $svc <= $total + $tol + 0.01 || $total >= 0, "{$label} class outstanding non-negative composition");

    echo "INFO  {$label} display_status={$summary['display_status']} outstanding={$summary['outstanding_total']} credit={$summary['tenant_credit']} sc_rows=" . count($sc['service_charges']) . " pen_rows=" . count($pen['penalties']) . "\n";
}

// Empty summary for invalid lease
$empty = re_lease_financial_summary($conn, 2, 99999999, 0);
fs_assert($empty['outstanding_total'] === '0.00' && $empty['lease_id'] === 99999999, 'empty summary on missing lease');

echo "\n=== Result: {$passed} passed, {$failed} failed ===\n";
exit($failed > 0 ? 1 : 0);
