<?php
/**
 * Phase 5A — read-only performance probe for Customer App financial paths.
 *
 * Usage:
 *   php modules/realestate/tools/customer_app_financial_phase5_perf_probe.php
 *   php modules/realestate/tools/customer_app_financial_phase5_perf_probe.php --label=after
 *
 * Does not write to the database.
 */
declare(strict_types=1);

$root = dirname(__DIR__, 3);
if (!is_link('/tmp/mysql.sock') && !file_exists('/tmp/mysql.sock')) {
    @symlink('/Applications/XAMPP/xamppfiles/var/mysql/mysql.sock', '/tmp/mysql.sock');
}

require_once $root . '/includes/db_connect.php';

$label = 'run';
foreach ($argv as $arg) {
    if (preg_match('/^--label=(.+)$/', $arg, $m)) {
        $label = preg_replace('/[^a-zA-Z0-9_-]/', '', $m[1]) ?: 'run';
    }
}

/** @var PDO $conn */
$pdo = $conn;
$queryCount = 0;
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// Count statements via a thin wrapper — PDO does not expose a global counter,
// so we instrument prepare/query via a decorator when available. Fallback: time only.
class P5CountingPDO extends PDO
{
    public int $queryCount = 0;
    public function prepare($query, $options = []): PDOStatement|false
    {
        $this->queryCount++;
        return parent::prepare($query, $options);
    }
    public function query($query, $fetchMode = null, ...$fetchModeArgs): PDOStatement|false
    {
        $this->queryCount++;
        if ($fetchMode === null) {
            return parent::query($query);
        }
        return parent::query($query, $fetchMode, ...$fetchModeArgs);
    }
}

// Reconnect as counting PDO using same DSN from existing connection is hard;
// instead wrap by requiring service after enabling general_log is not allowed.
// Use microtime + explicit resets of request cache between leases.

require_once $root . '/modules/realestate/includes/lease_financial_summary_service.php';
require_once $root . '/api/customer/v1/tenant_endpoints.php';

$leases = [69, 159, 269, 230, 61, 37, 49];
$companyId = 2;

echo "=== Phase 5A financial performance probe ({$label}) ===\n";
echo "CLI auth/session noise check:\n";

// Capture whether loading service emits session warnings (already loaded above).
$sessionActive = session_status() === PHP_SESSION_ACTIVE;
echo '  session_active_after_service_load=' . ($sessionActive ? 'yes' : 'no') . "\n";
echo '  receipt_allocation_engine_loaded=' . (function_exists('re_receipt_create') ? 'yes' : 'no') . "\n";
echo '  allocated_map_available=' . (function_exists('re_billing_item_obligation_allocated_map') ? 'yes' : 'no') . "\n\n";

$rows = [];
foreach ($leases as $leaseId) {
    // Clear request memo between leases (simulate distinct HTTP requests).
    $bag = &re_lease_fs_request_cache_bag();
    $bag = [];

    $mem0 = memory_get_usage(true);
    $t0 = hrtime(true);
    $summary = re_lease_financial_summary($conn, $companyId, $leaseId, 0);
    $t1 = hrtime(true);
    $dash = customer_api_tenant_financial_payload_for_dashboard($conn, $companyId, $leaseId, $summary);
    $t2 = hrtime(true);
    $items = re_lease_outstanding_items($conn, $companyId, $leaseId, ['limit' => 200]);
    $hist = re_lease_payment_history($conn, $companyId, $leaseId, 50);
    $sc = re_lease_service_charge_rows($conn, $companyId, $leaseId);
    $pen = re_lease_penalty_rows($conn, $companyId, $leaseId);
    $sched = re_lease_schedule_installments($conn, $companyId, $leaseId);
    $t3 = hrtime(true);
    $mem1 = memory_get_usage(true);

    $json = json_encode([
        'summary' => $summary,
        'dash' => $dash,
        'items' => $items,
        'hist' => $hist,
        'sc' => $sc,
        'pen' => $pen,
        'sched' => $sched,
    ], JSON_UNESCAPED_UNICODE);
    $payloadBytes = strlen((string)$json);

    $row = [
        'lease_id' => $leaseId,
        'summary_ms' => round(($t1 - $t0) / 1e6, 2),
        'dashboard_payload_ms' => round(($t2 - $t1) / 1e6, 2),
        'bundle_ms' => round(($t3 - $t0) / 1e6, 2),
        'payload_bytes' => $payloadBytes,
        'mem_delta_kb' => round(($mem1 - $mem0) / 1024, 1),
        'outstanding_total' => $summary['outstanding_total'] ?? null,
        'invoice_items' => count($items['items'] ?? []),
        'receipts' => count($hist['receipts'] ?? []),
        'sc_rows' => count($sc['service_charges'] ?? []),
        'pen_rows' => count($pen['penalties'] ?? []),
        'installments' => count($sched['installments'] ?? []),
    ];
    $rows[] = $row;
    printf(
        "L%d summary=%.2fms dash+=%.2fms bundle=%.2fms bytes=%d inv=%d rcpt=%d sc=%d pen=%d inst=%d\n",
        $leaseId,
        $row['summary_ms'],
        $row['dashboard_payload_ms'],
        $row['bundle_ms'],
        $row['payload_bytes'],
        $row['invoice_items'],
        $row['receipts'],
        $row['sc_rows'],
        $row['pen_rows'],
        $row['installments']
    );
}

// Multi-lease tenant 177 sequential switch simulation
$bag = &re_lease_fs_request_cache_bag();
$bag = [];
$t0 = hrtime(true);
foreach ([262, 265, 267, 268, 269] as $leaseId) {
    $bag = &re_lease_fs_request_cache_bag();
    $bag = []; // each switch = new request
    re_lease_financial_summary($conn, $companyId, $leaseId, 177);
}
$t1 = hrtime(true);
printf("multi-lease tenant177 5x summary=%.2fms\n", ($t1 - $t0) / 1e6);

$outDir = $root . '/docs/cursor-audit/artifacts';
if (!is_dir($outDir)) {
    @mkdir($outDir, 0775, true);
}
$outFile = $outDir . '/phase5_perf_' . $label . '.json';
file_put_contents($outFile, json_encode([
    'label' => $label,
    'generated_at' => date('c'),
    'leases' => $rows,
], JSON_PRETTY_PRINT));
echo "INFO  wrote {$outFile}\n";
