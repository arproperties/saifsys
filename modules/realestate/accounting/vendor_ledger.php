<?php
/**
 * Vendor Ledger — document activity + GL sub-ledger (Phase 1).
 * Uses setting-based AP account via re_ap_account() (not hardcoded 2130).
 */
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../../../includes/db_connect.php';
require_once __DIR__ . '/../../../includes/branding.php';
require_once __DIR__ . '/../../../includes/company_helper.php';
require_once __DIR__ . '/../../../includes/module_access.php';
require_once __DIR__ . '/../../../includes/rbac_department.php';
require_once __DIR__ . '/../includes/vendor_ap_helper.php';
require_once __DIR__ . '/export_excel_helper.php';

require_login();
if (!has_department_access(MODULE_REALESTATE, DEPT_REALESTATE_FINANCIAL, $conn)) {
    require_module_access($conn, MODULE_REALESTATE);
}

$brand = getBrandSettings($conn);
$companyId = (int)(current_company_id($conn) ?: 0);
if ($companyId <= 0) {
    http_response_code(400);
    die('Company context is required.');
}

$vendorId = (int)($_GET['vendor_id'] ?? 0);
$ledgerId = (int)($_GET['ledger_id'] ?? 0);
$from = !empty($_GET['date_from']) ? (string)$_GET['date_from'] : date('Y-m-01');
$to = !empty($_GET['date_to']) ? (string)$_GET['date_to'] : date('Y-m-d');
$export = (string)($_GET['export'] ?? '');

function h($s)
{
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}
function m($n)
{
    return number_format((float)$n, 2);
}

$vendorsStmt = $conn->prepare("SELECT id, vendor_name FROM re_vendors WHERE company_id = ? ORDER BY vendor_name");
$vendorsStmt->execute([$companyId]);
$vendors = $vendorsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

if (!$vendorId && $ledgerId) {
    $s = $conn->prepare("SELECT sub_account_id FROM re_account_ledgers WHERE id = ? AND company_id = ?");
    $s->execute([$ledgerId, $companyId]);
    $vendorId = (int)($s->fetchColumn() ?: 0);
}

$vendor = null;
$activity = [];
$ledgerEntries = [];
$opening = 0.0;
$closing = 0.0;
$docOpening = 0.0;
$docClosing = 0.0;
$apAccount = re_ap_account($conn, $companyId);
$apAccountLabel = $apAccount
    ? (($apAccount['account_code'] ?? '') . ' ' . ($apAccount['account_name'] ?? ''))
    : 'AP account not configured';
$availableAdvance = 0.0;

if ($vendorId) {
    $s = $conn->prepare("SELECT * FROM re_vendors WHERE id = ? AND company_id = ?");
    $s->execute([$vendorId, $companyId]);
    $vendor = $s->fetch(PDO::FETCH_ASSOC) ?: null;
}

