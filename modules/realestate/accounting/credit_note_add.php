<?php
/**
 * Real Estate Accounting - Create Credit Note (linked to invoice)
 */

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../../../includes/db_connect.php';
require_once __DIR__ . '/../../../includes/branding.php';
require_once __DIR__ . '/../../../includes/company_helper.php';
require_once __DIR__ . '/../../../includes/module_access.php';
require_once __DIR__ . '/../../../includes/rbac_department.php';
require_once __DIR__ . '/accounting_integration.php';

require_login();
if (!has_department_access(MODULE_REALESTATE, DEPT_REALESTATE_FINANCIAL, $conn)) {
    require_module_access($conn, MODULE_REALESTATE);
}

$brand = getBrandSettings($conn);
$currentCompanyId = current_company_id($conn) ?: 1;
$invoiceId = !empty($_GET['invoice_id']) ? (int)$_GET['invoice_id'] : (!empty($_POST['invoice_id']) ? (int)$_POST['invoice_id'] : null);
$success = '';
$error = '';

$invoice = null;
if ($invoiceId) {
    $stmt = $conn->prepare("
        SELECT i.*, l.lease_number, l.tenant_id, t.first_name, t.last_name, t.company_name, t.tenant_type
        FROM re_invoices i
        JOIN re_leases l ON l.id = i.lease_id
        JOIN re_tenants t ON t.id = l.tenant_id
        WHERE i.id = ? AND i.company_id = ?
    ");
    $stmt->execute([$invoiceId, $currentCompanyId]);
    $invoice = $stmt->fetch(PDO::FETCH_ASSOC);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $invoice) {
    csrf_verify();
    $amount = (float)($_POST['amount'] ?? 0);
    $reason = trim($_POST['reason'] ?? '');
    if ($amount <= 0) {
        $error = 'Amount must be greater than zero.';
    } else {
        $r = post_credit_note_to_accounting($invoiceId, $amount, $currentCompanyId, current_user_id(), $reason);
        if ($r['success']) {
            $doneInvoiceId = $invoiceId;
            $success = 'Credit note posted. Journal #' . $r['journal_id'] . '. Invoice outstanding updated. <a href="../billing_invoice_view.php?id=' . $doneInvoiceId . '">View Invoice</a>';
            $invoice = null;
            $invoiceId = null;
        } else {
            $error = $r['error'];
        }
    }
}

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
$pageTitle = 'Credit Note';
require_once __DIR__ . '/../includes/re_layout_header.php';
?>

<?php if ($success): ?><div class="alert alert-success alert-dismissible fade show"><?= $success ?> <button type="button" class="btn-close" data-bs-dismiss="alert"></button></div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-danger alert-dismissible fade show"><?= h($error) ?> <button type="button" class="btn-close" data-bs-dismiss="alert"></button></div><?php endif; ?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div class="page-header-label"><i class="bi bi-arrow-counterclockwise"></i> Credit Note</div>
    <a href="journal_entry_list.php" class="btn btn-outline-secondary"><i class="bi bi-journal-text"></i> Journal Entries</a>
</div>

<?php if (!$invoice): ?>
    <div class="card card-round">
        <div class="card-body">
            <p>Select an invoice to create a credit note.</p>
            <form method="GET" class="row g-3">
                <div class="col-md-6">
                    <label class="form-label">Invoice ID</label>
                    <input type="number" name="invoice_id" class="form-control" placeholder="e.g. 1" min="1" required>
                </div>
                <div class="col-md-2 d-flex align-items-end">
                    <button type="submit" class="btn btn-primary">Load Invoice</button>
                </div>
            </form>
        </div>
    </div>
<?php else: ?>
    <?php
    $tenantName = $invoice['tenant_type'] === 'company' ? $invoice['company_name'] : ($invoice['first_name'] . ' ' . $invoice['last_name']);
    $outstanding = (float)$invoice['outstanding_amount'];
    ?>
    <div class="card card-round mb-4">
        <div class="card-body">
            <h6>Invoice</h6>
            <p class="mb-0"><strong><?= h($invoice['invoice_number']) ?></strong> – <?= h($tenantName) ?> | Lease <?= h($invoice['lease_number']) ?> | Outstanding: <strong><?= number_format($outstanding, 2) ?> AED</strong></p>
            <a href="../billing_invoice_view.php?id=<?= $invoiceId ?>" class="btn btn-sm btn-outline-primary mt-2">View Invoice</a>
        </div>
    </div>
    <div class="card card-round">
        <div class="card-body">
            <form method="POST">
                <?= csrf_field() ?>
                <input type="hidden" name="invoice_id" value="<?= $invoiceId ?>">
                <div class="mb-3">
                    <label class="form-label">Credit Note Amount (AED) *</label>
                    <input type="number" name="amount" class="form-control" step="0.01" min="0.01" max="<?= $outstanding ?>" value="<?= $outstanding ?>" required>
                    <small class="text-muted">Max: <?= number_format($outstanding, 2) ?> AED</small>
                </div>
                <div class="mb-3">
                    <label class="form-label">Reason (optional)</label>
                    <input type="text" name="reason" class="form-control" placeholder="e.g. Goodwill, Overcharge correction">
                </div>
                <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg"></i> Post Credit Note</button>
            </form>
        </div>
    </div>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/re_layout_footer.php'; ?>
