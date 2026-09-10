<?php
/**
 * Real Estate Module - Bounced Cheques
 * All bounced cheques with follow-up remarks and CSV export
 */

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../includes/branding.php';
require_once __DIR__ . '/../../includes/company_helper.php';
require_once __DIR__ . '/../../includes/module_access.php';
require_once __DIR__ . '/../../includes/rbac_department.php';
require_once __DIR__ . '/../../includes/csrf.php';

require_login();
// Same access as billing.php
if (!has_department_access(MODULE_REALESTATE, DEPT_REALESTATE_FINANCIAL, $conn)) {
    require_module_access($conn, MODULE_REALESTATE);
}

$brand = getBrandSettings($conn);
$currentCompanyId = current_company_id($conn) ?: 1;
$currentUserId = $_SESSION['user_id'] ?? null;
$success = $_SESSION['success'] ?? '';
$error = $_SESSION['error'] ?? '';
unset($_SESSION['success'], $_SESSION['error']);

if (!function_exists('h')) {
    function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
}

// Detect optional columns so the page still loads before the migrations are run.
$pdcColumns = [];
try {
    $colStmt = $conn->prepare("
        SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 're_post_dated_cheques'
    ");
    $colStmt->execute();
    $pdcColumns = array_flip($colStmt->fetchAll(PDO::FETCH_COLUMN));
} catch (Throwable $e) {
    $pdcColumns = [];
}
$hasRemarksCols = isset($pdcColumns['bounce_remarks'], $pdcColumns['bounce_remarks_updated_by'], $pdcColumns['bounce_remarks_updated_at']);
$hasSettlementCols = isset($pdcColumns['settlement_method']);

// A cheque counts as bounced while it is still 'bounced', or after it was replaced/held
// (those keep bounced_date; cleared/cancelled/returned clear it).
$bouncedWhere = "(c.status = 'bounced' OR c.bounced_date IS NOT NULL)";

// Save remarks
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    if (($_POST['action'] ?? '') === 'save_remarks') {
        $chequeId = (int)($_POST['cheque_id'] ?? 0);
        $remarks = trim((string)($_POST['remarks'] ?? ''));
        if (!$hasRemarksCols) {
            $_SESSION['error'] = 'Remarks cannot be saved until the bounced cheque remarks migration is run.';
        } elseif ($chequeId <= 0) {
            $_SESSION['error'] = 'Invalid cheque.';
        } else {
            $remarks = mb_substr($remarks, 0, 2000);
            $stmt = $conn->prepare("
                UPDATE re_post_dated_cheques c
                SET c.bounce_remarks = ?, c.bounce_remarks_updated_by = ?, c.bounce_remarks_updated_at = NOW()
                WHERE c.id = ? AND c.company_id = ? AND {$bouncedWhere}
            ");
            $stmt->execute([$remarks !== '' ? $remarks : null, $currentUserId, $chequeId, $currentCompanyId]);
            if ($stmt->rowCount() > 0) {
                $_SESSION['success'] = 'Remarks saved.';
            } else {
                $_SESSION['error'] = 'Cheque not found or not bounced.';
            }
        }
    }
    header('Location: billing_bounced_cheques.php' . (!empty($_SERVER['QUERY_STRING']) ? '?' . $_SERVER['QUERY_STRING'] : ''));
    exit;
}

// Filters
$buildingIdFilter = !empty($_GET['building_id']) ? (int)$_GET['building_id'] : 0;
$settlementFilter = in_array($_GET['settlement'] ?? '', ['open', 'settled'], true) ? $_GET['settlement'] : 'all';
$dateFrom = $_GET['date_from'] ?? '';
$dateTo = $_GET['date_to'] ?? '';
$search = trim($_GET['q'] ?? '');
$exportCsv = ($_GET['export'] ?? '') === 'csv';

$where = ["c.company_id = ?", $bouncedWhere];
$params = [$currentCompanyId];

if ($buildingIdFilter > 0) {
    $where[] = "b.id = ?";
    $params[] = $buildingIdFilter;
}
if ($dateFrom !== '') {
    $where[] = "COALESCE(c.bounced_date, c.cheque_date) >= ?";
    $params[] = $dateFrom;
}
if ($dateTo !== '') {
    $where[] = "COALESCE(c.bounced_date, c.cheque_date) <= ?";
    $params[] = $dateTo;
}
if ($search !== '') {
    $where[] = "(c.cheque_number LIKE ? OR u.unit_number LIKE ? OR l.lease_number LIKE ? OR CONCAT(t.first_name, ' ', t.last_name) LIKE ?)";
    $like = '%' . $search . '%';
    array_push($params, $like, $like, $like, $like);
}

// "Open" = still bounced, installment not paid, no other settlement recorded.
$openSql = "(c.status = 'bounced' AND COALESCE(li.status, '') <> 'paid'"
    . ($hasSettlementCols ? " AND c.settlement_method IS NULL" : "")
    . ")";
if ($settlementFilter === 'open') {
    $where[] = $openSql;
} elseif ($settlementFilter === 'settled') {
    $where[] = "NOT {$openSql}";
}

$stmt = $conn->prepare("
    SELECT
        c.*,
        l.lease_number,
        l.status AS lease_status,
        u.unit_number,
        b.id AS building_id,
        b.name AS building_name,
        t.first_name,
        t.last_name,
        t.phone AS tenant_phone,
        li.status AS installment_status,
        " . ($hasRemarksCols ? "ru.username AS remarks_updated_by_name," : "NULL AS remarks_updated_by_name,") . "
        (SELECT lc.id FROM re_legal_cases lc WHERE lc.primary_cheque_id = c.id AND lc.deleted_at IS NULL ORDER BY lc.id DESC LIMIT 1) AS legal_case_id
    FROM re_post_dated_cheques c
    JOIN re_leases l ON l.id = c.lease_id
    JOIN re_units u ON u.id = l.unit_id
    JOIN re_buildings b ON b.id = u.building_id
    JOIN re_tenants t ON t.id = l.tenant_id
    LEFT JOIN re_lease_installments li ON li.id = c.installment_id
    " . ($hasRemarksCols ? "LEFT JOIN user ru ON ru.id = c.bounce_remarks_updated_by" : "") . "
    WHERE " . implode(' AND ', $where) . "
    ORDER BY COALESCE(c.bounced_date, c.cheque_date) DESC, c.id DESC
");
$stmt->execute($params);
$cheques = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Settlement label for a row; also used for the open/settled stats.
function bounced_cheque_settlement(array $c): array {
    if (!empty($c['settlement_method'])) {
        return ['open' => false, 'label' => 'Settled by ' . ucwords(str_replace('_', ' ', $c['settlement_method'])), 'class' => 'success'];
    }
    if (($c['installment_status'] ?? '') === 'paid') {
        return ['open' => false, 'label' => 'Installment paid', 'class' => 'success'];
    }
    if (($c['status'] ?? '') === 'replaced') {
        return ['open' => false, 'label' => 'Replaced by new cheque', 'class' => 'info'];
    }
    if (($c['status'] ?? '') === 'bounced') {
        return ['open' => true, 'label' => 'Open', 'class' => 'danger'];
    }
    return ['open' => false, 'label' => ucwords(str_replace('_', ' ', (string)$c['status'])), 'class' => 'secondary'];
}

$stats = ['count' => 0, 'amount' => 0.0, 'open_count' => 0, 'open_amount' => 0.0];
foreach ($cheques as &$cheque) {
    $cheque['_settlement'] = bounced_cheque_settlement($cheque);
    $stats['count']++;
    $stats['amount'] += (float)$cheque['cheque_amount'];
    if ($cheque['_settlement']['open']) {
        $stats['open_count']++;
        $stats['open_amount'] += (float)$cheque['cheque_amount'];
    }
}
unset($cheque);

if ($exportCsv) {
    // Stop spreadsheet apps treating free text as a formula.
    $cell = function ($v) {
        $v = (string)$v;
        return ($v !== '' && strpbrk($v[0], "=+-@\t\r") !== false) ? "'" . $v : $v;
    };
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="bounced-cheques-' . date('Ymd-His') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Cheque #', 'Cheque Date', 'Bounced Date', 'Building', 'Unit', 'Lease', 'Lease Status', 'Tenant', 'Tenant Phone', 'Amount', 'Bank', 'Bounce Reason', 'Settlement', 'Legal Case', 'Remarks', 'Remarks Updated By', 'Remarks Updated At']);
    foreach ($cheques as $cheque) {
        fputcsv($out, [
            $cell($cheque['cheque_number']),
            $cheque['cheque_date'],
            $cheque['bounced_date'],
            $cell($cheque['building_name']),
            $cell($cheque['unit_number']),
            $cell($cheque['lease_number']),
            $cheque['lease_status'],
            $cell(trim($cheque['first_name'] . ' ' . $cheque['last_name'])),
            $cell($cheque['tenant_phone']),
            $cheque['cheque_amount'],
            $cell($cheque['bank_name']),
            $cell($cheque['bounced_reason']),
            $cheque['_settlement']['label'],
            !empty($cheque['legal_case_id']) ? 'Yes' : 'No',
            $cell($cheque['bounce_remarks'] ?? ''),
            $cell($cheque['remarks_updated_by_name'] ?? ''),
            $cheque['bounce_remarks_updated_at'] ?? '',
        ]);
    }
    exit;
}

$buildingsStmt = $conn->prepare("SELECT id, name FROM re_buildings WHERE company_id = ? AND is_active = 1 ORDER BY name");
$buildingsStmt->execute([$currentCompanyId]);
$buildings = $buildingsStmt->fetchAll(PDO::FETCH_ASSOC);

$exportQuery = $_GET;
$exportQuery['export'] = 'csv';
$exportUrl = 'billing_bounced_cheques.php?' . http_build_query($exportQuery);

$pageTitle = 'Bounced Cheques';
require_once __DIR__ . '/includes/re_layout_header.php';
?>
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h1><i class="bi bi-x-octagon"></i> Bounced Cheques</h1>
            <div class="d-flex gap-2">
                <a href="<?= h($exportUrl) ?>" class="btn btn-outline-success">
                    <i class="bi bi-file-earmark-spreadsheet"></i> Export CSV
                </a>
                <a href="billing.php" class="btn btn-secondary">
                    <i class="bi bi-arrow-left"></i> Billing
                </a>
            </div>
        </div>

        <?php if ($success): ?>
            <div class="alert alert-success"><?= h($success) ?></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="alert alert-danger"><?= h($error) ?></div>
        <?php endif; ?>
        <?php if (!$hasRemarksCols): ?>
            <div class="alert alert-warning">Remarks are read-only until <code>migrations/re_bounced_cheque_remarks.sql</code> is run.</div>
        <?php endif; ?>

        <div class="row g-3 mb-4">
            <div class="col-md-3 col-sm-6">
                <div class="card text-center border-danger">
                    <div class="card-body">
                        <h5 class="text-muted">Bounced Cheques</h5>
                        <h2 class="mb-0 text-danger"><?= (int)$stats['count'] ?></h2>
                    </div>
                </div>
            </div>
            <div class="col-md-3 col-sm-6">
                <div class="card text-center border-danger">
                    <div class="card-body">
                        <h5 class="text-muted">Bounced Amount</h5>
                        <h2 class="mb-0 text-danger"><?= number_format($stats['amount'], 2) ?> AED</h2>
                    </div>
                </div>
            </div>
            <div class="col-md-3 col-sm-6">
                <div class="card text-center border-warning">
                    <div class="card-body">
                        <h5 class="text-muted">Open</h5>
                        <h2 class="mb-0 text-warning"><?= (int)$stats['open_count'] ?></h2>
                    </div>
                </div>
            </div>
            <div class="col-md-3 col-sm-6">
                <div class="card text-center border-warning">
                    <div class="card-body">
                        <h5 class="text-muted">Open Amount</h5>
                        <h2 class="mb-0 text-warning"><?= number_format($stats['open_amount'], 2) ?> AED</h2>
                    </div>
                </div>
            </div>
        </div>

        <div class="card mb-4">
            <div class="card-body">
                <form method="GET" class="row g-3">
                    <div class="col-md-3">
                        <label class="form-label">Search</label>
                        <input type="text" name="q" class="form-control form-control-sm" value="<?= h($search) ?>" placeholder="Cheque #, tenant, unit, lease">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Building</label>
                        <select name="building_id" class="form-select form-select-sm">
                            <option value="">All Buildings</option>
                            <?php foreach ($buildings as $building): ?>
                                <option value="<?= (int)$building['id'] ?>" <?= $buildingIdFilter === (int)$building['id'] ? 'selected' : '' ?>><?= h($building['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Settlement</label>
                        <select name="settlement" class="form-select form-select-sm">
                            <option value="all" <?= $settlementFilter === 'all' ? 'selected' : '' ?>>All</option>
                            <option value="open" <?= $settlementFilter === 'open' ? 'selected' : '' ?>>Open</option>
                            <option value="settled" <?= $settlementFilter === 'settled' ? 'selected' : '' ?>>Settled / Replaced</option>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Bounced From</label>
                        <input type="date" name="date_from" class="form-control form-control-sm" value="<?= h($dateFrom) ?>">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Bounced To</label>
                        <input type="date" name="date_to" class="form-control form-control-sm" value="<?= h($dateTo) ?>">
                    </div>
                    <div class="col-md-1">
                        <label class="form-label">&nbsp;</label>
                        <button type="submit" class="btn btn-primary btn-sm w-100"><i class="bi bi-funnel"></i> Filter</button>
                        <a href="billing_bounced_cheques.php" class="btn btn-outline-secondary btn-sm w-100 mt-1">Reset</a>
                    </div>
                </form>
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <h5 class="mb-0">Bounced Cheques (<?= count($cheques) ?>)</h5>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-hover align-top">
                        <thead>
                            <tr>
                                <th>Cheque #</th>
                                <th>Bounced</th>
                                <th>Property</th>
                                <th>Tenant</th>
                                <th class="text-end">Amount</th>
                                <th>Bank / Reason</th>
                                <th>Settlement</th>
                                <th style="min-width: 260px;">Remarks</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if (empty($cheques)): ?>
                            <tr><td colspan="9" class="text-center text-muted">No bounced cheques found</td></tr>
                        <?php else: foreach ($cheques as $cheque): $cid = (int)$cheque['id']; ?>
                            <tr>
                                <td>
                                    <strong><?= h($cheque['cheque_number']) ?></strong><br>
                                    <small class="text-muted">Dated <?= h(date('M d, Y', strtotime($cheque['cheque_date']))) ?></small>
                                </td>
                                <td><?= !empty($cheque['bounced_date']) ? h(date('M d, Y', strtotime($cheque['bounced_date']))) : '-' ?></td>
                                <td>
                                    <?= h($cheque['building_name']) ?><br>
                                    <small class="text-muted">Unit <?= h($cheque['unit_number']) ?> | <?= h($cheque['lease_number']) ?></small>
                                    <?php if (($cheque['lease_status'] ?? '') !== 'active'): ?>
                                        <br><span class="badge bg-secondary"><?= h(ucfirst((string)$cheque['lease_status'])) ?> lease</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?= h(trim($cheque['first_name'] . ' ' . $cheque['last_name'])) ?>
                                    <?php if (!empty($cheque['tenant_phone'])): ?>
                                        <br><small class="text-muted"><?= h($cheque['tenant_phone']) ?></small>
                                    <?php endif; ?>
                                </td>
                                <td class="text-end"><strong><?= number_format((float)$cheque['cheque_amount'], 2) ?> AED</strong></td>
                                <td>
                                    <?= h($cheque['bank_name'] ?: '-') ?>
                                    <?php if (!empty($cheque['bounced_reason'])): ?>
                                        <br><small class="text-muted"><?= h($cheque['bounced_reason']) ?></small>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="badge bg-<?= h($cheque['_settlement']['class']) ?>"><?= h($cheque['_settlement']['label']) ?></span>
                                    <?php if (!empty($cheque['legal_case_id'])): ?>
                                        <br><small class="text-dark">Legal case linked</small>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div id="remarks-view-<?= $cid ?>">
                                        <?php if (!empty($cheque['bounce_remarks'])): ?>
                                            <div class="small"><?= nl2br(h($cheque['bounce_remarks'])) ?></div>
                                            <small class="text-muted">
                                                <?= h($cheque['remarks_updated_by_name'] ?: 'Unknown user') ?>,
                                                <?= h(date('M d, Y H:i', strtotime($cheque['bounce_remarks_updated_at']))) ?>
                                            </small>
                                        <?php else: ?>
                                            <span class="text-muted small">No remarks</span>
                                        <?php endif; ?>
                                        <?php if ($hasRemarksCols): ?>
                                            <div>
                                                <button type="button" class="btn btn-link btn-sm p-0 js-remarks-edit" data-id="<?= $cid ?>">
                                                    <i class="bi bi-pencil"></i> <?= !empty($cheque['bounce_remarks']) ? 'Edit' : 'Add' ?>
                                                </button>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                    <?php if ($hasRemarksCols): ?>
                                        <form method="POST" id="remarks-form-<?= $cid ?>" hidden>
                                            <?php csrf_field(); ?>
                                            <input type="hidden" name="action" value="save_remarks">
                                            <input type="hidden" name="cheque_id" value="<?= $cid ?>">
                                            <textarea name="remarks" class="form-control form-control-sm mb-1" rows="3" maxlength="2000" placeholder="Follow-up remarks"><?= h($cheque['bounce_remarks'] ?? '') ?></textarea>
                                            <button type="submit" class="btn btn-primary btn-sm">Save</button>
                                            <button type="button" class="btn btn-outline-secondary btn-sm js-remarks-cancel" data-id="<?= $cid ?>">Cancel</button>
                                        </form>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <a href="billing_cheque_view.php?id=<?= $cid ?>" class="btn btn-sm btn-primary">
                                        <i class="bi bi-eye"></i> View
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

<script>
document.addEventListener('click', function (e) {
    var btn = e.target.closest('.js-remarks-edit, .js-remarks-cancel');
    if (!btn) return;
    var id = btn.getAttribute('data-id');
    var editing = btn.classList.contains('js-remarks-edit');
    document.getElementById('remarks-view-' + id).hidden = editing;
    document.getElementById('remarks-form-' + id).hidden = !editing;
    if (editing) document.querySelector('#remarks-form-' + id + ' textarea').focus();
});
</script>

<?php require_once __DIR__ . '/includes/re_layout_footer.php'; ?>
