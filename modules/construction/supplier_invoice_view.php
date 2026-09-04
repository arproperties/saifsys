<?php
/**
 * Construction Module — Supplier Invoice Detail
 * Lifecycle: Draft → Posted → Partially Paid → Paid → Voided
 */

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../includes/company_helper.php';
require_once __DIR__ . '/../../includes/module_access.php';
require_once __DIR__ . '/../../includes/rbac_department.php';
require_once __DIR__ . '/includes/construction_helpers.php';
require_once __DIR__ . '/includes/construction_supplier_ap_helpers.php';
require_once __DIR__ . '/includes/construction_supplier_advance_helpers.php';
require_once __DIR__ . '/includes/construction_accounting_integration.php';

require_login();
require_module_access($conn, MODULE_CONSTRUCTION);

require_once __DIR__ . '/../../includes/branding.php';
$brand = getBrandSettings($conn);
$cid = co_supplier_require_company_id($conn);
$userId = current_user_id();
$id = (int)($_GET['id'] ?? 0);
if (!$id) { header('Location: supplier_invoices.php'); exit; }
$msg = '';
$err = '';

if (!empty($_SESSION['co_supplier_inv_flash']) && is_array($_SESSION['co_supplier_inv_flash'])) {
    $msg = (string)($_SESSION['co_supplier_inv_flash']['msg'] ?? '');
    $err = (string)($_SESSION['co_supplier_inv_flash']['err'] ?? '');
    unset($_SESSION['co_supplier_inv_flash']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = (string)($_POST['action'] ?? '');
    try {
        if ($action === 'post_gl') {
            $chk = $conn->prepare('SELECT id, journal_id, invoice_number, status FROM co_supplier_invoices WHERE id = ? AND company_id = ?');
            $chk->execute([$id, $cid]);
            $row = $chk->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                throw new RuntimeException('Invoice not found.');
            }
            if (co_supplier_invoice_status($row) === 'voided') {
                throw new RuntimeException('Voided invoices cannot be posted.');
            }
            if (!empty($row['journal_id'])) {
                throw new RuntimeException('Already GL Posted.');
            }
            $postResult = co_post_supplier_invoice_to_accounting($id, $cid, $userId);
            if (empty($postResult['success'])) {
                throw new RuntimeException($postResult['error'] ?? 'GL posting failed.');
            }
            if (empty($postResult['already_posted'])) {
                if (co_supplier_invoice_lifecycle_ready($conn)) {
                    $conn->prepare("UPDATE co_supplier_invoices SET journal_id = ?, status = 'posted' WHERE id = ? AND company_id = ? AND status <> 'voided'")
                        ->execute([(int)$postResult['journal_id'], $id, $cid]);
                } else {
                    $conn->prepare('UPDATE co_supplier_invoices SET journal_id = ? WHERE id = ? AND company_id = ?')
                        ->execute([(int)$postResult['journal_id'], $id, $cid]);
                }
            }
            $msg = 'Posted to GL (journal #' . (int)$postResult['journal_id'] . ').';
        } elseif ($action === 'void') {
            $reason = trim((string)($_POST['reason'] ?? ''));
            $result = co_supplier_void_invoice($conn, $cid, $id, $reason !== '' ? $reason : 'Voided', $userId ? (int)$userId : null);
            if (empty($result['success'])) {
                throw new RuntimeException($result['error'] ?? 'Void failed.');
            }
            $msg = !empty($result['already_void']) ? 'Invoice was already voided.' : 'Invoice voided and GL journal reversed.';
        } elseif ($action === 'void_amend') {
            $reason = trim((string)($_POST['reason'] ?? ''));
            $result = co_supplier_void_and_amend_invoice(
                $conn,
                $cid,
                $id,
                $reason !== '' ? $reason : 'Void + Amend',
                $userId ? (int)$userId : null
            );
            if (empty($result['success'])) {
                throw new RuntimeException($result['error'] ?? 'Void + Amend failed.');
            }
            $_SESSION['co_supplier_inv_flash'] = [
                'msg' => 'Original invoice voided. New amendment draft created.',
            ];
            header('Location: supplier_invoice_edit.php?id=' . (int)$result['draft_invoice_id']);
            exit;
        } elseif ($action === 'apply_advance') {
            $applyAmount = round((float)($_POST['apply_amount'] ?? 0), 2);
            $invRow = co_supplier_invoice_load($conn, $cid, $id);
            if (!$invRow) {
                throw new RuntimeException('Invoice not found.');
            }
            $result = co_supplier_apply_advance(
                $conn,
                $cid,
                (int)$invRow['supplier_id'],
                $id,
                $applyAmount,
                $userId ? (int)$userId : null
            );
            if (empty($result['success'])) {
                throw new RuntimeException($result['error'] ?? 'Advance apply failed.');
            }
            $msg = 'Supplier advance applied (' . number_format($applyAmount, 2) . ' AED).';
        } elseif ($action === 'unapply_advance') {
            $applicationId = (int)($_POST['application_id'] ?? 0);
            $result = co_supplier_unapply_advance(
                $conn,
                $cid,
                $applicationId,
                trim((string)($_POST['reason'] ?? 'Unapplied from invoice view')),
                $userId ? (int)$userId : null
            );
            if (empty($result['success'])) {
                throw new RuntimeException($result['error'] ?? 'Advance unapply failed.');
            }
            $msg = 'Advance application reversed.';
        } elseif ($action === 'link_advance_vat') {
            $docId = (int)($_POST['advance_vat_document_id'] ?? 0);
            $vatAmt = round((float)($_POST['vat_amount'] ?? 0), 2);
            $taxAmt = round((float)($_POST['taxable_amount'] ?? 0), 2);
            $result = co_supplier_link_advance_vat_to_invoice(
                $conn,
                $cid,
                $id,
                [['document_id' => $docId, 'vat_amount' => $vatAmt, 'taxable_amount' => $taxAmt]],
                $userId ? (int)$userId : null
            );
            if (empty($result['success'])) {
                throw new RuntimeException($result['error'] ?? 'Link advance VAT failed.');
            }
            $msg = 'Advance VAT linked (' . number_format($vatAmt, 2) . ' AED). Remaining invoice Input VAT on post: '
                . number_format((float)($result['remaining_invoice_vat'] ?? 0), 2) . ' AED.';
        } elseif ($action === 'unlink_advance_vat') {
            $linkId = (int)($_POST['link_id'] ?? 0);
            $result = co_supplier_unlink_advance_vat_from_invoice($conn, $cid, $linkId, $userId ? (int)$userId : null);
            if (empty($result['success'])) {
                throw new RuntimeException($result['error'] ?? 'Unlink advance VAT failed.');
            }
            $msg = 'Advance VAT link removed.';
        }
    } catch (Throwable $e) {
        $err = $e->getMessage();
    }
}

