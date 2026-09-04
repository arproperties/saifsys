<?php
/**
 * Income Mispost Report + Repair Session creation
 */
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../../../includes/db_connect.php';
require_once __DIR__ . '/../../../includes/branding.php';
require_once __DIR__ . '/../../../includes/company_helper.php';
require_once __DIR__ . '/../../../includes/module_access.php';
require_once __DIR__ . '/../../../includes/csrf.php';
require_once __DIR__ . '/../includes/re_income_account_roles.php';
require_once __DIR__ . '/../includes/re_income_reclass_helper.php';

require_login();
require_module_access($conn, MODULE_REALESTATE);
if (!has_role('Owner', $conn)) {
    http_response_code(403);
    die('Access denied. This tool is available to Owner only.');
}

$brand = getBrandSettings($conn);
$companyId = (int)(current_company_id($conn) ?: 0);
if ($companyId <= 0) {
    http_response_code(400);
    die('Company context is required.');
}

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function money_fmt($n) { return number_format((float)$n, 2); }

$schemaReady = re_income_reclass_schema_ready($conn);
$flashError = '';
$flashSuccess = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create_session') {
    csrf_verify();
    if (!$schemaReady) {
        $flashError = 'Repair tables are not installed yet. Apply migrations/re_income_reclass_repair.sql first.';
    } else {
        $keys = $_POST['candidate'] ?? [];
        if (!is_array($keys) || !$keys) {
            $flashError = 'Select at least one candidate.';
        } else {
            $all = re_income_mispost_candidates($conn, $companyId);
            $byKey = [];
            foreach ($all as $m) {
                $acc = re_income_reclass_resolve_accounts($conn, $companyId, $m);
                if ($acc['error']) {
                    continue;
                }
                $ck = re_income_reclass_candidate_key(
                    (int)($m['invoice_item_id'] ?? 0),
                    (int)$m['journal_id'],
                    (int)$acc['wrong']['id'],
                    (int)$acc['correct']['id']
                );
                $byKey[$ck] = $m;
            }
            $selected = [];
            foreach ($keys as $k) {
                $k = (string)$k;
                if (isset($byKey[$k])) {
                    $selected[] = $byKey[$k];
                }
            }
            $corr = trim((string)($_POST['correction_date'] ?? ''));
            $res = re_income_reclass_create_session(
                $conn,
                $companyId,
                $selected,
                $corr !== '' ? $corr : null,
                current_user_id(),
                trim((string)($_POST['notes'] ?? ''))
            );
            if ($res['success']) {
                header('Location: income_reclass_session_view.php?id=' . (int)$res['session_id']);
                exit;
            }
            $flashError = $res['error'] ?: 'Failed to create session';
        }
    }
}

$filters = [
    'date_from' => trim((string)($_GET['date_from'] ?? '')),
    'date_to' => trim((string)($_GET['date_to'] ?? '')),
    'obligation_type' => trim((string)($_GET['obligation_type'] ?? '')),
    'posted_account' => trim((string)($_GET['posted_account'] ?? '')),
    'expected_account' => trim((string)($_GET['expected_account'] ?? '')),
    'invoice' => trim((string)($_GET['invoice'] ?? '')),
    'journal' => trim((string)($_GET['journal'] ?? '')),
    // remaining = not yet repaired (default); all = include repaired history
    'status' => trim((string)($_GET['status'] ?? 'remaining')),
];
if (!in_array($filters['status'], ['remaining', 'repaired', 'all'], true)) {
    $filters['status'] = 'remaining';
}

$misposts = re_income_mispost_candidates($conn, $companyId);
$statusMap = $schemaReady ? re_income_reclass_active_status_map($conn, $companyId) : [];

