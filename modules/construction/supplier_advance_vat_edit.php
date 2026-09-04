<?php
/**
 * Construction — Create/Edit Advance VAT draft
 */
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../includes/company_helper.php';
require_once __DIR__ . '/../../includes/module_access.php';
require_once __DIR__ . '/includes/construction_helpers.php';
require_once __DIR__ . '/includes/construction_supplier_ap_helpers.php';
require_once __DIR__ . '/includes/construction_supplier_advance_helpers.php';

require_login();
require_module_access($conn, MODULE_CONSTRUCTION);
require_once __DIR__ . '/../../includes/branding.php';
$brand = getBrandSettings($conn);
$cid = co_supplier_require_company_id($conn);
$userId = current_user_id();
$id = (int)($_GET['id'] ?? 0);
$paymentPrefill = (int)($_GET['payment_id'] ?? 0);
$err = '';
$doc = $id ? co_supplier_load_advance_vat_doc($conn, $cid, $id) : null;
if ($id && !$doc) { header('Location: supplier_advance_vat_documents.php'); exit; }
if ($doc && ($doc['status'] ?? '') !== 'draft') {
    header('Location: supplier_advance_vat_view.php?id=' . $id); exit;
}

$payments = [];
$st = $conn->prepare("
    SELECT sp.id, sp.payment_date, sp.amount, sp.advance_amount, s.supplier_name, sp.supplier_id
    FROM co_supplier_payments sp
    JOIN co_suppliers s ON s.id = sp.supplier_id
    WHERE sp.company_id = ? AND sp.journal_id IS NOT NULL AND COALESCE(sp.advance_amount,0) > 0.005
    ORDER BY sp.payment_date DESC, sp.id DESC
    LIMIT 200
");
$st->execute([$cid]);
foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $p) {
    $rem = co_supplier_payment_advance_remaining($conn, $cid, (int)$p['id']);
    if ($rem > 0.005 || ($doc && (int)$doc['supplier_payment_id'] === (int)$p['id'])) {
        $p['remaining'] = $rem;
        $payments[] = $p;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $taxable = (float)($_POST['taxable_amount'] ?? 0);
    $vat = (float)($_POST['vat_amount'] ?? 0);
    $res = co_supplier_save_advance_vat_draft($conn, $cid, [
        'supplier_payment_id' => (int)($_POST['supplier_payment_id'] ?? 0),
        'supplier_invoice_number' => trim($_POST['supplier_invoice_number'] ?? ''),
        'supplier_invoice_date' => $_POST['supplier_invoice_date'] ?? date('Y-m-d'),
        'supplier_trn' => trim($_POST['supplier_trn'] ?? ''),
        'taxable_amount' => $taxable,
        'vat_amount' => $vat,
        'gross_amount' => $taxable + $vat,
        'notes' => trim($_POST['notes'] ?? ''),
    ], $userId ? (int)$userId : null, $id ?: null);
    if (!empty($res['success'])) {
        header('Location: supplier_advance_vat_view.php?id=' . (int)$res['id'] . '&saved=1');
        exit;
    }
    $err = $res['error'] ?? 'Save failed';
}

$form = $_SERVER['REQUEST_METHOD'] === 'POST' ? $_POST : ($doc ?: [
    'supplier_payment_id' => $paymentPrefill,
    'supplier_invoice_date' => date('Y-m-d'),
    'vat_amount' => '',
    'taxable_amount' => '',
]);
$pageTitle = $id ? 'Edit Advance VAT Draft' : 'New Advance VAT Document';
require_once __DIR__ . '/includes/construction_layout_header.php';
?>
<div class="mb-4">
    <a href="supplier_advance_vat_documents.php" class="btn btn-outline-secondary btn-sm mb-2">Back</a>
    <h1 class="h4 mb-0"><?= h($pageTitle) ?></h1>
</div>
<?php if ($err): ?><div class="alert alert-danger"><?= h($err) ?></div><?php endif; ?>
<form method="post" class="card card-round"><div class="card-body row g-3">
<?php csrf_field(); ?>
<div class="col-md-8">
    <label class="form-label">Source Advance Payment *</label>
    <select name="supplier_payment_id" class="form-select" required>
        <option value="">— Select —</option>
        <?php foreach ($payments as $p): ?>
        <option value="<?= (int)$p['id'] ?>" <?= (int)($form['supplier_payment_id'] ?? 0) === (int)$p['id'] ? 'selected' : '' ?>>
            #<?= (int)$p['id'] ?> · <?= h($p['supplier_name']) ?> · <?= h($p['payment_date']) ?> · rem <?= number_format((float)$p['remaining'], 2) ?>
        </option>
        <?php endforeach; ?>
    </select>
</div>
<div class="col-md-4"><label class="form-label">Tax Invoice Date *</label><input type="date" name="supplier_invoice_date" class="form-control" required value="<?= h($form['supplier_invoice_date'] ?? date('Y-m-d')) ?>"></div>
<div class="col-md-4"><label class="form-label">Tax Invoice Number *</label><input type="text" name="supplier_invoice_number" class="form-control" required value="<?= h($form['supplier_invoice_number'] ?? '') ?>"></div>
<div class="col-md-4"><label class="form-label">Supplier TRN</label><input type="text" name="supplier_trn" class="form-control" value="<?= h($form['supplier_trn'] ?? '') ?>"></div>
<div class="col-md-4"><label class="form-label">Taxable *</label><input type="number" step="0.01" name="taxable_amount" class="form-control" required value="<?= h($form['taxable_amount'] ?? '') ?>"></div>
<div class="col-md-4"><label class="form-label">VAT *</label><input type="number" step="0.01" name="vat_amount" class="form-control" required value="<?= h($form['vat_amount'] ?? '') ?>"></div>
<div class="col-12"><label class="form-label">Notes</label><textarea name="notes" class="form-control" rows="2"><?= h($form['notes'] ?? '') ?></textarea></div>
<div class="col-12"><button class="btn btn-primary">Save Draft</button></div>
</div></form>
<?php require_once __DIR__ . '/includes/construction_layout_footer.php'; ?>
