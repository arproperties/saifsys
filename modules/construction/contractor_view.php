<?php
/**
 * Construction Module — Contractor Dashboard (operational + live Supplier/AP KPIs)
 */

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../includes/company_helper.php';
require_once __DIR__ . '/../../includes/module_access.php';
require_once __DIR__ . '/../../includes/rbac_department.php';
require_once __DIR__ . '/includes/construction_helpers.php';
require_once __DIR__ . '/includes/construction_contractor_supplier_helpers.php';

require_login();
require_module_access($conn, MODULE_CONSTRUCTION);

require_once __DIR__ . '/../../includes/branding.php';
$brand = getBrandSettings($conn);
$cid = current_company_id($conn);
if (!$cid) {
    http_response_code(403);
    die('Company context required.');
}
$id = (int)($_GET['id'] ?? 0);
if (!$id) { header('Location: contractors.php'); exit; }

$stmt = $conn->prepare("SELECT * FROM co_contractors WHERE id = ? AND company_id = ?");
$stmt->execute([$id, $cid]);
$c = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$c) { header('Location: contractors.php'); exit; }

$msg = '';
$err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && co_contractor_link_schema_ready($conn)) {
    if (function_exists('csrf_verify')) {
        csrf_verify();
    }
    $action = $_POST['link_action'] ?? '';
    if ($action === 'set_link') {
        $sid = (int)($_POST['supplier_id'] ?? 0);
        $res = co_contractor_set_supplier_link($conn, $cid, $id, $sid > 0 ? $sid : null);
        if ($res['ok']) {
            header('Location: contractor_view.php?id=' . $id . '&linked=1');
            exit;
        }
        $err = $res['error'] ?? 'Could not update link.';
    } elseif ($action === 'create_supplier') {
        $res = co_contractor_create_supplier_from_contractor($conn, $cid, $id, current_user_id());
        if ($res['ok']) {
            header('Location: contractor_view.php?id=' . $id . '&linked=1');
            exit;
        }
        $err = $res['error'] ?? 'Could not create supplier.';
    }
}
if (isset($_GET['linked'])) {
    $msg = 'Business Partner link updated.';
}

$progress = co_contractor_commercial_progress($conn, $cid, $id);
$assignments = co_contractor_project_assignments($conn, $cid, $id);
$linkableSuppliers = co_contractor_linkable_suppliers($conn, $cid, $id);
$payUrl = co_contractor_payment_workspace_url($conn, $cid, $id);

// Per-project commercial rows
$projectRows = [];
foreach ($assignments as $a) {
    $pprog = co_contractor_commercial_progress($conn, $cid, $id, (int)$a['project_id']);
    $cv = co_contractor_money($a['contract_value']);
    $pprog['contract_value'] = $cv;
    $pprog['remaining_contract_value'] = co_contractor_money(max(0, $cv - $pprog['total_invoiced']));
    $pprog['billing_progress_pct'] = $cv > 0.005 ? co_contractor_money(min(100, ($pprog['total_invoiced'] / $cv) * 100)) : 0.0;
    $pprog['payment_progress_pct'] = $cv > 0.005 ? co_contractor_money(min(100, ($pprog['total_paid'] / $cv) * 100)) : 0.0;
    $projectRows[] = array_merge($a, ['progress' => $pprog]);
}

$timeline = [];
if ($progress['linked'] && !empty($progress['supplier_id'])) {
    $timeline = co_supplier_financial_timeline($conn, $cid, (int)$progress['supplier_id'], 15, true);
}

$pageTitle = $c['contractor_name'];
require_once __DIR__ . '/includes/construction_layout_header.php';
?>

<div class="mb-4">
    <a href="contractors.php" class="btn btn-outline-secondary btn-sm mb-2"><i class="bi bi-arrow-left"></i> Back</a>
    <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
        <div>
            <h1 class="h4 mb-0"><?= h($c['contractor_name']) ?></h1>
            <span class="badge bg-<?= $c['is_active'] ? 'success' : 'secondary' ?>"><?= $c['is_active'] ? 'Active' : 'Inactive' ?></span>
            <?php if ($progress['linked']): ?>
                <span class="badge bg-info text-dark">Linked Supplier</span>
            <?php else: ?>
                <span class="badge bg-warning text-dark">No Supplier Link</span>
            <?php endif; ?>
        </div>
        <div class="d-flex flex-wrap gap-2">
            <?php if ($payUrl): ?>
                <a href="<?= h($payUrl) ?>" class="btn btn-success">Pay (Supplier Workspace)</a>
            <?php endif; ?>
            <?php if ($progress['linked'] && $progress['supplier_id']): ?>
                <a href="supplier_view.php?id=<?= (int)$progress['supplier_id'] ?>" class="btn btn-outline-success">Open Supplier Profile</a>
                <a href="supplier_invoice_add.php?supplier_id=<?= (int)$progress['supplier_id'] ?>" class="btn btn-primary">Add Invoice</a>
            <?php endif; ?>
            <a href="contractor_edit.php?id=<?= $id ?>" class="btn btn-outline-primary">Edit Contractor</a>
            <a href="project_contractors.php?contractor_id=<?= $id ?>" class="btn btn-outline-primary">Assign to Project</a>
        </div>
    </div>
