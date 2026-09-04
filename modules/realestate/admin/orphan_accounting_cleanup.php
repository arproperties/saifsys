<?php
/**
 * Orphan accounting cleanup tool.
 *
 * Removes legacy GL rows whose source documents no longer exist.
 * Protects journals linked to live payments, invoices, and allocations.
 *
 * Disabled unless ALLOW_ORPHAN_ACCOUNTING_CLEANUP_TOOL is explicitly true.
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
require_once __DIR__ . '/../includes/orphan_accounting_cleanup_helper.php';

require_login();
require_module_access($conn, MODULE_REALESTATE);

$brand = getBrandSettings($conn);
$companyId = current_company_id($conn) ?: 1;
$userId = current_user_id();
$roles = current_user_roles($conn);
$isOwnerAdmin = in_array('Owner', $roles, true) || in_array('Admin', $roles, true);
$toolEnabled = defined('ALLOW_ORPHAN_ACCOUNTING_CLEANUP_TOOL') && ALLOW_ORPHAN_ACCOUNTING_CLEANUP_TOOL === true;
$dbName = (string)$conn->query('SELECT DATABASE()')->fetchColumn();
$action = $_POST['action'] ?? '';
$message = '';
$error = '';
$preview = null;
$execution = null;

function h($s)
{
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

function m($n)
{
    return number_format((float)$n, 2);
}

$selectedOptions = oac_default_options();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $selectedOptions = oac_options_from_post($_POST);
}

if (!$isOwnerAdmin) {
    $error = 'Forbidden. Owner/Admin role is required.';
} elseif (!$toolEnabled) {
    $error = 'Tool is disabled. Define ALLOW_ORPHAN_ACCOUNTING_CLEANUP_TOOL as true in configuration to enable it.';
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    try {
        $preview = oac_build_preview($conn, $companyId, $selectedOptions);
        if ($action === 'execute') {
            $phraseOk = trim((string)($_POST['confirm_phrase'] ?? '')) === 'CLEAN ORPHAN GL';
            $backupOk = !empty($_POST['backup_confirmed']);
            $dryRunOk = !empty($_POST['dry_run_reviewed']);
            if (!$phraseOk || !$backupOk || !$dryRunOk) {
                $error = 'Execution blocked. Complete backup confirmation, dry-run review, and exact typed phrase.';
            } else {
                oac_audit_table($conn);
                $execution = oac_execute($conn, $companyId, $selectedOptions);
                $preview = $execution['after'];
                $conn->prepare("
                    INSERT INTO re_orphan_accounting_cleanup_audit
                        (company_id, user_id, database_name, selected_options_json, preview_json, result_json, ip_address, session_id)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                ")->execute([
                    $companyId,
                    $userId,
                    $dbName,
                    json_encode($selectedOptions, JSON_UNESCAPED_SLASHES),
                    json_encode($execution['before'], JSON_UNESCAPED_SLASHES),
                    json_encode($execution['results'], JSON_UNESCAPED_SLASHES),
                    $_SERVER['REMOTE_ADDR'] ?? null,
                    session_id(),
                ]);
                $message = 'Orphan accounting cleanup completed. Live payments, invoices, and allocations were preserved.';
            }
        }
    } catch (Throwable $e) {
        $error = 'Operation failed: ' . $e->getMessage();
    }
}

$optionLabels = [
    'orphan_lease_gl' => 'Orphan lease GL (dead payments, legacy deposits, recognition journals)',
    'orphan_recognition_rows' => 'Orphan recognition schedule rows (missing lease/installment)',
    'orphan_billing_items' => 'Orphan billing items (missing lease)',
    'orphan_tenant_credits' => 'Orphan tenant credit transactions',
    'clear_payroll' => 'Clear payroll runs and payroll journals',
    'clear_vendor_ap' => 'Clear vendor bills, vendor payments, and vendor journals',
    'clear_expenses' => 'Clear Real Estate expense records and expense journals',
    'clear_cash_payment_journals' => 'Clear cash payment request journals (Extra Service / CPR workflow — not lease receipts)',
];

$pageTitle = 'Orphan Accounting Cleanup';
require_once __DIR__ . '/../includes/re_layout_header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <div class="page-header-label"><i class="bi bi-bandaid"></i> Orphan Accounting Cleanup</div>
    <a href="../accounting/trial_balance.php" class="btn btn-outline-secondary">Trial Balance</a>
</div>

<div class="alert alert-warning">
    <strong>Surgical legacy cleanup.</strong>
    Removes ghost accounting from old imports/resets while protecting journals linked to existing payments and invoices.
    Does <strong>not</strong> delete <code>re_payments</code>, <code>re_invoices</code>, obligations, candidates, or payment allocations.
</div>

<div class="alert alert-info">
    <strong>Not covered by Lease Financial Reset.</strong>
    Cash Payment Requests (<code>CPR-…</code>) from Extra Services / cashier verification use a separate module
    (<code>re_cash_payment_requests</code>, not <code>re_payments</code>). The lease reset tool does not remove them.
    Use the optional <strong>Clear cash payment request journals</strong> checkbox below when you want those GL entries gone too.
</div>

<?php if ($message): ?><div class="alert alert-success"><?= h($message) ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-danger"><?= h($error) ?></div><?php endif; ?>

<div class="card card-round mb-4">
    <div class="card-body">
        <div><strong>Current database:</strong> <code><?= h($dbName) ?></code></div>
        <div><strong>Company ID:</strong> <?= (int)$companyId ?></div>
        <div><strong>Tool enabled:</strong> <span class="badge bg-<?= $toolEnabled ? 'success' : 'danger' ?>"><?= $toolEnabled ? 'yes' : 'no' ?></span></div>
        <div class="small text-muted mt-2">Take a database backup before Execute. Recommended on live after lease financial reset, before accountants continue heavy data entry.</div>
    </div>
</div>

<form method="post" class="card card-round mb-4">
    <div class="card-header bg-white"><strong>Cleanup Scope</strong></div>
    <div class="card-body">
        <?php csrf_field(); ?>
        <div class="row g-3">
            <?php foreach ($optionLabels as $key => $label): ?>
                <div class="col-md-6">
                    <label class="form-check">
                        <input type="checkbox" class="form-check-input" name="opt[<?= h($key) ?>]" value="1" <?= !empty($selectedOptions[$key]) ? 'checked' : '' ?>>
                        <span class="form-check-label"><?= h($label) ?></span>
                    </label>
                </div>
            <?php endforeach; ?>
        </div>
        <div class="small text-muted mt-3">
            Legacy security-deposit journals with no receipt and no settlement are treated as orphan.
            If you posted deposits via Edit Lease only (journal, no receipt), book the receipt first or do not re-run cleanup afterward.
        </div>
        <hr>
        <div class="row g-3">
            <div class="col-md-6">
                <label class="form-check">
                    <input type="checkbox" class="form-check-input" name="backup_confirmed" value="1" <?= !empty($_POST['backup_confirmed']) ? 'checked' : '' ?>>
                    <span class="form-check-label">I confirm an external SQL backup/export has been completed.</span>
                </label>
            </div>
            <div class="col-md-6">
                <label class="form-check">
                    <input type="checkbox" class="form-check-input" name="dry_run_reviewed" value="1" <?= !empty($_POST['dry_run_reviewed']) ? 'checked' : '' ?>>
                    <span class="form-check-label">I reviewed the dry-run results below.</span>
                </label>
            </div>
            <div class="col-md-6">
                <label class="form-label">Type confirmation phrase for Execute</label>
                <input type="text" name="confirm_phrase" class="form-control" value="<?= h($_POST['confirm_phrase'] ?? '') ?>" placeholder="CLEAN ORPHAN GL">
            </div>
        </div>
        <div class="mt-3 d-flex gap-2">
            <button name="action" value="dry_run" class="btn btn-primary" <?= (!$toolEnabled || !$isOwnerAdmin) ? 'disabled' : '' ?>>Dry Run</button>
            <button name="action" value="execute" class="btn btn-danger" <?= (!$toolEnabled || !$isOwnerAdmin) ? 'disabled' : '' ?> onclick="return confirm('Execute orphan accounting cleanup on this database?');">Execute Cleanup</button>
        </div>
    </div>
</form>

<?php if ($preview): ?>
<div class="card card-round mb-4">
    <div class="card-header bg-white"><strong>Protected Live Data</strong></div>
    <div class="card-body row g-3">
        <div class="col-md-3"><div class="text-muted small">Live payments</div><div class="fs-5"><?= (int)$preview['protected']['live_payments'] ?></div></div>
        <div class="col-md-3"><div class="text-muted small">Live invoices</div><div class="fs-5"><?= (int)$preview['protected']['live_invoices'] ?></div></div>
        <div class="col-md-3"><div class="text-muted small">Payment allocations</div><div class="fs-5"><?= (int)$preview['protected']['live_payment_allocations'] ?></div></div>
        <div class="col-md-3"><div class="text-muted small">Protected journals</div><div class="fs-5 text-success"><?= (int)$preview['protected']['protected_journals'] ?></div></div>
        <div class="col-md-3"><div class="text-muted small">Orphan journals to remove</div><div class="fs-5 text-danger"><?= (int)$preview['protected']['orphan_journals'] ?></div></div>
        <div class="col-md-3"><div class="text-muted small">Protected payment journals</div><div class="fs-5"><?= (int)$preview['protected']['protected_payment_journals'] ?></div></div>
        <div class="col-md-3"><div class="text-muted small">Protected invoice journals</div><div class="fs-5"><?= (int)$preview['protected']['protected_invoice_journals'] ?></div></div>
        <div class="col-md-3"><div class="text-muted small">Protected CPR journals</div><div class="fs-5"><?= (int)($preview['protected']['protected_cash_payment_journals'] ?? 0) ?></div></div>
        <div class="col-md-3"><div class="text-muted small">Total journals in company</div><div class="fs-5"><?= (int)$preview['protected']['total_journals'] ?></div></div>
    </div>
</div>

<?php if (!empty($preview['gl_impact'])): ?>
<div class="card card-round mb-4">
    <div class="card-header bg-white"><strong>GL Impact (Orphan Journals Only)</strong></div>
    <div class="table-responsive">
        <table class="table table-sm table-hover mb-0">
            <thead class="table-light">
                <tr>
                    <th>Code</th><th>Account</th><th>Type</th>
                    <th class="text-end">Debit</th><th class="text-end">Credit</th><th class="text-end">Journals</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($preview['gl_impact'] as $row): ?>
                <tr>
                    <td><?= h($row['account_code']) ?></td>
                    <td><?= h($row['account_name']) ?></td>
                    <td><?= h($row['account_type']) ?></td>
                    <td class="text-end"><?= m($row['total_debit']) ?></td>
                    <td class="text-end"><?= m($row['total_credit']) ?></td>
                    <td class="text-end"><?= (int)$row['journals'] ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<?php if (!empty($preview['sample_orphans'])): ?>
<div class="card card-round mb-4">
    <div class="card-header bg-white"><strong>Sample Orphan Journals</strong></div>
    <div class="table-responsive">
        <table class="table table-sm mb-0">
            <thead class="table-light">
                <tr>
                    <th>#</th><th>Date</th><th>Type</th><th>Reference</th><th class="text-end">Amount</th><th>Description</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($preview['sample_orphans'] as $row): ?>
                <tr>
                    <td><?= h($row['journal_number']) ?></td>
                    <td><?= h($row['journal_date']) ?></td>
                    <td><?= h($row['journal_type']) ?></td>
                    <td><?= h($row['reference_type']) ?> #<?= (int)$row['reference_id'] ?></td>
                    <td class="text-end"><?= m($row['total_debit']) ?></td>
                    <td><?= h($row['description']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<div class="card card-round mb-4">
    <div class="card-header bg-white"><strong>Dry Run Steps</strong></div>
    <div class="table-responsive">
        <table class="table table-sm table-hover mb-0">
            <thead class="table-light">
                <tr><th>Category</th><th>Table</th><th>Action</th><th class="text-end">Rows</th><th>Status</th></tr>
            </thead>
            <tbody>
            <?php foreach ($preview['steps'] as $row): ?>
                <tr>
                    <td><?= h($row['category']) ?></td>
                    <td><code><?= h($row['table']) ?></code></td>
                    <td><?= h($row['action']) ?></td>
                    <td class="text-end"><?= (int)$row['row_count'] ?></td>
                    <td><span class="badge bg-<?= $row['status'] === 'ready' ? 'primary' : ($row['status'] === 'missing' ? 'secondary' : 'danger') ?>"><?= h($row['status']) ?></span></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<?php if ($execution): ?>
<div class="card card-round mb-4">
    <div class="card-header bg-white"><strong>Execution Summary</strong></div>
    <div class="table-responsive">
        <table class="table table-sm mb-0">
            <thead class="table-light">
                <tr><th>Table</th><th class="text-end">Preview Rows</th><th class="text-end">Affected Rows</th><th>Result</th></tr>
            </thead>
            <tbody>
            <?php foreach ($execution['results'] as $row): ?>
                <tr>
                    <td><code><?= h($row['table']) ?></code></td>
                    <td class="text-end"><?= (int)$row['row_count'] ?></td>
                    <td class="text-end"><?= (int)$row['deleted'] ?></td>
                    <td><?= h($row['result']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<div class="card card-round">
    <div class="card-header bg-white"><strong>Never Deleted By This Tool</strong></div>
    <div class="card-body mb-0">
        <ul>
            <li>Lease master records, tenants, units, buildings, documents</li>
            <li>Current <code>re_payments</code>, <code>re_invoices</code>, obligations, invoice candidates</li>
            <li>Payment allocations and receipt allocations linked to live payments</li>
            <li>Journals linked to existing payments or invoices</li>
            <li>Cash payment request journals — unless <strong>Clear cash payment request journals</strong> is checked</li>
            <li>Chart of accounts, bank account setup, vendor master list</li>
        </ul>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/re_layout_footer.php'; ?>