$expenseJoin = co_expense_account_column_ready($conn)
    ? "LEFT JOIN re_chart_of_accounts ea ON ea.id = si.expense_account_id AND ea.company_id = si.company_id"
    : "";
$expenseSelect = co_expense_account_column_ready($conn)
    ? "ea.account_code AS expense_account_code, ea.account_name AS expense_account_name,"
    : "NULL AS expense_account_code, NULL AS expense_account_name,";

$stmt = $conn->prepare("
    SELECT si.*, s.supplier_name, s.id AS supplier_id,
           p.project_code, p.project_name, p.project_type,
           {$expenseSelect}
           jh.journal_number, jh.is_reversed AS journal_reversed
    FROM co_supplier_invoices si
    JOIN co_suppliers s ON s.id = si.supplier_id
    LEFT JOIN co_projects p ON p.id = si.project_id
    {$expenseJoin}
    LEFT JOIN re_journal_headers jh ON jh.id = si.journal_id
    WHERE si.id = ? AND si.company_id = ?
");
$stmt->execute([$id, $cid]);
$inv = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$inv) { header('Location: supplier_invoices.php'); exit; }

if (co_supplier_invoice_lifecycle_ready($conn) && co_supplier_invoice_status($inv) !== 'voided') {
    co_supplier_invoice_refresh_status($conn, $cid, $id);
    $stmt->execute([$id, $cid]);
    $inv = $stmt->fetch(PDO::FETCH_ASSOC) ?: $inv;
}

