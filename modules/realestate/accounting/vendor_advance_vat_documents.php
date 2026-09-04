<?php
/**
 * Vendor Advance VAT Documents — list
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

$schemaReady = re_ap_advance_vat_table_ready($conn);
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
        SELECT d.*, v.vendor_name,
               COALESCE((
                   SELECT SUM(l.vat_amount_linked)
                   FROM re_vendor_advance_vat_bill_links l
                   WHERE l.company_id = d.company_id
                     AND l.advance_vat_document_id = d.id
                     AND l.status = 'posted'
               ), 0) AS vat_consumed
        FROM re_vendor_advance_vat_documents d
        JOIN re_vendors v ON v.id = d.vendor_id AND v.company_id = d.company_id
        WHERE d.company_id = ?
    ";
    $params = [$companyId];
    if ($vendorId > 0) {
        $sql .= " AND d.vendor_id = ?";
        $params[] = $vendorId;
    }
    if (in_array($status, ['draft', 'posted', 'reversed'], true)) {
        $sql .= " AND d.status = ?";
        $params[] = $status;
    }
    if ($q !== '') {
        $sql .= " AND (d.supplier_invoice_number LIKE ? OR v.vendor_name LIKE ? OR CONCAT('PAY-', d.vendor_payment_id) LIKE ?)";
        $like = '%' . $q . '%';
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
    }
    $sql .= " ORDER BY d.created_at DESC, d.id DESC LIMIT 500";
    $st = $conn->prepare($sql);
    $st->execute($params);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

$pageTitle = 'Advance VAT Documents';
require_once __DIR__ . '/../includes/re_layout_header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <div class="page-header-label"><i data-lucide="file-badge" class="me-1"></i> Advance VAT Documents</div>
    <div>
        <a href="vendor_advance_vat_document_edit.php" class="btn btn-primary<?= $schemaReady ? '' : ' disabled' ?>">New VAT Document</a>
    </div>
</div>

<?php if (!$schemaReady): ?>
    <div class="alert alert-warning">Advance VAT schema is not installed. Run <code>migrations/re_vendor_advance_vat_documents.sql</code> before using this feature.</div>
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
                    <?php foreach (['draft', 'posted', 'reversed'] as $s): ?>
                        <option value="<?= $s ?>" <?= $status === $s ? 'selected' : '' ?>><?= ucfirst($s) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label">Search</label>
                <input type="text" name="q" class="form-control" value="<?= h($q) ?>" placeholder="Invoice #, vendor, PAY-#">
            </div>
            <div class="col-md-2">
                <button class="btn btn-outline-primary w-100">Filter</button>
            </div>
        </form>
    </div>
</div>

<div class="card card-round">
    <div class="table-responsive">
        <table class="table table-hover mb-0">
            <thead class="table-light">
                <tr>
                    <th>Supplier Inv #</th>
                    <th>Date</th>
                    <th>Vendor</th>
                    <th>Payment</th>
                    <th class="text-end">Taxable</th>
                    <th class="text-end">VAT</th>
                    <th class="text-end">Remaining</th>
                    <th>Status</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php if (!$rows): ?>
                    <tr><td colspan="9" class="text-muted text-center py-4">No advance VAT documents found.</td></tr>
                <?php endif; ?>
                <?php foreach ($rows as $r): ?>
                    <?php
                    $rem = max(0, (float)$r['vat_amount'] - (float)$r['vat_consumed']);
                    $badge = ($r['status'] === 'posted') ? 'success' : (($r['status'] === 'draft') ? 'warning text-dark' : 'secondary');
                    ?>
                    <tr>
                        <td><strong><?= h($r['supplier_invoice_number']) ?></strong></td>
                        <td><?= h($r['supplier_invoice_date']) ?></td>
                        <td><?= h($r['vendor_name']) ?></td>
                        <td><a href="vendor_payments.php?q=PAY-<?= (int)$r['vendor_payment_id'] ?>">PAY-<?= (int)$r['vendor_payment_id'] ?></a></td>
                        <td class="text-end"><?= m($r['taxable_amount']) ?></td>
                        <td class="text-end"><?= m($r['vat_amount']) ?></td>
                        <td class="text-end"><?= ($r['status'] === 'posted') ? m($rem) : '—' ?></td>
                        <td><span class="badge bg-<?= $badge ?>"><?= h($r['status']) ?></span></td>
                        <td class="text-end"><a class="btn btn-sm btn-outline-primary" href="vendor_advance_vat_document_view.php?id=<?= (int)$r['id'] ?>">Open</a></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php require_once __DIR__ . '/../includes/re_layout_footer.php'; ?>
