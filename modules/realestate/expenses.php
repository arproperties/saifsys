<?php
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../includes/branding.php';
require_once __DIR__ . '/../../includes/company_helper.php';
require_once __DIR__ . '/../../includes/module_access.php';
require_once __DIR__ . '/../../includes/rbac_department.php';
require_once __DIR__ . '/../../includes/erp_expense_posting.php';
require_once __DIR__ . '/../../includes/erp_expense_attachments.php';

require_login();
if (!has_department_access(MODULE_REALESTATE, DEPT_REALESTATE_FINANCIAL, $conn)) {
    require_module_access($conn, MODULE_REALESTATE);
}

$brand = getBrandSettings($conn);
$currentCompanyId = (int)(current_company_id($conn) ?: 0);
if ($currentCompanyId <= 0) {
    http_response_code(400);
    die('Company context is required.');
}
$userId = current_user_id();
$sourceModule = 'realestate';

$flashSuccess = '';
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
$vId = (int)($_GET['vendor_id'] ?? 0);
$dFrom = $_GET['from'] ?? '';
$dTo = $_GET['to'] ?? '';
$status = $_GET['status'] ?? '';
if (!$dFrom && !$dTo) { $dFrom = date('Y-m-01'); $dTo = date('Y-m-d'); }

$where = ['e.company_id = ?', 'e.source_module = ?'];
$args = [$currentCompanyId, $sourceModule];
if ($q !== '') {
    $where[] = '(e.reference_no LIKE ? OR e.notes LIKE ? OR v.vendor_name LIKE ? OR e.expense_number LIKE ?)';
    $like = '%' . $q . '%';
    array_push($args, $like, $like, $like, $like);
}
if ($vId > 0) { $where[] = 'e.vendor_id = ?'; $args[] = $vId; }
if ($dFrom !== '') { $where[] = 'e.expense_date >= ?'; $args[] = $dFrom; }
if ($dTo !== '') { $where[] = 'e.expense_date <= ?'; $args[] = $dTo; }
if ($status !== '' && $status !== 'cancelled') { $where[] = 'e.status = ?'; $args[] = $status; }
elseif ($status === '') { $where[] = "e.status != 'cancelled'"; }
elseif ($status === 'cancelled') { $where[] = "e.status = 'cancelled'"; }

$sql = "SELECT e.*, v.vendor_name FROM erp_expense_headers e
  LEFT JOIN re_vendors v ON v.id = e.vendor_id AND v.company_id = e.company_id
  WHERE " . implode(' AND ', $where) . " ORDER BY e.expense_date DESC, e.id DESC LIMIT 500";
$tableMissing = false;
$rows = [];
try {
    $st = $conn->prepare($sql);
    $st->execute($args);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    if (strpos($e->getMessage(), 'erp_expense') !== false) { $tableMissing = true; }
}

$vendors = $conn->prepare("SELECT id, vendor_name FROM re_vendors WHERE company_id = ? AND status = 'active' ORDER BY vendor_name");
$vendors->execute([$currentCompanyId]);
$vendors = $vendors->fetchAll(PDO::FETCH_ASSOC);

$totalPeriod = 0;
foreach ($rows as $r) { $totalPeriod += (float)$r['total']; }
$countPeriod = count($rows);
$avgPeriod = $countPeriod > 0 ? $totalPeriod / $countPeriod : 0;

$cms = date('Y-m-01'); $cme = date('Y-m-d');
$stmt = $conn->prepare("SELECT COUNT(*) as cnt, COALESCE(SUM(total),0) as tot FROM erp_expense_headers WHERE company_id=? AND source_module=? AND expense_date>=? AND expense_date<=? AND status='posted'");
$stmt->execute([$currentCompanyId, $sourceModule, $cms, $cme]);
$currentMonth = $stmt->fetch(PDO::FETCH_ASSOC);
$lms = date('Y-m-01', strtotime('-1 month')); $lme = date('Y-m-t', strtotime('-1 month'));
$stmt->execute([$currentCompanyId, $sourceModule, $lms, $lme]);
$lastMonth = $stmt->fetch(PDO::FETCH_ASSOC);

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function m($n) { return number_format((float)$n, 2, '.', ','); }