</div>

<?php if ($msg): ?><div class="alert alert-success"><?= h($msg) ?></div><?php endif; ?>
<?php if ($err): ?><div class="alert alert-danger"><?= h($err) ?></div><?php endif; ?>

<?php if (!co_contractor_link_schema_ready($conn)): ?>
<div class="alert alert-warning">Run <code>migrations/construction_contractor_supplier_link.sql</code> to enable Business Partner linking.</div>
<?php endif; ?>

<div class="row g-3 mb-3">
    <div class="col-md-4 col-lg-3">
        <div class="card card-round h-100"><div class="card-body">
            <h6 class="text-muted small text-uppercase mb-1">Contract Value</h6>
            <div class="h5 mb-0"><?= co_format_money($progress['contract_value']) ?></div>
        </div></div>
    </div>
    <div class="col-md-4 col-lg-3">
        <div class="card card-round h-100"><div class="card-body">
            <h6 class="text-muted small text-uppercase mb-1">Total Invoiced</h6>
            <div class="h5 mb-0"><?= $progress['linked'] ? co_format_money($progress['total_invoiced']) : '—' ?></div>
            <small class="text-muted">Posted invoice subtotal</small>
        </div></div>
    </div>
    <div class="col-md-4 col-lg-3">
        <div class="card card-round h-100"><div class="card-body">
            <h6 class="text-muted small text-uppercase mb-1">Total Paid</h6>
            <div class="h5 mb-0"><?= $progress['linked'] ? co_format_money($progress['total_paid']) : '—' ?></div>
        </div></div>
    </div>
    <div class="col-md-4 col-lg-3">
        <div class="card card-round h-100"><div class="card-body">
            <h6 class="text-muted small text-uppercase mb-1">Outstanding AP</h6>
            <div class="h5 mb-0"><?= $progress['linked'] ? co_format_money($progress['outstanding_ap']) : '—' ?></div>
        </div></div>
    </div>
    <div class="col-md-4 col-lg-3">
        <div class="card card-round h-100"><div class="card-body">
            <h6 class="text-muted small text-uppercase mb-1">Remaining Contract Value</h6>
            <div class="h5 mb-0"><?= co_format_money($progress['remaining_contract_value']) ?></div>
            <small class="text-muted">Contract − invoiced</small>
        </div></div>
    </div>
    <div class="col-md-4 col-lg-3">
        <div class="card card-round h-100"><div class="card-body">
            <h6 class="text-muted small text-uppercase mb-1">Billing Progress</h6>
            <div class="h5 mb-0"><?= $progress['linked'] ? number_format($progress['billing_progress_pct'], 1) . '%' : '—' ?></div>
            <div class="progress mt-2" style="height:6px"><div class="progress-bar" style="width:<?= min(100, (float)$progress['billing_progress_pct']) ?>%"></div></div>
        </div></div>
    </div>
    <div class="col-md-4 col-lg-3">
        <div class="card card-round h-100"><div class="card-body">
            <h6 class="text-muted small text-uppercase mb-1">Payment Progress</h6>
            <div class="h5 mb-0"><?= $progress['linked'] ? number_format($progress['payment_progress_pct'], 1) . '%' : '—' ?></div>
            <div class="progress mt-2" style="height:6px"><div class="progress-bar bg-success" style="width:<?= min(100, (float)$progress['payment_progress_pct']) ?>%"></div></div>
        </div></div>
    </div>
    <div class="col-md-4 col-lg-3">
        <div class="card card-round h-100"><div class="card-body">
            <h6 class="text-muted small text-uppercase mb-1">Net Payable</h6>
            <div class="h5 mb-0"><?= $progress['linked'] ? co_format_money($progress['net_payable']) : '—' ?></div>
            <small class="text-muted">AP − Advances <?= $progress['linked'] ? '(' . co_format_money($progress['advance_balance']) . ')' : '' ?></small>
        </div></div>
    </div>
</div>