$rows = [];
$filterOpts = ['obligation_type' => [], 'posted_account' => [], 'expected_account' => []];
$statsAll = ['total' => 0, 'remaining' => 0, 'repaired' => 0, 'remaining_amount' => 0.0, 'repaired_amount' => 0.0];
foreach ($misposts as $m) {
    $acc = re_income_reclass_resolve_accounts($conn, $companyId, $m);
    if ($acc['error']) {
        continue;
    }
    $ck = re_income_reclass_candidate_key(
        (int)($m['invoice_item_id'] ?? 0),
        (int)$m['journal_id'],
        (int)$acc['wrong']['id'],
        (int)$acc['correct']['id']
    );
    // Status uses stable journal+accounts identity (not candidate_key / item id)
    $stableFp = re_income_reclass_stable_fingerprint(
        (int)$m['journal_id'],
        (int)$acc['wrong']['id'],
        (int)$acc['correct']['id']
    );
    $status = $statusMap[$stableFp] ?? 'candidate';
    if ($status === 'repaired') {
        $statusLabel = 'Repaired';
    } elseif ($status === 'failed') {
        $statusLabel = 'Failed';
    } elseif ($status === 'skipped') {
        $statusLabel = 'Skipped';
    } elseif ($status === 'selected') {
        $statusLabel = 'Selected';
    } else {
        $statusLabel = 'Candidate';
    }
    $row = $m + [
        'candidate_key' => $ck,
        'wrong_account_id' => (int)$acc['wrong']['id'],
        'correct_account_id' => (int)$acc['correct']['id'],
        'repair_status' => $statusLabel,
        'repair_status_raw' => $status,
    ];
    $filterOpts['obligation_type'][$m['obligation_type']] = true;
    $filterOpts['posted_account'][$m['posted_income_code']] = true;
    $filterOpts['expected_account'][$m['expected_account_code']] = true;

    // Stats over all historical misposts (before status filter)
    $passBase = true;
    if ($filters['date_from'] !== '' && $m['invoice_date'] < $filters['date_from']) {
        $passBase = false;
    }
    if ($filters['date_to'] !== '' && $m['invoice_date'] > $filters['date_to']) {
        $passBase = false;
    }
    if ($filters['obligation_type'] !== '' && strcasecmp((string)$m['obligation_type'], $filters['obligation_type']) !== 0) {
        $passBase = false;
    }
    if ($filters['posted_account'] !== '' && (string)$m['posted_income_code'] !== $filters['posted_account']) {
        $passBase = false;
    }
    if ($filters['expected_account'] !== '' && (string)$m['expected_account_code'] !== $filters['expected_account']) {
        $passBase = false;
    }
    if ($filters['invoice'] !== '' && stripos((string)$m['invoice_number'], $filters['invoice']) === false) {
        $passBase = false;
    }
    if ($filters['journal'] !== '' && stripos((string)$m['journal_number'], $filters['journal']) === false) {
        $passBase = false;
    }
    if (!$passBase) {
        continue;
    }

    $statsAll['total']++;
    if ($status === 'repaired') {
        $statsAll['repaired']++;
        $statsAll['repaired_amount'] += (float)$m['posted_income_credit'];
    } else {
        $statsAll['remaining']++;
        $statsAll['remaining_amount'] += (float)$m['posted_income_credit'];
    }

    if ($filters['status'] === 'remaining' && $status === 'repaired') {
        continue;
    }
    if ($filters['status'] === 'repaired' && $status !== 'repaired') {
        continue;
    }

    $rows[] = $row;
}

if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $filename = 'income_mispost_report_company_' . $companyId . '_' . date('Y-m-d') . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    $out = fopen('php://output', 'w');
    fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF));
    if ($rows) {
        $headers = array_keys($rows[0]);
        fputcsv($out, $headers);
        foreach ($rows as $row) {
            fputcsv($out, $row);
        }
    } else {
        fputcsv($out, ['message']);
        fputcsv($out, ['No rows']);
    }
    fclose($out);
    exit;
}

$pageTitle = 'Income Mispost Report';
require_once __DIR__ . '/../includes/re_layout_header.php';
$qs = http_build_query(array_filter($filters, static fn($v) => $v !== ''));
?>

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <div class="page-header-label mb-0">
        <i class="bi bi-exclamation-diamond"></i> Income Mispost Report
    </div>
    <div class="d-flex gap-2 flex-wrap">
        <a href="income_reclass_sessions.php" class="btn btn-outline-secondary">Repair Sessions</a>
        <a href="?<?= h($qs) ?>&export=csv" class="btn btn-outline-primary"><i class="bi bi-download"></i> Export CSV</a>
    </div>