$lifecycleStatus = co_supplier_invoice_status($inv);
$canEdit = co_supplier_invoice_can_edit($inv);
$isVoided = $lifecycleStatus === 'voided';
$isPostedUnpaid = $lifecycleStatus === 'posted';
$paidAmount = co_supplier_invoice_paid_amount($conn, $cid, $id);
$linkedAdvanceVat = co_supplier_invoice_linked_advance_vat($conn, $cid, $id);
$balanceDue = !$isVoided
    ? co_supplier_invoice_outstanding_amount($conn, $cid, $id)
    : 0.0;
$canDelete = $canEdit && $paidAmount <= 0.005 && $linkedAdvanceVat <= 0.005;
$canVoid = $isPostedUnpaid && $paidAmount <= 0.005 && !empty($inv['journal_id']);
$canPay = !$isVoided && $balanceDue > 0.005 && !empty($inv['journal_id']) && empty($inv['journal_reversed']);
$advanceBalance = 0.0;
$canApplyAdvance = false;
$advanceTrace = [];
$eligibleVatDocs = [];
$vatLinks = [];
$canLinkAdvanceVat = false;
if (co_supplier_advance_schema_ready($conn) && !$isVoided) {
    $advanceTrace = co_supplier_invoice_advance_trace($conn, $cid, $id);
    if (!empty($inv['journal_id'])) {
        $advanceBalance = co_supplier_advance_balance($conn, $cid, (int)$inv['supplier_id']);
        $canApplyAdvance = $balanceDue > 0.005 && $advanceBalance > 0.005 && in_array($lifecycleStatus, ['posted', 'partially_paid'], true);
    }
}
if (function_exists('co_supplier_advance_vat_table_ready') && co_supplier_advance_vat_table_ready($conn) && !$isVoided) {
    $ls = $conn->prepare("
        SELECT l.*, d.supplier_invoice_number, d.supplier_payment_id
        FROM co_supplier_advance_vat_invoice_links l
        JOIN co_supplier_advance_vat_documents d ON d.id = l.advance_vat_document_id AND d.company_id = l.company_id
        WHERE l.company_id = ? AND l.supplier_invoice_id = ?
        ORDER BY l.id DESC
    ");
    $ls->execute([$cid, $id]);
    $vatLinks = $ls->fetchAll(PDO::FETCH_ASSOC) ?: [];
    if ($canEdit && empty($inv['journal_id'])) {
        $eligibleVatDocs = co_supplier_eligible_advance_vat_docs($conn, $cid, (int)$inv['supplier_id']);
        $canLinkAdvanceVat = !empty($eligibleVatDocs) && ((float)$inv['vat_amount'] - $linkedAdvanceVat) > 0.005;
    }
}

$invoiceLines = [];
if (co_db_table_exists($conn, 'co_supplier_invoice_items')) {
    $lineStmt = $conn->prepare("
        SELECT li.*, p.project_code, p.project_name, ea.account_code, ea.account_name
        FROM co_supplier_invoice_items li
        LEFT JOIN co_projects p ON p.id = li.project_id AND p.company_id = li.company_id
        LEFT JOIN re_chart_of_accounts ea ON ea.id = li.expense_account_id AND ea.company_id = li.company_id
        WHERE li.company_id = ? AND li.invoice_id = ?
        ORDER BY li.id
    ");
    $lineStmt->execute([$cid, $id]);
    $invoiceLines = $lineStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

$allocations = [];
if (co_supplier_allocations_ready($conn)) {
    $allocStmt = $conn->prepare("
        SELECT a.*, sp.payment_date, sp.reference AS payment_reference, sp.journal_id AS payment_journal_id
        FROM co_supplier_payment_allocations a
        JOIN co_supplier_payments sp ON sp.id = a.payment_id AND sp.company_id = a.company_id
        WHERE a.company_id = ? AND a.invoice_id = ?
        ORDER BY sp.payment_date DESC, a.id DESC
    ");
    $allocStmt->execute([$cid, $id]);
    $allocations = $allocStmt->fetchAll(PDO::FETCH_ASSOC);
}

$amendedFrom = null;
if (!empty($inv['amended_from_invoice_id'])) {
    $af = $conn->prepare("SELECT id, invoice_number FROM co_supplier_invoices WHERE id = ? AND company_id = ?");
    $af->execute([(int)$inv['amended_from_invoice_id'], $cid]);
    $amendedFrom = $af->fetch(PDO::FETCH_ASSOC) ?: null;
}

$appBase = (strpos($_SERVER['PHP_SELF'] ?? '', '/herosysgro') !== false) ? '/herosysgro' : '';
$journalUrl = $appBase . '/modules/construction/journal_entry_view.php?id=';

$pageTitle = 'Supplier Invoice ' . ($inv['invoice_number'] ?? '');
require_once __DIR__ . '/includes/construction_layout_header.php';
?>

<?php if ($msg !== ''): ?><div class="alert alert-success"><?= h($msg) ?></div><?php endif; ?>
<?php if ($err !== ''): ?><div class="alert alert-danger"><?= h($err) ?></div><?php endif; ?>

<div class="mb-4">
    <a href="supplier_view.php?id=<?= (int)$inv['supplier_id'] ?>" class="btn btn-outline-secondary btn-sm mb-2"><i class="bi bi-arrow-left"></i> Back to Supplier</a>
    <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
        <div>
            <h1 class="h4 mb-0">
                Invoice <?= h($inv['invoice_number']) ?>
                <span class="badge bg-<?= h(co_supplier_invoice_status_badge_class($lifecycleStatus)) ?> ms-1"><?= h(co_supplier_invoice_status_label($lifecycleStatus)) ?></span>
            </h1>
            <p class="text-muted mb-0"><?= h($inv['supplier_name']) ?></p>
            <?php if ($amendedFrom): ?>
            <p class="small mb-0">Amendment of <a href="supplier_invoice_view.php?id=<?= (int)$amendedFrom['id'] ?>"><?= h($amendedFrom['invoice_number']) ?></a></p>
            <?php endif; ?>
            <?php if ($isVoided && !empty($inv['void_reason'])): ?>
            <p class="small text-muted mb-0">Void reason: <?= h($inv['void_reason']) ?><?= !empty($inv['voided_at']) ? ' (' . h($inv['voided_at']) . ')' : '' ?></p>
            <?php endif; ?>
        </div>
        <div class="d-flex flex-wrap gap-2">
            <?php if ($canEdit): ?>
            <form method="post" class="d-inline" onsubmit="return confirm('Post this supplier invoice to GL? Financial values will be locked.');">
                <?php csrf_field(); ?>
                <input type="hidden" name="action" value="post_gl">
                <button type="submit" class="btn btn-warning">Post to GL</button>
            </form>
            <a href="supplier_invoice_edit.php?id=<?= $id ?>" class="btn btn-outline-primary">Edit</a>
            <?php endif; ?>
            <?php if ($canPay): ?>
            <a href="supplier_payment_add.php?invoice_id=<?= $id ?>" class="btn btn-success">Pay</a>
            <?php endif; ?>
            <?php if ($canApplyAdvance): ?>
            <button type="button" class="btn btn-outline-success" data-bs-toggle="modal" data-bs-target="#applyAdvanceModal">Apply Advance</button>
            <?php endif; ?>
            <?php if ($canVoid): ?>
            <button type="button" class="btn btn-outline-danger" data-bs-toggle="modal" data-bs-target="#voidModal">Void</button>
            <button type="button" class="btn btn-outline-warning" data-bs-toggle="modal" data-bs-target="#voidAmendModal">Void + Amend</button>
            <?php endif; ?>
            <a href="supplier_recurring_invoices.php?action=create&source_invoice_id=<?= $id ?>" class="btn btn-outline-info">Recurring</a>
            <?php if ($canDelete): ?>
            <a href="supplier_invoice_delete.php?id=<?= $id ?>" class="btn btn-outline-danger">Delete</a>
            <?php endif; ?>
            <a href="supplier_invoice_documents.php?invoice_id=<?= $id ?>" class="btn btn-outline-secondary">Documents</a>
        </div>
    </div>
</div>

<?php if (!$canEdit && !$isVoided): ?>
<div class="alert alert-info">This invoice is <?= h(co_supplier_invoice_status_label($lifecycleStatus)) ?> — financial values are locked. To correct amounts, reverse payments if needed, then use Void + Amend.</div>
<?php endif; ?>

<div class="row g-3 mb-3">
    <div class="col-md-3"><div class="card card-round h-100"><div class="card-body"><div class="text-muted small">Subtotal</div><div class="h5 mb-0"><?= co_format_money($inv['subtotal']) ?></div></div></div></div>
    <div class="col-md-3"><div class="card card-round h-100"><div class="card-body"><div class="text-muted small">VAT (<?= h($inv['vat_pct']) ?>%)</div><div class="h5 mb-0"><?= co_format_money($inv['vat_amount']) ?></div>
        <?php if ($linkedAdvanceVat > 0.005): ?><div class="small text-muted">Linked advance VAT: <?= co_format_money($linkedAdvanceVat) ?></div><?php endif; ?>
    </div></div></div>
    <div class="col-md-3"><div class="card card-round h-100"><div class="card-body"><div class="text-muted small">Total</div><div class="h5 mb-0"><?= co_format_money($inv['total']) ?></div></div></div></div>
    <div class="col-md-3"><div class="card card-round h-100"><div class="card-body"><div class="text-muted small">Balance Due</div><div class="h5 mb-0"><?= $isVoided ? '—' : co_format_money($balanceDue) ?></div></div></div></div>
</div>

<div class="card card-round mb-3">
    <div class="card-header bg-white"><h6 class="mb-0">Invoice Details</h6></div>
    <div class="card-body">
        <div class="table-responsive">
        <table class="table table-sm mb-0">
            <tr><td class="text-muted" style="width:30%">Supplier</td><td><a href="supplier_view.php?id=<?= (int)$inv['supplier_id'] ?>"><?= h($inv['supplier_name']) ?></a></td></tr>
            <tr><td class="text-muted">Project</td><td><?= $inv['project_code'] ? h($inv['project_code'] . ' — ' . $inv['project_name']) : '—' ?></td></tr>
            <tr><td class="text-muted">Expense Account</td><td><?= !empty($inv['expense_account_code']) ? h($inv['expense_account_code'] . ' — ' . $inv['expense_account_name']) : '— (auto)' ?></td></tr>
            <tr><td class="text-muted">Invoice Date</td><td><?= h($inv['invoice_date']) ?></td></tr>
            <tr><td class="text-muted">Due Date</td><td><?= h($inv['due_date'] ?: '—') ?></td></tr>
            <tr><td class="text-muted">Description</td><td><?= h($inv['description'] ?: '—') ?></td></tr>
            <tr><td class="text-muted">Reference</td><td><?= h($inv['reference'] ?: '—') ?></td></tr>
            <tr><td class="text-muted">Lifecycle Status</td><td><span class="badge bg-<?= h(co_supplier_invoice_status_badge_class($lifecycleStatus)) ?>"><?= h(co_supplier_invoice_status_label($lifecycleStatus)) ?></span></td></tr>
            <tr><td class="text-muted">Paid</td><td><?= co_format_money($paidAmount) ?></td></tr>
            <tr><td class="text-muted">GL Journal</td><td>
                <?php if (!empty($inv['journal_id'])): ?>
                    <?php if (!empty($inv['journal_reversed']) || $isVoided): ?>
                    <span class="badge bg-secondary">Reversed</span>
                    <a href="<?= h($journalUrl . (int)$inv['journal_id']) ?>" target="_blank" class="ms-1"><?= h($inv['journal_number'] ?: ('#' . (int)$inv['journal_id'])) ?></a>
                    <?php else: ?>
                    <a href="<?= h($journalUrl . (int)$inv['journal_id']) ?>" target="_blank" class="badge bg-success text-decoration-none"><?= h($inv['journal_number'] ?: 'Posted') ?></a>
                    <?php endif; ?>
                <?php else: ?>
                <span class="badge bg-secondary">Not posted</span>
                <?php endif; ?>
            </td></tr>
        </table>
        </div>
    </div>
</div>

<?php if ($invoiceLines): ?>
<div class="card card-round mb-3">
    <div class="card-header bg-white"><h6 class="mb-0">Line Items</h6></div>
    <div class="card-body p-0 table-responsive">
        <table class="table table-sm mb-0">
            <thead class="table-light"><tr><th>Description</th><th>Project</th><th>Expense Account</th><th class="text-end">Qty</th><th class="text-end">Rate</th><th class="text-end">VAT</th><th class="text-end">Total</th></tr></thead>
            <tbody>
            <?php foreach ($invoiceLines as $line): ?>
                <tr>
                    <td><?= h($line['description'] ?: '-') ?></td>
                    <td>
                        <?php if (!empty($line['project_id'])): ?>
                            <?= h(trim(($line['project_code'] ?? '') . ' — ' . ($line['project_name'] ?? ''), " —")) ?: ('#' . (int)$line['project_id']) ?>
                        <?php else: ?>—<?php endif; ?>
                    </td>
                    <td>
                        <?= !empty($line['account_code']) ? h($line['account_code'] . ' — ' . $line['account_name']) : '—' ?>
                    </td>
                    <td class="text-end"><?= number_format((float)$line['quantity'], 2) ?></td>
                    <td class="text-end"><?= co_format_money($line['unit_price']) ?></td>
                    <td class="text-end"><?= co_format_money($line['vat_amount']) ?> <small class="text-muted">(<?= h($line['vat_pct']) ?>%)</small></td>
                    <td class="text-end fw-semibold"><?= co_format_money($line['line_total']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<?php if ($allocations): ?>
<div class="card card-round mb-3">
    <div class="card-header bg-white"><h6 class="mb-0">Payment Allocations</h6></div>
    <div class="card-body p-0 table-responsive">
        <table class="table table-sm mb-0">
            <thead class="table-light"><tr><th>Payment Date</th><th class="text-end">Allocated</th><th>Reference</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($allocations as $alloc): ?>
            <tr>
                <td><?= h($alloc['payment_date']) ?></td>
                <td class="text-end"><?= co_format_money($alloc['allocated_amount']) ?></td>
                <td><?= h($alloc['payment_reference'] ?: '—') ?></td>
                <td class="text-end"><a href="supplier_payment_view.php?id=<?= (int)$alloc['payment_id'] ?>" class="btn btn-sm btn-outline-primary">View Payment</a></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<?php if ($vatLinks || $canLinkAdvanceVat): ?>
<div class="card card-round mb-3">
    <div class="card-header bg-white d-flex justify-content-between align-items-center">
        <h6 class="mb-0">Advance VAT Links</h6>
        <?php if ($canLinkAdvanceVat): ?>
        <button type="button" class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#linkAdvanceVatModal">Link Advance VAT</button>
        <?php endif; ?>
    </div>
    <div class="card-body p-0 table-responsive">
        <?php if ($vatLinks): ?>
        <table class="table table-sm mb-0">
            <thead class="table-light"><tr><th>Tax Invoice #</th><th>Source Payment</th><th class="text-end">VAT Linked</th><th>Status</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($vatLinks as $vl): ?>
            <tr>
                <td><a href="supplier_advance_vat_view.php?id=<?= (int)$vl['advance_vat_document_id'] ?>"><?= h($vl['supplier_invoice_number']) ?></a></td>
                <td><a href="supplier_payment_view.php?id=<?= (int)$vl['supplier_payment_id'] ?>">#<?= (int)$vl['supplier_payment_id'] ?></a></td>
                <td class="text-end"><?= co_format_money($vl['vat_amount_linked']) ?></td>
                <td><span class="badge bg-<?= ($vl['status'] ?? '') === 'posted' ? 'success' : 'secondary' ?>"><?= h(ucfirst((string)$vl['status'])) ?></span></td>
                <td class="text-end">
                    <?php if ($canEdit && ($vl['status'] ?? '') === 'posted'): ?>
                    <form method="post" class="d-inline" onsubmit="return confirm('Remove this advance VAT link?');">
                        <?php csrf_field(); ?>
                        <input type="hidden" name="action" value="unlink_advance_vat">
                        <input type="hidden" name="link_id" value="<?= (int)$vl['id'] ?>">
                        <button type="submit" class="btn btn-sm btn-outline-danger">Unlink</button>
                    </form>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php else: ?>
        <p class="text-muted small p-3 mb-0">No advance VAT linked yet. Link posted Advance VAT documents before posting this draft invoice so Input VAT is not double-counted.</p>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<?php if ($advanceTrace): ?>
<div class="card card-round mb-3">
    <div class="card-header bg-white"><h6 class="mb-0">Advance Applications (Traceability)</h6></div>
    <div class="card-body p-0 table-responsive">
        <table class="table table-sm mb-0">
            <thead class="table-light">
                <tr>
                    <th>Source Advance</th>
                    <th>Payment Date</th>
                    <th class="text-end">Applied Amount</th>
                    <th class="text-end">Remaining Advance Balance</th>
                    <th>Lifecycle</th>
                    <th>Status</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($advanceTrace as $app): ?>
            <tr>
                <td>
                    <a href="supplier_payment_view.php?id=<?= (int)$app['supplier_payment_id'] ?>">Payment #<?= (int)$app['supplier_payment_id'] ?></a>
                    <div class="small text-muted">Original <?= co_format_money($app['source_advance_original']) ?><?= !empty($app['payment_reference']) ? ' · ' . h($app['payment_reference']) : '' ?></div>
                </td>
                <td><?= h($app['payment_date']) ?></td>
                <td class="text-end fw-semibold"><?= co_format_money($app['amount']) ?></td>
                <td class="text-end"><?= co_format_money($app['source_advance_remaining']) ?></td>
                <td><span class="badge bg-info text-dark"><?= h(co_supplier_payment_advance_lifecycle_label((string)$app['source_advance_lifecycle'])) ?></span></td>
                <td><span class="badge bg-<?= ($app['status'] ?? '') === 'posted' ? 'success' : 'secondary' ?>"><?= h(ucfirst((string)$app['status'])) ?></span></td>
                <td class="text-end">
                    <?php if (($app['status'] ?? '') === 'posted' && !empty($inv['journal_id'])): ?>
                    <form method="post" class="d-inline" onsubmit="return confirm('Unapply this advance application?');">
                        <?php csrf_field(); ?>
                        <input type="hidden" name="action" value="unapply_advance">
                        <input type="hidden" name="application_id" value="<?= (int)$app['id'] ?>">
                        <button type="submit" class="btn btn-sm btn-outline-danger">Unapply</button>
                    </form>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<?php if ($canLinkAdvanceVat): ?>
<div class="modal fade" id="linkAdvanceVatModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <form method="post" class="modal-content">
      <?php csrf_field(); ?>
      <input type="hidden" name="action" value="link_advance_vat">
      <div class="modal-header"><h5 class="modal-title">Link Advance VAT</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <div class="modal-body">
        <p class="small text-muted">Links reduce Input VAT posted on this invoice (AP credit = total − linked VAT). Draft invoices only.</p>
        <label class="form-label">Advance VAT Document</label>
        <select name="advance_vat_document_id" id="advVatDocSelect" class="form-select" required>
            <option value="">— Select —</option>
            <?php foreach ($eligibleVatDocs as $doc): ?>
            <option value="<?= (int)$doc['id'] ?>" data-rem="<?= h(number_format((float)$doc['remaining_vat'], 2, '.', '')) ?>">
                <?= h($doc['supplier_invoice_number']) ?> · rem VAT <?= number_format((float)$doc['remaining_vat'], 2) ?> · pay #<?= (int)$doc['supplier_payment_id'] ?>
            </option>
            <?php endforeach; ?>
        </select>
        <label class="form-label mt-2">VAT Amount to Link</label>
        <?php
          $maxLink = min(
              max(0, (float)$inv['vat_amount'] - $linkedAdvanceVat),
              !empty($eligibleVatDocs) ? (float)$eligibleVatDocs[0]['remaining_vat'] : 0
          );
        ?>
        <input type="number" step="0.01" min="0.01" name="vat_amount" id="advVatAmt" class="form-control" required value="<?= h(number_format($maxLink, 2, '.', '')) ?>">
        <input type="hidden" name="taxable_amount" value="0">
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="submit" class="btn btn-primary">Link</button>
      </div>
    </form>
  </div>
</div>
<script>
(function(){
  var sel = document.getElementById('advVatDocSelect');
  var amt = document.getElementById('advVatAmt');
  if (!sel || !amt) return;
  sel.addEventListener('change', function(){
    var opt = sel.options[sel.selectedIndex];
    var rem = parseFloat(opt.getAttribute('data-rem') || '0');
    var invRem = <?= json_encode((float)max(0, (float)$inv['vat_amount'] - $linkedAdvanceVat)) ?>;
    var v = Math.min(rem, invRem);
    if (v > 0) amt.value = v.toFixed(2);
  });
})();
</script>
<?php endif; ?>

<?php if ($canApplyAdvance): ?>
<div class="modal fade" id="applyAdvanceModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <form method="post" class="modal-content">
      <?php csrf_field(); ?>
      <input type="hidden" name="action" value="apply_advance">
      <div class="modal-header"><h5 class="modal-title">Apply Supplier Advance</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <div class="modal-body">
        <p class="mb-2">Available advance: <strong><?= co_format_money($advanceBalance) ?></strong>. Invoice balance due: <strong><?= co_format_money($balanceDue) ?></strong>.</p>
        <p class="small text-muted">Posts Dr Supplier Payable / Cr Supplier Advances — no bank movement. FIFO from oldest advance payments.</p>
        <label class="form-label">Amount to apply</label>
        <input type="number" step="0.01" min="0.01" max="<?= h(min($advanceBalance, $balanceDue)) ?>" name="apply_amount" class="form-control" required value="<?= h(number_format(min($advanceBalance, $balanceDue), 2, '.', '')) ?>">
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="submit" class="btn btn-success">Apply Advance</button>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>

<?php if ($canVoid): ?>
<div class="modal fade" id="voidModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <form method="post" class="modal-content">
      <?php csrf_field(); ?>
      <input type="hidden" name="action" value="void">
      <div class="modal-header"><h5 class="modal-title">Void Invoice</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <div class="modal-body">
        <p>Voiding reverses the GL journal and marks this invoice read-only. It cannot be edited afterward.</p>
        <label class="form-label">Reason</label>
        <input type="text" name="reason" class="form-control" required maxlength="255" placeholder="Reason for void">
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="submit" class="btn btn-danger">Void Invoice</button>
      </div>
    </form>
  </div>
</div>
<div class="modal fade" id="voidAmendModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <form method="post" class="modal-content">
      <?php csrf_field(); ?>
      <input type="hidden" name="action" value="void_amend">
      <div class="modal-header"><h5 class="modal-title">Void + Amend</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <div class="modal-body">
        <p>Voids this posted unpaid invoice and opens a new linked draft for correction. Never edits posted financial values in place.</p>
        <label class="form-label">Reason</label>
        <input type="text" name="reason" class="form-control" required maxlength="255" placeholder="Reason for amendment">
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="submit" class="btn btn-warning">Void + Create Draft</button>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/includes/construction_layout_footer.php'; ?>
