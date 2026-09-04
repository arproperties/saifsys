<?php
/**
 * Construction Module — Delete Supplier Invoice (Draft only)
 * Posted invoices must be voided, not deleted.
 */

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../includes/company_helper.php';
require_once __DIR__ . '/../../includes/module_access.php';
require_once __DIR__ . '/../../includes/rbac_department.php';
require_once __DIR__ . '/includes/construction_helpers.php';
require_once __DIR__ . '/includes/construction_supplier_ap_helpers.php';
require_once __DIR__ . '/includes/construction_accounting_integration.php';

require_login();
require_module_access($conn, MODULE_CONSTRUCTION);

$cid = co_supplier_require_company_id($conn);
$userId = current_user_id();
$id = (int)($_REQUEST['id'] ?? 0);
if (!$id) { header('Location: supplier_invoices.php'); exit; }

$stmt = $conn->prepare("SELECT si.*, s.supplier_name FROM co_supplier_invoices si JOIN co_suppliers s ON s.id = si.supplier_id WHERE si.id = ? AND si.company_id = ?");
$stmt->execute([$id, $cid]);
$invoice = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$invoice) { header('Location: supplier_invoices.php'); exit; }

$status = co_supplier_invoice_status($invoice);
$err = '';

if ($status !== 'draft' || !empty($invoice['journal_id'])) {
    $_SESSION['co_supplier_inv_flash'] = [
        'err' => 'Only draft invoices can be deleted. Posted invoices must be voided from the invoice view.',
    ];
    header('Location: supplier_invoice_view.php?id=' . $id);
    exit;
}

$paidAmount = co_supplier_invoice_paid_amount($conn, $cid, $id);
if ($paidAmount > 0.005) {
    $err = 'Cannot delete an invoice with payments applied.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$err) {
    csrf_verify();
    try {
        if (co_db_table_exists($conn, 'co_supplier_invoice_items')) {
            $conn->prepare("DELETE FROM co_supplier_invoice_items WHERE invoice_id = ? AND company_id = ?")
                ->execute([$id, $cid]);
        }

        if (co_db_table_exists($conn, 'co_supplier_invoice_documents')) {
            $docStmt = $conn->prepare("SELECT id, file_path FROM co_supplier_invoice_documents WHERE supplier_invoice_id = ? AND company_id = ?");
            $docStmt->execute([$id, $cid]);
            $docs = $docStmt->fetchAll(PDO::FETCH_ASSOC);
            $conn->prepare("DELETE FROM co_supplier_invoice_documents WHERE supplier_invoice_id = ? AND company_id = ?")->execute([$id, $cid]);
            $appRoot = dirname(__DIR__, 2);
            foreach ($docs as $doc) {
                $fullPath = $appRoot . '/' . ltrim((string)($doc['file_path'] ?? ''), '/');
                if ($fullPath && file_exists($fullPath)) {
                    @unlink($fullPath);
                }
            }
        }

        $conn->prepare("DELETE FROM co_supplier_invoices WHERE id = ? AND company_id = ? AND journal_id IS NULL")->execute([$id, $cid]);
        co_supplier_ap_audit(
            $conn,
            $cid,
            (int)$invoice['supplier_id'],
            $id,
            null,
            'invoice_deleted',
            (string)$invoice['invoice_number'],
            null,
            (float)$invoice['total'],
            'Draft supplier invoice deleted',
            $userId ? (int)$userId : null,
            'supplier_invoice',
            null
        );
        header('Location: supplier_view.php?id=' . (int)$invoice['supplier_id']);
        exit;
    } catch (Throwable $e) {
        $err = $e->getMessage();
    }
}

require_once __DIR__ . '/../../includes/branding.php';
$brand = getBrandSettings($conn);
$pageTitle = 'Delete Supplier Invoice';
require_once __DIR__ . '/includes/construction_layout_header.php';
?>

<div class="mb-4">
    <a href="supplier_invoice_view.php?id=<?= $id ?>" class="btn btn-outline-secondary btn-sm mb-2"><i class="bi bi-arrow-left"></i> Back</a>
    <h1 class="h4 mb-0">Delete Supplier Invoice</h1>
</div>
<?php if ($err): ?>
<div class="alert alert-danger"><?= h($err) ?></div>
<p><a href="supplier_invoice_view.php?id=<?= $id ?>" class="btn btn-outline-secondary">Return to Invoice</a></p>
<?php else: ?>
<div class="card card-round">
    <div class="card-body">
        <p class="mb-1">Delete draft invoice <strong><?= h($invoice['invoice_number']) ?></strong> for <strong><?= h($invoice['supplier_name']) ?></strong>?</p>
        <p class="text-muted">Total: <?= co_format_money($invoice['total']) ?>. Drafts are permanently removed. Posted invoices must be voided instead.</p>
        <form method="post" class="d-flex gap-2">
            <?php csrf_field(); ?>
            <button type="submit" class="btn btn-danger">Delete Draft</button>
            <a href="supplier_invoice_view.php?id=<?= $id ?>" class="btn btn-outline-secondary">Cancel</a>
        </form>
    </div>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/includes/construction_layout_footer.php'; ?>