<?php if ($progress['retention_balance'] > 0.005 || $progress['retention_held'] > 0.005): ?>
<div class="alert alert-light border small">
    Residual retention (legacy): Held <?= co_format_money($progress['retention_held']) ?>
    · Released <?= co_format_money($progress['retention_released']) ?>
    · Balance <?= co_format_money($progress['retention_balance']) ?>
    · <a href="retention_release.php">Retention Release</a>
</div>
<?php endif; ?>

<div class="card card-round mb-3">
    <div class="card-header bg-white"><h6 class="mb-0">Business Partner — Linked Supplier</h6></div>
    <div class="card-body">
        <?php if ($progress['linked']): ?>
            <p class="mb-2">
                <strong><?= h($progress['supplier_name']) ?></strong>
                · <a href="supplier_view.php?id=<?= (int)$progress['supplier_id'] ?>">Open Supplier Profile</a>
                <?php if ($progress['last_invoice']): ?>
                    · Last invoice <a href="supplier_invoice_view.php?id=<?= (int)$progress['last_invoice']['id'] ?>"><?= h($progress['last_invoice']['invoice_number'] ?? '#' . $progress['last_invoice']['id']) ?></a>
                <?php endif; ?>
                <?php if ($progress['last_payment']): ?>
                    · Last payment <a href="supplier_payment_view.php?id=<?= (int)$progress['last_payment']['id'] ?>"><?= co_format_money($progress['last_payment']['amount']) ?></a>
                <?php endif; ?>
            </p>
        <?php else: ?>
            <p class="text-muted">No supplier linked. Financial KPIs and Payment actions require a Business Partner link.</p>
        <?php endif; ?>
        <?php if (co_contractor_link_schema_ready($conn)): ?>
        <form method="post" class="row g-2 align-items-end">
            <?php if (function_exists('csrf_field')) csrf_field(); ?>
            <input type="hidden" name="link_action" value="set_link">
            <div class="col-md-6">
                <label class="form-label">Linked Supplier</label>
                <select name="supplier_id" class="form-select">
                    <option value="">— Not linked —</option>
                    <?php
                    $currentSid = (int)($c['supplier_id'] ?? 0);
                    $options = $linkableSuppliers;
                    if ($currentSid > 0 && $progress['supplier_name']) {
                        array_unshift($options, ['id' => $currentSid, 'supplier_name' => $progress['supplier_name']]);
                    }
                    $seen = [];
                    foreach ($options as $sup):
                        if (isset($seen[(int)$sup['id']])) continue;
                        $seen[(int)$sup['id']] = true;
                    ?>
                    <option value="<?= (int)$sup['id'] ?>" <?= $currentSid === (int)$sup['id'] ? 'selected' : '' ?>><?= h($sup['supplier_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-auto">
                <button type="submit" class="btn <?= $progress['linked'] ? 'btn-outline-primary' : 'btn-primary' ?>">
                    <?= $progress['linked'] ? 'Edit' : 'Save Link' ?>
                </button>
            </div>
        </form>
        <?php if (!$progress['linked']): ?>
        <form method="post" class="mt-2">
            <?php if (function_exists('csrf_field')) csrf_field(); ?>
            <input type="hidden" name="link_action" value="create_supplier">
            <button type="submit" class="btn btn-outline-secondary btn-sm">Create Supplier from this Contractor</button>
        </form>
        <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<div class="card card-round">
    <div class="card-header bg-white d-flex justify-content-between align-items-center">
        <h6 class="mb-0">Details</h6>
        <a href="contractor_documents.php?contractor_id=<?= $id ?>" class="btn btn-sm btn-outline-secondary">Documents</a>
    </div>
    <div class="card-body">
        <div class="table-responsive">
        <table class="table table-sm mb-0">
            <tr><td class="text-muted" style="width:30%">Contact Person</td><td><?= h($c['contact_person'] ?? '-') ?></td></tr>
            <tr><td class="text-muted">Phone</td><td><?= h($c['phone'] ?? '-') ?></td></tr>
            <tr><td class="text-muted">Email</td><td><?= h($c['email'] ?? '-') ?></td></tr>
            <tr><td class="text-muted">Tax Number</td><td><?= h($c['tax_number'] ?? '-') ?></td></tr>
            <tr><td class="text-muted">Address</td><td><?= nl2br(h($c['address'] ?? '-')) ?></td></tr>
            <?php
            $hasStructuredBank = !empty($c['bank_name']) || !empty($c['account_number']) || !empty($c['iban']) || !empty($c['swift_code']);
            if ($hasStructuredBank):
                $bankParts = array_filter([
                    $c['bank_name'] ?? null,
                    !empty($c['account_number']) ? 'Account: ' . ($c['account_number'] ?? '') : null,
                    $c['iban'] ?? null,
                    !empty($c['swift_code']) ? 'SWIFT: ' . ($c['swift_code'] ?? '') : null
                ]);
            ?>
            <tr><td class="text-muted">Bank Details</td><td><?= implode(' · ', array_map('h', $bankParts)) ?></td></tr>
            <?php endif; ?>
            <?php if (!empty($c['bank_details'])): ?>
            <tr><td class="text-muted"><?= $hasStructuredBank ? 'Bank Notes' : 'Bank Details' ?></td><td><?= nl2br(h($c['bank_details'])) ?></td></tr>
            <?php endif; ?>
            <?php if ($c['notes']): ?>
            <tr><td class="text-muted">Notes</td><td><?= nl2br(h($c['notes'])) ?></td></tr>
            <?php endif; ?>
        </table>
        </div>
    </div>
</div>

<div class="card card-round mt-3">
    <div class="card-header bg-white d-flex justify-content-between align-items-center">
        <h6 class="mb-0">Projects Assigned (<?= count($projectRows) ?>)</h6>
        <a href="project_contractors.php?contractor_id=<?= $id ?>" class="btn btn-sm btn-outline-primary">+ Add</a>
    </div>
    <div class="card-body">
        <?php if (empty($projectRows)): ?>
            <p class="text-muted mb-0">No projects linked yet. <a href="project_contractors.php?contractor_id=<?= $id ?>">Link to a project</a>.</p>
        <?php else: ?>
        <div class="table-responsive">
        <table class="table table-sm mb-0">
            <thead>
                <tr>
                    <th>Project</th>
                    <th>Contract Value</th>
                    <th>Invoiced</th>
                    <th>Paid</th>
                    <th>Outstanding AP</th>
                    <th>Remaining</th>
                    <th>Billing %</th>
                    <th>Payment %</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($projectRows as $lp):
                $pp = $lp['progress'];
                $rowPay = co_contractor_payment_workspace_url($conn, $cid, $id, (int)$lp['project_id']);
            ?>
                <tr>
                    <td><a href="project_view.php?id=<?= (int)$lp['project_id'] ?>"><?= h($lp['project_code']) ?> — <?= h($lp['project_name']) ?></a></td>
                    <td><?= co_format_money($pp['contract_value']) ?></td>
                    <td><?= $pp['linked'] ? co_format_money($pp['total_invoiced']) : '—' ?></td>
                    <td><?= $pp['linked'] ? co_format_money($pp['total_paid']) : '—' ?></td>
                    <td><?= $pp['linked'] ? co_format_money($pp['outstanding_ap']) : '—' ?></td>
                    <td><?= co_format_money($pp['remaining_contract_value']) ?></td>
                    <td><?= $pp['linked'] ? number_format($pp['billing_progress_pct'], 1) . '%' : '—' ?></td>
                    <td><?= $pp['linked'] ? number_format($pp['payment_progress_pct'], 1) . '%' : '—' ?></td>
                    <td>
                        <?php if ($rowPay): ?>
                            <a href="<?= h($rowPay) ?>" class="btn btn-sm btn-outline-success">Payment</a>
                        <?php else: ?>
                            <a href="contractor_view.php?id=<?= $id ?>" class="btn btn-sm btn-outline-warning" title="Link supplier first">Link Supplier</a>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php if (!empty($timeline)): ?>
<div class="card card-round mt-3">
    <div class="card-header bg-white d-flex justify-content-between align-items-center">
        <h6 class="mb-0">Recent Financial Activity</h6>
        <a href="supplier_view.php?id=<?= (int)$progress['supplier_id'] ?>" class="btn btn-sm btn-outline-secondary">Full Supplier Timeline</a>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-sm mb-0">
                <thead class="table-light"><tr><th>When</th><th>Event</th><th>Description</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($timeline as $ev): ?>
                    <tr>
                        <td class="text-nowrap small"><?= h($ev['occurred_at'] ?? $ev['date'] ?? '') ?></td>
                        <td><span class="badge bg-secondary"><?= h($ev['label'] ?? $ev['event'] ?? '') ?></span></td>
                        <td><?= h($ev['ref'] ?? '') ?><?php if (isset($ev['amount']) && $ev['amount'] !== null): ?> · <?= co_format_money($ev['amount']) ?><?php endif; ?></td>
                        <td><?php if (!empty($ev['link'])): ?><a href="<?= h($ev['link']) ?>" class="btn btn-sm btn-outline-primary">View</a><?php endif; ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/includes/construction_layout_footer.php'; ?>