if ($vendor) {
    $availableAdvance = function_exists('re_ap_vendor_advance_balance')
        ? re_ap_vendor_advance_balance($conn, $companyId, $vendorId)
        : 0.0;
    // Document opening: posted bills before from − posted payments before from
    $obBills = $conn->prepare("
        SELECT COALESCE(SUM(total_amount), 0)
        FROM re_vendor_invoices
        WHERE company_id = ? AND vendor_id = ?
          AND posting_status = 'posted'
          AND status NOT IN ('void', 'cancelled')
          AND invoice_date < ?
    ");
    $obBills->execute([$companyId, $vendorId, $from]);
    $obPays = $conn->prepare("
        SELECT COALESCE(SUM(amount), 0)
        FROM re_vendor_payments
        WHERE company_id = ? AND vendor_id = ?
          AND status = 'posted'
          AND payment_date < ?
    ");
    $obPays->execute([$companyId, $vendorId, $from]);
    $docOpening = (float)$obBills->fetchColumn() - (float)$obPays->fetchColumn();

    $b = $conn->prepare("
        SELECT * FROM re_vendor_invoices
        WHERE company_id = ? AND vendor_id = ?
          AND posting_status = 'posted'
          AND status NOT IN ('void', 'cancelled')
          AND invoice_date BETWEEN ? AND ?
        ORDER BY invoice_date ASC, id ASC
    ");
    $b->execute([$companyId, $vendorId, $from, $to]);
    foreach ($b->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
        $activity[] = [
            'date' => $r['invoice_date'],
            'type' => 'Bill',
            'ref' => $r['invoice_number'],
            'debit' => 0.0,
            'credit' => (float)$r['total_amount'],
            'id' => (int)$r['id'],
            'link' => 'vendor_bill_view.php?id=' . (int)$r['id'],
        ];
    }

    $p = $conn->prepare("
        SELECT * FROM re_vendor_payments
        WHERE company_id = ? AND vendor_id = ?
          AND status = 'posted'
          AND payment_date BETWEEN ? AND ?
        ORDER BY payment_date ASC, id ASC
    ");
    $p->execute([$companyId, $vendorId, $from, $to]);
    foreach ($p->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
        $activity[] = [
            'date' => $r['payment_date'],
            'type' => 'Payment',
            'ref' => $r['reference_number'] ?: ('PAY-' . $r['id']),
            'debit' => (float)$r['amount'],
            'credit' => 0.0,
            'id' => (int)$r['id'],
            'link' => 'vendor_payments.php?q=' . urlencode($r['reference_number'] ?: ('PAY-' . $r['id'])),
        ];
    }

    if (function_exists('re_ap_advance_refund_table_ready') && re_ap_advance_refund_table_ready($conn)) {
        $rf = $conn->prepare("
            SELECT id, refund_date, amount, reference_number, status, vendor_payment_id
            FROM re_vendor_advance_refunds
            WHERE company_id = ? AND vendor_id = ?
              AND refund_date BETWEEN ? AND ?
            ORDER BY refund_date ASC, id ASC
        ");
        $rf->execute([$companyId, $vendorId, $from, $to]);
        foreach ($rf->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
            // Informational only — does not change AP running balance
            $activity[] = [
                'date' => $r['refund_date'],
                'type' => 'Adv Refund',
                'ref' => ($r['reference_number'] ?: ('REF-' . $r['id'])) . ' · ' . number_format((float)$r['amount'], 2) . ' · ' . $r['status'],
                'debit' => 0.0,
                'credit' => 0.0,
                'id' => (int)$r['id'],
                'link' => 'vendor_advance_refund_view.php?id=' . (int)$r['id'],
            ];
        }
    }

    usort($activity, static function ($a, $b) {
        $c = strcmp($a['date'], $b['date']);
        return $c !== 0 ? $c : strcmp($a['type'], $b['type']);
    });

    $run = $docOpening;
    foreach ($activity as &$a) {
        $run += $a['credit'] - $a['debit'];
        $a['balance'] = $run;
    }
    unset($a);
    $docClosing = $run;

    // GL sub-ledger via setting-based AP account
    if ($apAccount) {
        $apId = (int)$apAccount['id'];
        $l = $conn->prepare("
            SELECT * FROM re_account_ledgers
            WHERE company_id = ? AND account_id = ?
              AND sub_account_type = 'vendor' AND sub_account_id = ?
            LIMIT 1
        ");
        $l->execute([$companyId, $apId, $vendorId]);
        $ledger = $l->fetch(PDO::FETCH_ASSOC);
        if ($ledger) {
            $ledgerId = (int)$ledger['id'];
            $ob = $conn->prepare("
                SELECT balance FROM re_account_ledger_entries
                WHERE ledger_id = ? AND company_id = ? AND entry_date < ?
                ORDER BY entry_date DESC, id DESC LIMIT 1
            ");
            $ob->execute([$ledgerId, $companyId, $from]);
            $opening = (float)($ob->fetchColumn() ?: $ledger['opening_balance']);
            $e = $conn->prepare("
                SELECT ale.*, jh.journal_number, jh.journal_type
                FROM re_account_ledger_entries ale
                JOIN re_journal_headers jh ON jh.id = ale.journal_id
                WHERE ale.ledger_id = ? AND ale.company_id = ?
                  AND ale.entry_date BETWEEN ? AND ?
                ORDER BY ale.entry_date ASC, ale.id ASC
            ");
            $e->execute([$ledgerId, $companyId, $from, $to]);
            $ledgerEntries = $e->fetchAll(PDO::FETCH_ASSOC) ?: [];
            $closing = $ledgerEntries ? (float)end($ledgerEntries)['balance'] : $opening;
        }
    }
}

if ($export === 'excel' && $vendor) {
    $rows = [];
    $rows[] = [
        'date' => $from,
        'type' => 'Opening',
        'ref' => '',
        'debit' => '',
        'credit' => '',
        'balance' => round($docOpening, 2),
    ];
    foreach ($activity as $a) {
        $rows[] = [
            'date' => $a['date'],
            'type' => $a['type'],
            'ref' => $a['ref'],
            'debit' => $a['debit'] > 0 ? round($a['debit'], 2) : '',
            'credit' => $a['credit'] > 0 ? round($a['credit'], 2) : '',
            'balance' => round($a['balance'], 2),
        ];
    }
    accounting_export_excel_or_csv(
        $rows,
        [
            'date' => 'Date',
            'type' => 'Type',
            'ref' => 'Reference',
            'debit' => 'Debit/Payment',
            'credit' => 'Credit/Bill',
            'balance' => 'Running Balance',
        ],
        'vendor_ledger_' . $vendorId . '_' . $from . '_' . $to,
        'Vendor Ledger — ' . ($vendor['vendor_name'] ?? '')
    );
}

$reApUiEnhanced = true;
$pageTitle = 'Vendor Ledger';
require_once __DIR__ . '/../includes/re_layout_header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4 re-ap-print-hide">
    <div class="page-header-label">
        <i class="bi bi-journal-bookmark me-1"></i>
        Vendor Ledger
    </div>
    <div class="d-flex gap-2 flex-wrap">
        <?php if ($vendor): ?>
            <a href="?<?= h(http_build_query(array_merge($_GET, ['export' => 'excel']))) ?>" class="btn btn-outline-success">Excel</a>
            <button type="button" class="btn btn-outline-secondary" onclick="window.print()">Print</button>
            <a href="vendor_statement.php?vendor_id=<?= (int)$vendorId ?>&date_from=<?= h(urlencode($from)) ?>&date_to=<?= h(urlencode($to)) ?>" class="btn btn-outline-primary">Vendor SOA</a>
        <?php endif; ?>
        <a href="ap_aging.php" class="btn btn-outline-primary">AP Aging</a>
        <a href="vendor_bills.php" class="btn btn-outline-secondary">Vendor Bills</a>
    </div>
</div>

<div class="card card-round mb-4 re-ap-print-hide">
    <div class="card-body">
        <form method="get" class="row g-3 align-items-end">
            <div class="col-md-4">
                <label class="form-label">Vendor</label>
                <select name="vendor_id" class="form-select" required>
                    <option value="">-- Select Vendor --</option>
                    <?php foreach ($vendors as $v): ?>
                        <option value="<?= (int)$v['id'] ?>" <?= $vendorId === (int)$v['id'] ? 'selected' : '' ?>><?= h($v['vendor_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label">From</label>
                <input type="date" name="date_from" class="form-control" value="<?= h($from) ?>">
            </div>
            <div class="col-md-3">
                <label class="form-label">To</label>
                <input type="date" name="date_to" class="form-control" value="<?= h($to) ?>">
            </div>
            <div class="col-md-2">
                <button class="btn btn-primary w-100">View</button>
            </div>
        </form>
        <div class="form-text mt-2">AP GL account: <?= h($apAccountLabel) ?> (from re_vendor_ap_account_code setting).</div>
    </div>
</div>

<?php if ($vendor): ?>
    <div class="row g-3 mb-4">
        <div class="col-md-4">
            <div class="card card-round re-ap-card-metric">
                <div class="card-body">
                    <div class="metric-label">Vendor</div>
                    <div class="fw-semibold"><?= h($vendor['vendor_name']) ?></div>
                    <div class="small text-muted"><?= h($vendor['phone'] ?: '-') ?> · <?= h($vendor['email'] ?: '-') ?></div>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card card-round re-ap-card-metric">
                <div class="card-body">
                    <div class="metric-label">Document Closing AP</div>
                    <div class="metric-value"><?= m($docClosing) ?></div>
                    <div class="small text-muted">Opening <?= m($docOpening) ?></div>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card card-round re-ap-card-metric">
                <div class="card-body">
                    <div class="metric-label">Available Vendor Advance</div>
                    <div class="metric-value text-success"><?= m($availableAdvance) ?></div>
                    <div class="small text-muted">GL sub-ledger close <?= m($closing) ?></div>
                </div>
            </div>
        </div>
    </div>

    <div class="card card-round mb-4">
        <div class="card-header d-flex justify-content-between">
            <span>Bills &amp; Payments (running balance)</span>
            <span class="small text-muted">Credit = bill / Debit = payment</span>
        </div>
        <div class="table-responsive">
            <table class="table table-sm table-hover re-ap-table mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Date</th>
                        <th>Type</th>
                        <th>Reference</th>
                        <th class="text-end">Debit/Payment</th>
                        <th class="text-end">Credit/Bill</th>
                        <th class="text-end">Balance</th>
                        <th class="re-ap-print-hide"></th>
                    </tr>
                </thead>
                <tbody>
                    <tr class="table-secondary">
                        <td colspan="5" class="text-end">Opening</td>
                        <td class="text-end"><?= m($docOpening) ?></td>
                        <td class="re-ap-print-hide"></td>
                    </tr>
                    <?php foreach ($activity as $a): ?>
                        <tr>
                            <td><?= h($a['date']) ?></td>
                            <td><?= h($a['type']) ?></td>
                            <td><?= h($a['ref']) ?></td>
                            <td class="text-end"><?= $a['debit'] > 0 ? m($a['debit']) : '-' ?></td>
                            <td class="text-end"><?= $a['credit'] > 0 ? m($a['credit']) : '-' ?></td>
                            <td class="text-end"><?= m($a['balance']) ?></td>
                            <td class="re-ap-print-hide"><a href="<?= h($a['link']) ?>" class="btn btn-sm btn-outline-primary">Open</a></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$activity): ?>
                        <tr><td colspan="7" class="text-center text-muted">No activity in period</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="card card-round">
        <div class="card-header">GL Vendor Sub-Ledger (<?= h($apAccountLabel) ?>)</div>
        <div class="table-responsive">
            <table class="table table-sm re-ap-table mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Date</th>
                        <th>Journal</th>
                        <th>Type</th>
                        <th>Description</th>
                        <th>Reference</th>
                        <th class="text-end">Debit</th>
                        <th class="text-end">Credit</th>
                        <th class="text-end">Balance</th>
                    </tr>
                </thead>
                <tbody>
                    <tr class="table-secondary">
                        <td colspan="7" class="text-end">Opening</td>
                        <td class="text-end"><?= m($opening) ?></td>
                    </tr>
                    <?php foreach ($ledgerEntries as $e): ?>
                        <tr>
                            <td><?= h($e['entry_date']) ?></td>
                            <td><a href="journal_entry_view.php?id=<?= (int)$e['journal_id'] ?>"><?= h($e['journal_number']) ?></a></td>
                            <td><?= h($e['journal_type']) ?></td>
                            <td><?= h($e['description']) ?></td>
                            <td><?= h($e['reference']) ?></td>
                            <td class="text-end"><?= (float)$e['debit_amount'] > 0 ? m($e['debit_amount']) : '-' ?></td>
                            <td class="text-end"><?= (float)$e['credit_amount'] > 0 ? m($e['credit_amount']) : '-' ?></td>
                            <td class="text-end"><?= m($e['balance']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$ledgerEntries && !$apAccount): ?>
                        <tr><td colspan="8" class="text-center text-muted">AP account not found — configure re_vendor_ap_account_code.</td></tr>
                    <?php elseif (!$ledgerEntries): ?>
                        <tr><td colspan="8" class="text-center text-muted">No GL sub-ledger entries in period (or vendor ledger not created yet).</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/re_layout_footer.php'; ?>