</div>

<div class="alert alert-warning">
    <strong>Important:</strong> Creating a repair session and executing repairs posts <em>separate</em> reclassification journals
    (Dr wrong income / Cr correct income). Original invoices, AR, VAT, receipts, allocations, and original journals are <strong>not</strong> modified.
</div>

<?php if ($flashError): ?><div class="alert alert-danger"><?= h($flashError) ?></div><?php endif; ?>
<?php if (!$schemaReady): ?>
<div class="alert alert-info">Repair tables not installed yet. Upload and apply <code>migrations/re_income_reclass_repair.sql</code> (after approval) to enable Create Repair Session.</div>
<?php endif; ?>

<form method="get" class="card card-round mb-3">
    <div class="card-body">
        <div class="row g-2">
            <div class="col-md-2"><label class="form-label">From</label><input type="date" name="date_from" class="form-control" value="<?= h($filters['date_from']) ?>"></div>
            <div class="col-md-2"><label class="form-label">To</label><input type="date" name="date_to" class="form-control" value="<?= h($filters['date_to']) ?>"></div>
            <div class="col-md-2">
                <label class="form-label">Obligation Type</label>
                <select name="obligation_type" class="form-select">
                    <option value="">All</option>
                    <?php foreach (array_keys($filterOpts['obligation_type']) as $t): ?>
                        <option value="<?= h($t) ?>" <?= strcasecmp($filters['obligation_type'], $t) === 0 ? 'selected' : '' ?>><?= h($t) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label">Posted Account</label>
                <select name="posted_account" class="form-select">
                    <option value="">All</option>
                    <?php foreach (array_keys($filterOpts['posted_account']) as $t): ?>
                        <option value="<?= h($t) ?>" <?= $filters['posted_account'] === $t ? 'selected' : '' ?>><?= h($t) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label">Expected Account</label>
                <select name="expected_account" class="form-select">
                    <option value="">All</option>
                    <?php foreach (array_keys($filterOpts['expected_account']) as $t): ?>
                        <option value="<?= h($t) ?>" <?= $filters['expected_account'] === $t ? 'selected' : '' ?>><?= h($t) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2"><label class="form-label">Invoice</label><input type="text" name="invoice" class="form-control" value="<?= h($filters['invoice']) ?>" placeholder="INV-"></div>
            <div class="col-md-2"><label class="form-label">Journal</label><input type="text" name="journal" class="form-control" value="<?= h($filters['journal']) ?>" placeholder="JRN-"></div>
            <div class="col-md-2">
                <label class="form-label">Status</label>
                <select name="status" class="form-select">
                    <option value="remaining" <?= $filters['status'] === 'remaining' ? 'selected' : '' ?>>Remaining only</option>
                    <option value="repaired" <?= $filters['status'] === 'repaired' ? 'selected' : '' ?>>Repaired only</option>
                    <option value="all" <?= $filters['status'] === 'all' ? 'selected' : '' ?>>All (history)</option>
                </select>
            </div>
            <div class="col-md-2 d-flex align-items-end"><button class="btn btn-primary w-100" type="submit">Filter</button></div>
        </div>
    </div>
</form>

