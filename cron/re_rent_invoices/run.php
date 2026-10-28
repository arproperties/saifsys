<?php
// cron/re_rent_invoices/run.php
// Real estate accrual, run daily. For every active Invoice Mode lease:
//   1. issue each rent/fee invoice once its date arrives (catch-up issues past
//      months on their own due dates, so each month's P&L gets its own rent);
//   2. sync the month-end cut-off journals that move later-month days of older
//      anniversary-period rent invoices into the right calendar month.
// Command line only.
//
// Hostinger cron (05:00 UTC = 09:00 Dubai, so PHP and MySQL agree on the date):
//   0 5 * * *  php <sys>/cron/re_rent_invoices/run.php
// By hand:
//   php run.php --dry-run          show what would happen, change nothing
//   php run.php --regenerate       one-off: rebuild unbilled rent rows as calendar months first
//   php run.php --lease=429        one lease only (cut-off sync still runs for the company)

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
date_default_timezone_set('Asia/Dubai');   // live PHP runs on UTC

// Leases left out until their figures are confirmed.
const RE_RENT_CRON_SKIP_LEASE_NUMBERS = [
    'GENTSCAM-101-0001' => 'annual rent 40,260,600 - waiting for confirmation',
];

require_once __DIR__ . '/../../includes/db_connect.php';
// The engines pull in web helpers that try to start a session; harmless on the command line.
@require_once __DIR__ . '/../../modules/realestate/includes/invoice_engine.php';
@require_once __DIR__ . '/../../modules/realestate/includes/rent_cutoff_engine.php';

$opt = getopt('', ['dry-run', 'regenerate', 'lease:', 'company:']);
$dryRun = isset($opt['dry-run']);
$regenerate = isset($opt['regenerate']);
$onlyLease = isset($opt['lease']) ? (int)$opt['lease'] : 0;
$onlyCompany = isset($opt['company']) ? (int)$opt['company'] : 0;

$logFile = preg_match('#^(/home/[^/]+)/#', __DIR__, $m) ? $m[1] . '/re_rent_invoices.log' : null;
$log = static function (string $msg) use ($logFile): void {
    $line = date('Y-m-d H:i:s') . ' ' . $msg . "\n";
    echo $line;
    if ($logFile) {
        file_put_contents($logFile, $line, FILE_APPEND);
    }
};

$log('start' . ($dryRun ? ' (dry run)' : '') . ($regenerate ? ' (regenerate)' : '') . ($onlyLease ? " lease=$onlyLease" : ''));

$sql = "
    SELECT id, company_id, lease_number
    FROM re_leases
    WHERE accounting_mode = 'invoice' AND status = 'active' AND deleted_at IS NULL
";
$params = [];
if ($onlyLease) { $sql .= ' AND id = ?'; $params[] = $onlyLease; }
if ($onlyCompany) { $sql .= ' AND company_id = ?'; $params[] = $onlyCompany; }
$sql .= ' ORDER BY company_id, id';
$stmt = $conn->prepare($sql);
$stmt->execute($params);
$leases = $stmt->fetchAll(PDO::FETCH_ASSOC);

$totals = ['leases' => 0, 'skipped' => 0, 'regenerated' => 0, 'issued' => 0, 'would_issue' => 0, 'failed' => 0];
$companies = [];
foreach ($leases as $lease) {
    $leaseId = (int)$lease['id'];
    $companyId = (int)$lease['company_id'];
    $companies[$companyId] = true;
    $tag = "lease #$leaseId {$lease['lease_number']}";
    if (isset(RE_RENT_CRON_SKIP_LEASE_NUMBERS[$lease['lease_number']])) {
        $totals['skipped']++;
        $log("skip $tag: " . RE_RENT_CRON_SKIP_LEASE_NUMBERS[$lease['lease_number']]);
        continue;
    }
    $totals['leases']++;

    if ($dryRun) {
        $due = $conn->prepare("
            SELECT COUNT(*), COALESCE(SUM(total_amount), 0)
            FROM re_obligations
            WHERE company_id = ? AND lease_id = ? AND status = 'open' AND invoice_id IS NULL
              AND accounting_class <> 'liability' AND obligation_type <> 'security_deposit'
              AND total_amount > 0 AND due_date <= CURDATE()
              AND NOT EXISTS (SELECT 1 FROM re_invoice_items ii JOIN re_invoices i ON i.id = ii.invoice_id
                              WHERE ii.obligation_id = re_obligations.id AND i.status <> 'cancelled')
        ");
        $due->execute([$companyId, $leaseId]);
        [$n, $amt] = $due->fetch(PDO::FETCH_NUM);
        if ($n > 0) {
            $totals['would_issue'] += (int)$n;
            $log("$tag: would issue $n invoice(s), AED " . number_format((float)$amt, 2));
        }
        continue;
    }

    try {
        if ($regenerate) {
            $gen = re_obligation_engine_generate_for_lease($conn, $companyId, $leaseId, null);
            if (empty($gen['success'])) {
                throw new RuntimeException('regenerate: ' . ($gen['error'] ?? 'unknown'));
            }
            $totals['regenerated']++;
        }
        $prep = re_invoice_engine_prepare_candidates_for_lease($conn, $companyId, $leaseId, null);
        if (empty($prep['success'])) {
            throw new RuntimeException('prepare: ' . ($prep['error'] ?? 'unknown'));
        }
        $issue = re_invoice_engine_issue_eligible_for_lease($conn, $companyId, $leaseId, null);
        $issued = (int)($issue['stats']['issued'] ?? 0);
        $totals['issued'] += $issued;
        if (empty($issue['success'])) {
            throw new RuntimeException('issue: ' . ($issue['error'] ?? 'unknown'));
        }
        if ($issued > 0) {
            $log("$tag: issued $issued invoice(s)");
        }
    } catch (Throwable $e) {
        $totals['failed']++;
        $log("FAILED $tag: " . $e->getMessage());
    }
}

foreach (array_keys($companies) as $companyId) {
    $cut = re_rent_cutoff_sync_company($conn, (int)$companyId, null, $dryRun);
    $log("company $companyId cut-off: checked {$cut['checked']}, posted {$cut['posted']}, replaced {$cut['replaced']}, reversed {$cut['reversed']}, unchanged {$cut['unchanged']}, errors {$cut['error']}");
    foreach (array_slice($cut['errors'], 0, 20) as $err) {
        $log("  cut-off error: $err");
    }
    if ($cut['error'] > 0) {
        $totals['failed'] += (int)$cut['error'];
    }
}

$log('done: ' . json_encode($totals));
exit($totals['failed'] > 0 ? 1 : 0);