$pageTitle = 'Quick Paid Expenses';
require_once __DIR__ . '/includes/re_layout_header.php';
?>
        <div class="alert alert-warning"><strong>Quick Paid Expenses</strong> — immediately paid from bank/cash (no Accounts Payable). For unpaid purchases and formal AP, use <a href="accounting/vendor_bills.php" class="alert-link">Vendor Bills</a>.</div>
<div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h4 class="mb-0"><i class="bi bi-receipt-cutoff me-2"></i>Quick Paid Expenses</h4>
                <small class="text-muted">Dr expense + Input VAT / Cr bank or cash. Posted to company books.</small>
            </div>
            <a href="expense_add.php" class="btn btn-primary" style="background-color: var(--primary); border-color: var(--primary);"><i class="bi bi-plus-circle"></i> Add Quick Paid Expense</a>
        </div>
        <?php if ($tableMissing): ?>
        <div class="alert alert-warning">Run <code>migrations/erp_expenses.sql</code> to create ERP expense tables.</div>
        <?php endif; ?>
        <?php if (isset($_GET['ok']) && $_GET['ok'] === '1'): ?><div class="alert alert-success alert-dismissible">Saved and posted.<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div><?php endif; ?>
        <?= erp_expense_attachment_flash_html() ?>
        <?php if (isset($_GET['saved']) && $_GET['saved'] === 'draft'): ?><div class="alert alert-info alert-dismissible">Draft saved (not posted).<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div><?php endif; ?>
        <?php if (isset($_GET['ok']) && $_GET['ok'] === 'cancelled'): ?><div class="alert alert-secondary alert-dismissible">Expense cancelled.<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div><?php endif; ?>
        <?php if (isset($_GET['ok']) && $_GET['ok'] === 'deleted'): ?><div class="alert alert-success alert-dismissible">Quick Paid Expense deleted. Posted journal was reversed if present.<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div><?php endif; ?>
        <?php if ($flashError !== ''): ?><div class="alert alert-danger alert-dismissible"><?= h($flashError) ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div><?php endif; ?>

        <div class="row g-3 mb-4">
            <div class="col-md-3"><div class="card card-round h-100 border-start border-primary border-4"><div class="card-body">
                <h6 class="text-muted">Selected period</h6><h4><?= m($totalPeriod) ?> <small class="fs-6">AED</small></h4><small class="text-muted"><?= (int)$countPeriod ?> expense(s)</small>
            </div></div></div>
            <div class="col-md-3"><div class="card card-round h-100"><div class="card-body">
                <h6 class="text-muted">This month</h6><h4><?= m($currentMonth['tot'] ?? 0) ?> <small class="fs-6">AED</small></h4>
            </div></div></div>
            <div class="col-md-3"><div class="card card-round h-100"><div class="card-body">
                <h6 class="text-muted">Last month</h6><h4><?= m($lastMonth['tot'] ?? 0) ?> <small class="fs-6">AED</small></h4>
            </div></div></div>
            <div class="col-md-3"><div class="card card-round h-100"><div class="card-body">
                <h6 class="text-muted">Average</h6><h4><?= m($avgPeriod) ?> <small class="fs-6">AED</small></h4>
            </div></div></div>
        </div>

        <div class="card card-round mb-4"><div class="card-body">
            <form method="get" class="row g-2 align-items-end">
                <div class="col-md-3"><label class="form-label">Search</label><input type="text" name="q" class="form-control" value="<?= h($q) ?>"></div>
                <div class="col-md-2"><label class="form-label">Supplier</label>
                    <select name="vendor_id" class="form-select"><option value="0">All</option><?php foreach ($vendors as $v): ?>
                        <option value="<?= (int)$v['id'] ?>" <?= $vId === (int)$v['id'] ? 'selected' : '' ?>><?= h($v['vendor_name']) ?></option><?php endforeach; ?></select></div>
                <div class="col-md-2"><label class="form-label">From</label><input type="date" name="from" class="form-control" value="<?= h($dFrom) ?>"></div>
                <div class="col-md-2"><label class="form-label">To</label><input type="date" name="to" class="form-control" value="<?= h($dTo) ?>"></div>
                <div class="col-md-2"><label class="form-label">Status</label>
                    <select name="status" class="form-select">
                        <option value="" <?= $status===''?'selected':'' ?>>Active</option>
                        <option value="draft" <?= $status==='draft'?'selected':'' ?>>Draft</option>
                        <option value="posted" <?= $status==='posted'?'selected':'' ?>>Posted</option>
                        <option value="cancelled" <?= $status==='cancelled'?'selected':'' ?>>Cancelled</option>
                    </select></div>
                <div class="col-md-1"><button type="submit" class="btn btn-primary w-100"><i class="bi bi-search"></i></button></div>
            </form>
        </div></div>

        <div class="card card-round"><div class="card-body p-0"><div class="table-responsive">
            <table class="table table-hover mb-0"><thead class="table-light"><tr>
                <th>Date</th><th>Expense #</th><th>Supplier</th><th>Reference</th><th>Paid via</th><th class="text-end">Total</th><th>Status</th><th></th>
            </tr></thead><tbody>
            <?php if (empty($rows)): ?>
                <tr><td colspan="8" class="text-center text-muted py-4">No expenses. <a href="expense_add.php">Add one</a>.</td></tr>
            <?php else: foreach ($rows as $r): ?>
                <tr>
                    <td><?= h($r['expense_date']) ?></td>
                    <td><?= h($r['expense_number'] ?: '—') ?></td>
                    <td><?= h($r['vendor_name'] ?: '—') ?></td>
                    <td><?= h($r['reference_no'] ?: '—') ?></td>
                    <td><?php
                        $pv = $r['paid_via'] ?? '';
                        $pvl = ['cash'=>'Cash','bank'=>'Bank','credit'=>'Credit','accounts_payable'=>'Accounts payable','ap'=>'Accounts payable'];
                        echo h($pvl[$pv] ?? $pv);
                    ?></td>
                    <td class="text-end"><?= m($r['total']) ?></td>
                    <td><span class="badge bg-<?= ($r['status']??'')==='cancelled'?'secondary':(($r['status']??'')==='draft'?'warning text-dark':'success') ?>"><?= h($r['status'] ?? '') ?></span></td>
                    <td class="text-nowrap">
                        <?php if (($r['status'] ?? '') !== 'cancelled'): ?>
                            <a class="btn btn-sm btn-outline-primary" href="expense_edit.php?id=<?= (int)$r['id'] ?>">Edit</a>
                        <?php endif; ?>
                        <?php if (!empty($r['journal_id'])): ?>
                            <a class="btn btn-sm btn-outline-secondary" href="accounting/journal_entry_view.php?id=<?= (int)$r['journal_id'] ?>">Journal</a>
                        <?php endif; ?>
                        <button type="button"
                                class="btn btn-sm btn-outline-danger"
                                title="Delete expense"
                                data-bs-toggle="modal"
                                data-bs-target="#deleteExpenseModal<?= (int)$r['id'] ?>">
                            <i class="bi bi-trash"></i> Delete
                        </button>
                    </td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody></table>
        </div></div></div>

        <?php foreach ($rows as $r): ?>
        <div class="modal fade" id="deleteExpenseModal<?= (int)$r['id'] ?>" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog">
                <div class="modal-content">
                    <form method="POST">
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
<?php require_once __DIR__ . '/includes/re_layout_footer.php';