<form method="post" id="repairForm">
    <?php csrf_field(); ?>
    <input type="hidden" name="action" value="create_session">
    <div class="card card-round mb-3">
        <div class="card-body">
            <div class="row g-2 align-items-end">
                <div class="col-md-2">
                    <div class="small text-muted">Historical total</div>
                    <div class="h5 mb-0"><?= (int)$statsAll['total'] ?></div>
                </div>
                <div class="col-md-2">
                    <div class="small text-muted">Already repaired</div>
                    <div class="h5 mb-0 text-success"><?= (int)$statsAll['repaired'] ?></div>
                </div>
                <div class="col-md-2">
                    <div class="small text-muted">Remaining to fix</div>
                    <div class="h5 mb-0 text-warning"><?= (int)$statsAll['remaining'] ?></div>
                </div>
                <div class="col-md-2">
                    <div class="small text-muted">Showing now</div>
                    <div class="h5 mb-0" id="filteredCount"><?= count($rows) ?></div>
                </div>
                <div class="col-md-2">
                    <div class="small text-muted">Selected count</div>
                    <div class="h5 mb-0" id="selectedCount">0</div>
                </div>
                <div class="col-md-2">
                    <div class="small text-muted">Selected amount</div>
                    <div class="h5 mb-0" id="selectedAmount">0.00 AED</div>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Correction date (if period locked)</label>
                    <input type="date" name="correction_date" class="form-control" value="">
                </div>
                <div class="col-md-6">
                    <label class="form-label">Session notes</label>
                    <input type="text" name="notes" class="form-control" placeholder="Optional">
                </div>
                <div class="col-md-6 d-flex gap-2 justify-content-end">
                    <button type="button" class="btn btn-outline-secondary" id="btnSelectAll">Select All Filtered</button>
                    <button type="button" class="btn btn-outline-secondary" id="btnClear">Clear</button>
                    <button type="submit" class="btn btn-warning" <?= $schemaReady ? '' : 'disabled' ?> onclick="return confirm('Create a repair session for the selected candidates? No journals are posted until Dry Run + Execute on the session page.');">
                        Create Repair Session
                    </button>
                </div>
            </div>
        </div>
    </div>

    <div class="card card-round mb-4">
        <div class="card-header d-flex justify-content-between">
            <strong>Misposted income lines</strong>
            <span class="badge bg-secondary"><?= count($rows) ?></span>
        </div>
        <div class="table-responsive">
            <table class="table table-sm table-hover mb-0" id="mispostTable">
                <thead class="table-light">
                    <tr>
                        <th></th>
                        <th>Status</th>
                        <th>Date</th>
                        <th>Invoice</th>
                        <th>Journal</th>
                        <th>Type</th>
                        <th>Item</th>
                        <th>Posted</th>
                        <th>Expected</th>
                        <th class="text-end">Amount</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!$rows): ?>
                        <tr><td colspan="10" class="text-center text-muted py-4">No matching candidates</td></tr>
                    <?php else: ?>
                        <?php foreach ($rows as $m): ?>
                            <?php $canSelect = !in_array($m['repair_status_raw'], ['repaired', 'selected'], true); ?>
                            <tr>
                                <td>
                                    <?php if ($canSelect): ?>
                                        <input type="checkbox" class="form-check-input row-check" name="candidate[]" value="<?= h($m['candidate_key']) ?>" data-amount="<?= h((string)$m['posted_income_credit']) ?>">
                                    <?php endif; ?>
                                </td>
                                <td><span class="badge bg-light text-dark"><?= h($m['repair_status']) ?></span></td>
                                <td><?= h($m['invoice_date']) ?></td>
                                <td><a href="../billing_invoice_view.php?id=<?= (int)$m['invoice_id'] ?>"><?= h($m['invoice_number']) ?></a></td>
                                <td><a href="journal_entry_view.php?id=<?= (int)$m['journal_id'] ?>"><?= h($m['journal_number']) ?></a></td>
                                <td><code><?= h($m['obligation_type']) ?></code></td>
                                <td class="small"><?= h($m['item_name']) ?></td>
                                <td><code><?= h($m['posted_income_code']) ?></code></td>
                                <td><code><?= h($m['expected_account_code']) ?></code></td>
                                <td class="text-end"><?= money_fmt($m['posted_income_credit']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</form>

<script>
(function(){
  const checks = () => Array.from(document.querySelectorAll('.row-check'));
  function refresh(){
    let n=0, amt=0;
    checks().forEach(c=>{ if(c.checked){ n++; amt += parseFloat(c.dataset.amount||0);} });
    document.getElementById('selectedCount').textContent = n;
    document.getElementById('selectedAmount').textContent = amt.toFixed(2) + ' AED';
  }
  document.getElementById('btnSelectAll')?.addEventListener('click', ()=>{ checks().forEach(c=>c.checked=true); refresh(); });
  document.getElementById('btnClear')?.addEventListener('click', ()=>{ checks().forEach(c=>c.checked=false); refresh(); });
  document.querySelectorAll('.row-check').forEach(c=>c.addEventListener('change', refresh));
  refresh();
})();
</script>

<?php require_once __DIR__ . '/../includes/re_layout_footer.php'; ?>
