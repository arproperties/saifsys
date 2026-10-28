<?php
// cron/re_rent_invoices/run.php
// Real estate accrual, run daily. For every active Invoice Mode lease
// (and expired/terminated ones, up to their end or termination date only):
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
    // Ended leases with no receipts, invoices or cheques at all - confirm they are real first.
    '501-0001' => 'no receipts or cheques - waiting for confirmation',
    'GENTSCAM-G01-0001' => 'no receipts or cheques - waiting for confirmation',
    'MRAMRANS-102-0001' => 'no receipts or cheques - waiting for confirmation',
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

// Ended leases still owe the months up to their end; nothing after it is ever invoiced.
$sql = "
    SELECT id, company_id, lease_number, status,
           CASE WHEN status = 'terminated' THEN COALESCE(termination_date, end_date) ELSE end_date END AS last_day
    FROM re_leases
    WHERE accounting_mode = 'invoice' AND status IN ('active', 'expired', 'terminated') AND deleted_at IS NULL
";
$params = [];
if ($onlyLease) { $sql .= ' AND id = ?'; $params[] = $onlyLease; }
if ($onlyCompany) { $sql .= ' AND company_id = ?'; $params[] = $onlyCompany; }
$sql .= ' ORDER BY company_id, id';
$stmt = $conn->prepare($sql);
$stmt->execute($params);
$leases = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Issue an ended lease's due invoices whose period starts on or before its last day.
// Candidates are prepared only for those rows, so nothing later can be auto-issued.
// A terminated lease's last day is its termination date, so a row starting that day is not billed.
$issueEndedLease = static function (PDO $conn, int $companyId, int $leaseId, string $lastDay, bool $terminated): int {
    $inRange = static function (array $o) use ($lastDay, $terminated): bool {
        $from = (string)($o['period_start'] ?: $o['due_date']);
        return $terminated ? $from < $lastDay : $from <= $lastDay;
    };
    $plan = re_invoice_engine_candidate_plan_for_lease($conn, $companyId, $leaseId);
    if (empty($plan['success'])) {
        throw new RuntimeException('plan: ' . ($plan['error'] ?? 'unknown'));
    }
    $insert = $conn->prepare("
        INSERT INTO re_invoice_candidates (
            company_id, lease_id, obligation_id, candidate_key, eligible_on,
            status, invoice_id, notes, engine_version,
            prepared_by, prepared_at, approved_by, approved_at
        ) VALUES (?, ?, ?, ?, ?, 'approved', NULL, ?, ?, NULL, NOW(), NULL, NOW())
    ");
    foreach ($plan['candidate_plans'] as $p) {
        if (!$p['is_eligible_now'] || !$inRange($p['obligation'])) {
            continue;
        }
        $obligationId = (int)$p['obligation']['id'];
        // A cancelled candidate (after a void) is re-opened, as the normal prepare step does.
        $old = $conn->prepare("
            SELECT c.id, c.status, i.status AS invoice_status
            FROM re_invoice_candidates c
            LEFT JOIN re_invoices i ON i.id = c.invoice_id AND i.company_id = c.company_id
            WHERE c.company_id = ? AND c.obligation_id = ?
            ORDER BY c.id DESC LIMIT 1
        ");
        $old->execute([$companyId, $obligationId]);
        if ($oldCand = $old->fetch(PDO::FETCH_ASSOC)) {
            if (in_array($oldCand['status'], ['cancelled', 'void'], true) && in_array($oldCand['invoice_status'], [null, 'cancelled'], true)) {
                $conn->prepare("
                    UPDATE re_invoice_candidates
                    SET status = 'approved', eligible_on = ?, candidate_key = ?, invoice_id = NULL, approved_at = NOW(),
                        notes = TRIM(CONCAT(COALESCE(notes, ''), ' | Re-approved by daily rent job'))
                    WHERE id = ? AND company_id = ?
                ")->execute([(string)$p['eligible_on'], (string)$p['candidate_key'], (int)$oldCand['id'], $companyId]);
            }
            continue;
        }
        $insert->execute([$companyId, $leaseId, $obligationId, (string)$p['candidate_key'], (string)$p['eligible_on'],
            'Prepared by daily rent job for ended lease, obligation #' . $obligationId . '.', RE_INVOICE_ENGINE_VERSION]);
    }
    $issued = 0;
    foreach (re_invoice_engine_issuable_candidates($conn, $companyId, $leaseId) as $c) {
        if (!$inRange($c)) {
            continue;
        }
        $res = re_invoice_engine_issue_candidate_for_lease($conn, $companyId, $leaseId, (int)$c['id'], null);
        if (empty($res['success'])) {
            throw new RuntimeException('issue #' . $c['id'] . ': ' . ($res['error'] ?? 'unknown'));
        }
        $issued++;
    }
    return $issued;
};

$totals = ['leases' => 0, 'skipped' => 0, 'regenerated' => 0, 'issued' => 0, 'would_issue' => 0, 'failed' => 0];
$companies = [];
foreach ($leases as $lease) {
    $leaseId = (int)$lease['id'];
    $companyId = (int)$lease['company_id'];
    $companies[$companyId] = true;
    $ended = $lease['status'] !== 'active';
    $lastDay = $ended ? (string)$lease['last_day'] : '9999-12-31';
    $tag = "lease #$leaseId {$lease['lease_number']}" . ($ended ? " ({$lease['status']}, up to $lastDay)" : '');
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
              AND (COALESCE(period_start, due_date) < ? OR (? = 0 AND COALESCE(period_start, due_date) = ?))
              AND NOT EXISTS (SELECT 1 FROM re_invoice_items ii JOIN re_invoices i ON i.id = ii.invoice_id
                              WHERE ii.obligation_id = re_obligations.id AND i.status <> 'cancelled')
        ");
        $due->execute([$companyId, $leaseId, $lastDay, (int)($lease['status'] === 'terminated'), $lastDay]);
        [$n, $amt] = $due->fetch(PDO::FETCH_NUM);
        if ($n > 0) {
            $totals['would_issue'] += (int)$n;
            $log("$tag: would issue $n invoice(s), AED " . number_format((float)$amt, 2));
        }
        continue;
    }

    try {
        if ($ended) {
            $issued = $issueEndedLease($conn, $companyId, $leaseId, $lastDay, $lease['status'] === 'terminated');
            $totals['issued'] += $issued;
            if ($issued > 0) {
                $log("$tag: issued $issued invoice(s)");
            }
            continue;
        }
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
