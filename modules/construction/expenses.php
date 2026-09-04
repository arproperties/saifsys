<?php
/**
 * Construction — Quick Paid Expenses list (+ legacy Historical ERP archive rows).
 */
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../includes/branding.php';
require_once __DIR__ . '/../../includes/company_helper.php';
require_once __DIR__ . '/../../includes/module_access.php';
require_once __DIR__ . '/../../includes/rbac_department.php';
require_once __DIR__ . '/../../includes/erp_expense_posting.php';

require_login();
if (!has_department_access(MODULE_CONSTRUCTION, DEPT_CONSTRUCTION_FINANCIAL, $conn)) {
    require_module_access($conn, MODULE_CONSTRUCTION);
}

$brand = getBrandSettings($conn);
$currentCompanyId = (int)(current_company_id($conn) ?: 0);
if ($currentCompanyId <= 0) {
    http_response_code(400);
    die('Company context is required.');
}
$userId = current_user_id();
$sourceModule = 'construction';

$flashError = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    csrf_verify();
    $expenseId = (int)($_POST['id'] ?? 0);
    $res = erp_delete_expense($conn, $expenseId, $currentCompanyId, $userId, $sourceModule);
    if (!empty($res['success'])) {
        header('Location: expenses.php?ok=deleted');
        exit;
    }
    $flashError = (string)($res['error'] ?? 'Could not delete expense.');
}

$q = trim($_GET['q'] ?? '');
$supplierId = (int)($_GET['supplier_id'] ?? 0);
$projectId = (int)($_GET['project_id'] ?? 0);
$accountId = (int)($_GET['account_id'] ?? 0);
$paidVia = trim((string)($_GET['paid_via'] ?? ''));
$dFrom = $_GET['from'] ?? '';
$dTo = $_GET['to'] ?? '';
$status = $_GET['status'] ?? '';
$archiveFilter = trim((string)($_GET['archive'] ?? '')); // '', 'live', 'legacy'
if (!$dFrom && !$dTo) {
    $dFrom = date('Y-m-01');
    $dTo = date('Y-m-d');
}

$hasExpenseProjectColumn = false;
try {
    $colStmt = $conn->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'erp_expense_headers' AND COLUMN_NAME = 'project_id'");
    $colStmt->execute();
    $hasExpenseProjectColumn = ((int)$colStmt->fetchColumn() > 0);
} catch (Throwable $e) {
    $hasExpenseProjectColumn = false;
}
$hasLegacyCol = erp_expense_headers_has_legacy_archive_column($conn);

$where = ['e.company_id = ?', 'e.source_module = ?'];
$args = [$currentCompanyId, $sourceModule];
if ($q !== '') {
    $where[] = '(e.reference_no LIKE ? OR e.notes LIKE ? OR cs.supplier_name LIKE ? OR e.expense_number LIKE ?)';
    $like = '%' . $q . '%';
    array_push($args, $like, $like, $like, $like);
}
if ($supplierId > 0) {
    $where[] = 'e.co_supplier_id = ?';
    $args[] = $supplierId;
}
if ($hasExpenseProjectColumn && $projectId > 0) {
    $where[] = 'e.project_id = ?';
    $args[] = $projectId;
}
if ($accountId > 0) {
    $where[] = 'EXISTS (SELECT 1 FROM erp_expense_lines el WHERE el.expense_id = e.id AND el.account_id = ?)';
    $args[] = $accountId;
}
if ($paidVia !== '' && in_array($paidVia, ['cash', 'bank', 'credit', 'accounts_payable'], true)) {
    $where[] = 'e.paid_via = ?';
    $args[] = $paidVia;
}
if ($dFrom !== '') {
    $where[] = 'e.expense_date >= ?';
    $args[] = $dFrom;
}
if ($dTo !== '') {
    $where[] = 'e.expense_date <= ?';
    $args[] = $dTo;
}
if ($status !== '' && $status !== 'cancelled') {
    $where[] = 'e.status = ?';
    $args[] = $status;
} elseif ($status === '') {
    $where[] = "e.status != 'cancelled'";
} elseif ($status === 'cancelled') {
    $where[] = "e.status = 'cancelled'";
}
if ($hasLegacyCol && $archiveFilter === 'live') {
    $where[] = 'COALESCE(e.legacy_archive, 0) = 0';
} elseif ($hasLegacyCol && $archiveFilter === 'legacy') {
    $where[] = 'COALESCE(e.legacy_archive, 0) = 1';
}

