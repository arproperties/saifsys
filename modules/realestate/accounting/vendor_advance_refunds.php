<?php
/**
 * Vendor Advance Refunds — list
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

function h($s)
{
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}
function m($n)
{
    return number_format((float)$n, 2);
}

$schemaReady = re_ap_advance_refund_table_ready($conn);
$vendorId = (int)($_GET['vendor_id'] ?? 0);
$status = trim((string)($_GET['status'] ?? ''));
$q = trim((string)($_GET['q'] ?? ''));

$vendors = [];
$stV = $conn->prepare("SELECT id, vendor_name FROM re_vendors WHERE company_id = ? ORDER BY vendor_name");
$stV->execute([$companyId]);
$vendors = $stV->fetchAll(PDO::FETCH_ASSOC) ?: [];

$rows = [];
if ($schemaReady) {
    $sql = "
        SELECT r.*, v.vendor_name, vp.reference_number AS payment_ref,
               COALESCE(vp.advance_amount, 0) AS original_advance
        FROM re_vendor_advance_refunds r
        JOIN re_vendors v ON v.id = r.vendor_id AND v.company_id = r.company_id
        JOIN re_vendor_payments vp ON vp.id = r.vendor_payment_id AND vp.company_id = r.company_id
        WHERE r.company_id = ?
    ";
    $params = [$companyId];
    if ($vendorId > 0) {
        $sql .= " AND r.vendor_id = ?";
        $params[] = $vendorId;
    }
    if (in_array($status, ['posted', 'reversed'], true)) {
        $sql .= " AND r.status = ?";
        $params[] = $status;
    }
    if ($q !== '') {
        $sql .= " AND (r.reference_number LIKE ? OR v.vendor_name LIKE ? OR CONCAT('PAY-', r.vendor_payment_id) LIKE ? OR CONCAT('REF-', r.id) LIKE ?)";
        $like = '%' . $q . '%';
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
    }
    $sql .= " ORDER BY r.refund_date DESC, r.id DESC LIMIT 500";
    $st = $conn->prepare($sql);
    $st->execute($params);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

$pageTitle = 'Advance Refunds';
require_once __DIR__ . '/../includes/re_layout_header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <div class="page-header-label"><i data-lucide="undo-2" class="me-1"></i> Vendor Advance Refunds</div>
    <div>
        <a href="vendor_advance_refund_add.php" class="btn btn-primary<?= $schemaReady ? '' : ' disabled' ?>">New Refund</a>
    </div>
</div>

<?php if (!$schemaReady): ?>
    <div class="alert alert-warning">Advance refund schema is not installed. Run <code>migrations/re_vendor_advance_refunds.sql</code> before using this feature.</div>
<?php endif; ?>

<?php if (!empty($_GET['ok'])): ?>
    <div class="alert alert-success">Advance refund saved successfully.</div>
<?php endif; ?>

<div class="card card-round mb-3">
    <div class="card-body">
        <form method="get" class="row g-2 align-items-end">
            <div class="col-md-3">
                <label class="form-label">Vendor</label>
                <select name="vendor_id" class="form-select">
                    <option value="0">All vendors</option>
                    <?php foreach ($vendors as $v): ?>
                        <option value="<?= (int)$v['id'] ?>" <?= $vendorId === (int)$v['id'] ? 'selected' : '' ?>><?= h($v['vendor_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label">Status</label>
                <select name="status" class="form-select">
                    <option value="">All</option>
                    <?php foreach (['posted', 'reversed'] as $s): ?>
                        <option value="<?= $s ?>" <?= $status === $s ? 'selected' : '' ?>><?= ucfirst($s) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label">Search</label>
                <input type="text" name="q" class="form-control" value="<?= h($q) ?>" placeholder="Reference / vendor / PAY-">
            </div>
            <div class="col-md-2">
                <button class="btn btn-primary w-100">Filter</button>
            </div>
        </form>
    </div>
</div>

<div class="card card-round">
    <div class="table-responsive">
        <table class="table table-sm table-hover mb-0">
            <thead class="table-light">
                <tr>
                    <th>Date</th>
                    <th>Vendor</th>
                    <th>Refund #</th>
                    <th>Source Payment</th>
                    <th class="text-end">Amount</th>
                    <th>Status</th>
                    <th>Journal</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rows as $r): ?>
                    <tr>
                        <td><?= h($r['refund_date']) ?></td>
                        <td><?= h($r['vendor_name']) ?></td>
                        <td>REF-<?= (int)$r['id'] ?></td>
                        <td><?= h($r['payment_ref'] ?: ('PAY-' . $r['vendor_payment_id'])) ?></td>
                        <td class="text-end"><?= m($r['amount']) ?></td>
                        <td>
                            <span class="badge bg-<?= ($r['status'] ?? '') === 'posted' ? 'success' : 'secondary' ?>">
                                <?= h($r['status']) ?>
                            </span>
                        </td>
                        <td>
                            <?php if (!empty($r['journal_id'])): ?>
                                <a href="journal_entry_view.php?id=<?= (int)$r['journal_id'] ?>">Journal</a>
                            <?php endif; ?>
                        </td>
                        <td>
                            <a class="btn btn-sm btn-outline-primary" href="vendor_advance_refund_view.php?id=<?= (int)$r['id'] ?>">View</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$rows): ?>
                    <tr><td colspan="8" class="text-center text-muted py-4">No advance refunds yet.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/re_layout_footer.php'; ?>