$sql = "SELECT e.*, COALESCE(cs.supplier_name, v.vendor_name) AS vendor_name, p.project_name, p.project_code,
  (SELECT GROUP_CONCAT(DISTINCT CONCAT(coa.account_code, ' ', coa.account_name) ORDER BY coa.account_code SEPARATOR ', ')
     FROM erp_expense_lines el
     JOIN re_chart_of_accounts coa ON coa.id = el.account_id AND coa.company_id = e.company_id
    WHERE el.expense_id = e.id) AS expense_accounts
  FROM erp_expense_headers e
  LEFT JOIN re_vendors v ON v.id = e.vendor_id AND v.company_id = e.company_id
  LEFT JOIN co_suppliers cs ON cs.id = e.co_supplier_id AND cs.company_id = e.company_id
  LEFT JOIN co_projects p ON p.id = e.project_id AND p.company_id = e.company_id
  WHERE " . implode(' AND ', $where) . " ORDER BY e.expense_date DESC, e.id DESC LIMIT 500";
$rows = [];
$tableMissing = false;
try {
    $st = $conn->prepare($sql);
    $st->execute($args);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    if (strpos($e->getMessage(), 'erp_expense') !== false || strpos($e->getMessage(), 'co_supplier') !== false) {
        $tableMissing = true;
    } else {
        throw $e;
    }
}

$suppliers = [];
try {
    $sst = $conn->prepare("SELECT id, supplier_name FROM co_suppliers WHERE company_id = ? AND is_active = 1 ORDER BY supplier_name");
    $sst->execute([$currentCompanyId]);
    $suppliers = $sst->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $suppliers = [];
}

$projects = [];
if ($hasExpenseProjectColumn) {
    try {
        $pst = $conn->prepare("SELECT id, project_name, project_code FROM co_projects WHERE company_id = ? ORDER BY project_name");
        $pst->execute([$currentCompanyId]);
        $projects = $pst->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $projects = [];
    }
}

$expenseAccounts = [];
try {
    $ast = $conn->prepare("
        SELECT DISTINCT coa.id, coa.account_code, coa.account_name
        FROM erp_expense_lines el
        JOIN erp_expense_headers e ON e.id = el.expense_id
        JOIN re_chart_of_accounts coa ON coa.id = el.account_id AND coa.company_id = e.company_id
        WHERE e.company_id = ? AND e.source_module = ?
        ORDER BY coa.account_code
        LIMIT 300
    ");
    $ast->execute([$currentCompanyId, $sourceModule]);
    $expenseAccounts = $ast->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    $expenseAccounts = [];
}

$totalPeriod = 0;
foreach ($rows as $r) {
    $totalPeriod += (float)$r['total'];
}
$countPeriod = count($rows);
$avgPeriod = $countPeriod > 0 ? $totalPeriod / $countPeriod : 0;

$cms = date('Y-m-01');
$cme = date('Y-m-d');
$stmt = $conn->prepare("SELECT COUNT(*) as cnt, COALESCE(SUM(total),0) as tot FROM erp_expense_headers WHERE company_id=? AND source_module=? AND expense_date>=? AND expense_date<=? AND status='posted'");
$stmt->execute([$currentCompanyId, $sourceModule, $cms, $cme]);
$currentMonth = $stmt->fetch(PDO::FETCH_ASSOC);
$lms = date('Y-m-01', strtotime('-1 month'));
$lme = date('Y-m-t', strtotime('-1 month'));
$stmt->execute([$currentCompanyId, $sourceModule, $lms, $lme]);
$lastMonth = $stmt->fetch(PDO::FETCH_ASSOC);

function h($s) {
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}
function m($n) {
    return number_format((float)$n, 2, '.', ',');
}

$pageTitle = 'Quick Paid Expenses';
require_once __DIR__ . '/includes/construction_layout_header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <div>
        <h4 class="mb-0"><i class="bi bi-cash-coin me-2"></i>Quick Paid Expenses</h4>
        <small class="text-muted">Immediate cash/bank/credit-card expenses (no Supplier AP). Legacy Historical ERP rows stay read-only.</small>
    </div>
    <div class="d-flex gap-2">
        <a href="supplier_invoice_add.php" class="btn btn-outline-primary"><i class="bi bi-receipt-cutoff"></i> Supplier Invoice</a>
        <a href="expense_add.php" class="btn btn-primary"><i class="bi bi-plus-lg"></i> Add Quick Paid Expense</a>
    </div>
</div>
<div class="alert alert-info">
    <strong>Quick Paid</strong> = Dr Expense / Dr Input VAT (2130) / Cr Bank|Cash|Credit Card — never creates Supplier Invoices, Outstanding AP, or Advances.
    Unpaid bills → <a href="supplier_invoices.php">Supplier Invoices</a>. Project is optional (blank = overhead).
</div>
<?php if ($tableMissing): ?>
<div class="alert alert-warning">Run <code>migrations/erp_expenses.sql</code>, Construction project/supplier expense migrations, and <code>migrations/construction_quick_paid_expenses.sql</code>.</div>
<?php endif; ?>
<?php if (isset($_GET['ok']) && $_GET['ok'] === '1'): ?><div class="alert alert-success alert-dismissible">Saved and posted.<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div><?php endif; ?>
<?php if (isset($_GET['saved']) && $_GET['saved'] === 'draft'): ?><div class="alert alert-info alert-dismissible">Draft saved (not posted).<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div><?php endif; ?>
<?php if (isset($_GET['ok']) && $_GET['ok'] === 'cancelled'): ?><div class="alert alert-secondary alert-dismissible">Expense cancelled.<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div><?php endif; ?>
<?php if (isset($_GET['ok']) && $_GET['ok'] === 'deleted'): ?><div class="alert alert-success alert-dismissible">Quick Paid Expense deleted. Posted journal was reversed if present.<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div><?php endif; ?>
<?php if ($flashError !== ''): ?><div class="alert alert-danger alert-dismissible"><?= h($flashError) ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div><?php endif; ?>

<div class="row g-3 mb-4">
    <div class="col-md-3"><div class="card card-round h-100 border-start border-primary border-4"><div class="card-body">
        <h6 class="text-muted">Selected period</h6><h4><?= m($totalPeriod) ?> <small class="fs-6">AED</small></h4><small class="text-muted"><?= (int)$countPeriod ?> expense(s)</small>
    </div></div></div>
    <div class="col-md-3"><div class="card card-round h-100"><div class="card-body">
        <h6 class="text-muted">This month (posted)</h6><h4><?= m($currentMonth['tot'] ?? 0) ?> <small class="fs-6">AED</small></h4>
    </div></div></div>
    <div class="col-md-3"><div class="card card-round h-100"><div class="card-body">
        <h6 class="text-muted">Last month (posted)</h6><h4><?= m($lastMonth['tot'] ?? 0) ?> <small class="fs-6">AED</small></h4>
    </div></div></div>
    <div class="col-md-3"><div class="card card-round h-100"><div class="card-body">
        <h6 class="text-muted">Average</h6><h4><?= m($avgPeriod) ?> <small class="fs-6">AED</small></h4>
    </div></div></div>
</div>

<div class="card card-round mb-4"><div class="card-body">
    <form method="get" class="row g-2 align-items-end">
        <div class="col-md-2"><label class="form-label">Search</label><input type="text" name="q" class="form-control" value="<?= h($q) ?>"></div>
        <div class="col-md-2"><label class="form-label">Supplier</label>
            <select name="supplier_id" class="form-select"><option value="0">All</option><?php foreach ($suppliers as $s): ?>
                <option value="<?= (int)$s['id'] ?>" <?= $supplierId === (int)$s['id'] ? 'selected' : '' ?>><?= h($s['supplier_name']) ?></option><?php endforeach; ?></select></div>
        <?php if ($hasExpenseProjectColumn): ?>
        <div class="col-md-2"><label class="form-label">Project</label>
            <select name="project_id" class="form-select"><option value="0">All</option><?php foreach ($projects as $p): ?>
                <option value="<?= (int)$p['id'] ?>" <?= $projectId === (int)$p['id'] ? 'selected' : '' ?>><?= h(trim(($p['project_code'] ? $p['project_code'] . ' — ' : '') . $p['project_name'])) ?></option><?php endforeach; ?></select></div>
        <?php endif; ?>
        <div class="col-md-2"><label class="form-label">Expense Account</label>
            <select name="account_id" class="form-select"><option value="0">All</option><?php foreach ($expenseAccounts as $a): ?>
                <option value="<?= (int)$a['id'] ?>" <?= $accountId === (int)$a['id'] ? 'selected' : '' ?>><?= h($a['account_code'] . ' — ' . $a['account_name']) ?></option><?php endforeach; ?></select></div>
        <div class="col-md-1"><label class="form-label">Pay method</label>
            <select name="paid_via" class="form-select">
                <option value="" <?= $paidVia === '' ? 'selected' : '' ?>>All</option>
                <option value="cash" <?= $paidVia === 'cash' ? 'selected' : '' ?>>Cash</option>
                <option value="bank" <?= $paidVia === 'bank' ? 'selected' : '' ?>>Bank</option>
                <option value="credit" <?= $paidVia === 'credit' ? 'selected' : '' ?>>Credit Card</option>
                <option value="accounts_payable" <?= $paidVia === 'accounts_payable' ? 'selected' : '' ?>>AP (legacy)</option>
            </select></div>
        <div class="col-md-1"><label class="form-label">From</label><input type="date" name="from" class="form-control" value="<?= h($dFrom) ?>"></div>
        <div class="col-md-1"><label class="form-label">To</label><input type="date" name="to" class="form-control" value="<?= h($dTo) ?>"></div>
        <div class="col-md-1"><label class="form-label">Status</label>
            <select name="status" class="form-select">
                <option value="" <?= $status === '' ? 'selected' : '' ?>>Active</option>
                <option value="draft" <?= $status === 'draft' ? 'selected' : '' ?>>Draft</option>
                <option value="posted" <?= $status === 'posted' ? 'selected' : '' ?>>Posted</option>
                <option value="cancelled" <?= $status === 'cancelled' ? 'selected' : '' ?>>Cancelled</option>
            </select></div>
        <?php if ($hasLegacyCol): ?>
        <div class="col-md-1"><label class="form-label">Archive</label>
            <select name="archive" class="form-select">
                <option value="" <?= $archiveFilter === '' ? 'selected' : '' ?>>All</option>
                <option value="live" <?= $archiveFilter === 'live' ? 'selected' : '' ?>>Quick Paid</option>
                <option value="legacy" <?= $archiveFilter === 'legacy' ? 'selected' : '' ?>>Historical</option>
            </select></div>
        <?php endif; ?>
        <div class="col-md-1"><button type="submit" class="btn btn-primary w-100"><i class="bi bi-search"></i></button></div>
    </form>
</div></div>

<div class="card card-round"><div class="card-body p-0"><div class="table-responsive">
    <table class="table table-hover mb-0"><thead class="table-light"><tr>
        <th>Date</th><th>Expense #</th><th>Project</th><th>Supplier</th><th>Expense Account</th><th>Paid via</th><th class="text-end">Total AED</th><th>Status</th><th></th>
    </tr></thead><tbody>
    <?php if (empty($rows)): ?>
        <tr><td colspan="9" class="text-center text-muted py-4">No expenses found for these filters.</td></tr>
    <?php else: foreach ($rows as $r):
        $isLegacy = $hasLegacyCol && (int)($r['legacy_archive'] ?? 0) === 1;
    ?>
        <tr>
            <td><?= h($r['expense_date']) ?></td>
            <td><?= h($r['expense_number'] ?: '—') ?><?php if ($isLegacy): ?> <span class="badge bg-secondary">Historical</span><?php endif; ?></td>
            <td><?= !empty($r['project_id']) ? h(trim((($r['project_code'] ?? '') !== '' ? ($r['project_code'] . ' — ') : '') . ($r['project_name'] ?? ''))) : '<span class="text-muted">Overhead</span>' ?></td>
            <td><?= h($r['vendor_name'] ?: '—') ?></td>
            <td class="small"><?= h($r['expense_accounts'] ?: '—') ?></td>
            <td><?php
                $pv = $r['paid_via'] ?? '';
                $pvl = ['cash'=>'Cash','bank'=>'Bank','credit'=>'Credit Card','accounts_payable'=>'Accounts payable','ap'=>'Accounts payable'];
                echo h($pvl[$pv] ?? $pv);
            ?></td>
            <td class="text-end"><?= m($r['total']) ?></td>
            <td><span class="badge bg-<?= ($r['status'] ?? '') === 'cancelled' ? 'secondary' : (($r['status'] ?? '') === 'draft' ? 'warning text-dark' : 'success') ?>"><?= h($r['status'] ?? '') ?></span></td>
            <td class="text-nowrap">
                <?php if (($r['status'] ?? '') !== 'cancelled'): ?>
                    <a class="btn btn-sm btn-outline-<?= $isLegacy ? 'secondary' : 'primary' ?>" href="expense_edit.php?id=<?= (int)$r['id'] ?>"><?= $isLegacy ? 'View' : 'Open' ?></a>
                <?php endif; ?>
                <?php if (!$isLegacy): ?>
                    <button type="button"
                            class="btn btn-sm btn-outline-danger"
                            title="Delete expense"
                            data-bs-toggle="modal"
                            data-bs-target="#deleteExpenseModal<?= (int)$r['id'] ?>">
                        <i class="bi bi-trash"></i> Delete
                    </button>
                <?php endif; ?>
            </td>
        </tr>
    <?php endforeach; endif; ?>
    </tbody></table>
</div></div></div>

<?php foreach ($rows as $r):
    $isLegacy = $hasLegacyCol && (int)($r['legacy_archive'] ?? 0) === 1;
    if ($isLegacy) {
        continue;
    }
?>
<div class="modal fade" id="deleteExpenseModal<?= (int)$r['id'] ?>" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="post">
                <?php csrf_field(); ?>
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                <div class="modal-header">
                    <h5 class="modal-title text-danger"><i class="bi bi-trash"></i> Delete Quick Paid Expense</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p class="mb-2">
                        Permanently delete
                        <strong><?= h($r['expense_number'] ?: ('#' . (int)$r['id'])) ?></strong>
                        (<?= m($r['total']) ?> AED<?= !empty($r['vendor_name']) ? ' — ' . h($r['vendor_name']) : '' ?>)?
                    </p>
                    <ul class="small text-muted mb-0">
                        <?php if (($r['status'] ?? '') === 'posted' || !empty($r['journal_id'])): ?>
                            <li>The posted journal will be <strong>reversed</strong> (offsetting entry) so books stay balanced.</li>
                        <?php elseif (($r['status'] ?? '') === 'draft'): ?>
                            <li>This draft has no posted journal.</li>
                        <?php else: ?>
                            <li>Any linked journal was already reversed when cancelled.</li>
                        <?php endif; ?>
                        <li>The expense record (header, lines, attachments) will be removed.</li>
                        <li>This cannot be undone.</li>
                    </ul>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Keep expense</button>
                    <button type="submit" class="btn btn-danger">Delete expense</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endforeach; ?>
<?php require_once __DIR__ . '/includes/construction_layout_footer.php';
