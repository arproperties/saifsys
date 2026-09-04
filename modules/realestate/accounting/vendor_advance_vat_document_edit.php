<?php
/**
 * Create / edit draft Vendor Advance VAT Document
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
require_once __DIR__ . '/../../../includes/csrf.php';
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
$userId = current_user_id();
$docId = (int)($_GET['id'] ?? 0);
$error = '';
$success = '';

function h($s)
{
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

if (!re_ap_advance_vat_table_ready($conn)) {
    http_response_code(503);
    die('Advance VAT schema is not installed. Run migrations/re_vendor_advance_vat_documents.sql');
}

$doc = $docId > 0 ? re_ap_load_advance_vat_doc($conn, $companyId, $docId) : null;
if ($docId > 0 && !$doc) {
    header('Location: vendor_advance_vat_documents.php');
    exit;
}
if ($doc && ($doc['status'] ?? '') !== 'draft') {
    header('Location: vendor_advance_vat_document_view.php?id=' . $docId);
    exit;
}

$vendorId = (int)($_GET['vendor_id'] ?? ($doc['vendor_id'] ?? 0));
$paymentId = (int)($_GET['payment_id'] ?? ($doc['vendor_payment_id'] ?? 0));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        csrf_verify();
        $vendorId = (int)($_POST['vendor_id'] ?? 0);
        $paymentId = (int)($_POST['vendor_payment_id'] ?? 0);
        $data = [
            'vendor_id' => $vendorId,
            'vendor_payment_id' => $paymentId,
            'supplier_invoice_number' => trim((string)($_POST['supplier_invoice_number'] ?? '')),
            'supplier_invoice_date' => (string)($_POST['supplier_invoice_date'] ?? ''),
            'supplier_trn' => trim((string)($_POST['supplier_trn'] ?? '')),
            'taxable_amount' => (float)($_POST['taxable_amount'] ?? 0),
            'vat_amount' => (float)($_POST['vat_amount'] ?? 0),
            'gross_amount' => (float)($_POST['gross_amount'] ?? 0),
            'currency_code' => (string)($_POST['currency_code'] ?? 'AED'),
            'notes' => trim((string)($_POST['notes'] ?? '')),
        ];
        $save = re_ap_save_advance_vat_draft($conn, $companyId, $data, $userId, $docId ?: null);
        if (empty($save['success'])) {
            throw new RuntimeException($save['error'] ?? 'Could not save draft.');
        }
        $docId = (int)$save['id'];
        if (!empty($_FILES['attachments']['name'][0])) {
            re_ap_store_advance_vat_attachments($conn, $companyId, $docId, $_FILES['attachments'], $userId);
        }
        $doPost = !empty($_POST['save_and_post']);
        if ($doPost) {
            $post = re_ap_post_advance_vat_document($conn, $companyId, $docId, $userId);
            if (empty($post['success'])) {
                throw new RuntimeException('Draft saved but posting failed: ' . ($post['error'] ?? 'unknown'));
            }
        }
        header('Location: vendor_advance_vat_document_view.php?id=' . $docId . ($doPost ? '&posted=1' : '&saved=1'));
        exit;
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$vendors = $conn->prepare("SELECT id, vendor_name, tax_id FROM re_vendors WHERE company_id = ? AND status = 'active' ORDER BY vendor_name");
$vendors->execute([$companyId]);
$vendors = $vendors->fetchAll(PDO::FETCH_ASSOC) ?: [];

$payments = [];
if ($vendorId > 0) {
    $ps = $conn->prepare("
        SELECT vp.id, vp.payment_date, vp.amount, vp.advance_amount, vp.reference_number, vp.status
        FROM re_vendor_payments vp
        WHERE vp.company_id = ? AND vp.vendor_id = ? AND vp.status = 'posted' AND COALESCE(vp.advance_amount,0) > 0.005
        ORDER BY vp.payment_date DESC, vp.id DESC
        LIMIT 200
    ");
    $ps->execute([$companyId, $vendorId]);
    $payments = $ps->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

$form = [
    'supplier_invoice_number' => $_POST['supplier_invoice_number'] ?? ($doc['supplier_invoice_number'] ?? ''),
    'supplier_invoice_date' => $_POST['supplier_invoice_date'] ?? ($doc['supplier_invoice_date'] ?? date('Y-m-d')),
    'supplier_trn' => $_POST['supplier_trn'] ?? ($doc['supplier_trn'] ?? ''),
    'taxable_amount' => $_POST['taxable_amount'] ?? ($doc['taxable_amount'] ?? ''),
    'vat_amount' => $_POST['vat_amount'] ?? ($doc['vat_amount'] ?? ''),
    'gross_amount' => $_POST['gross_amount'] ?? ($doc['gross_amount'] ?? ''),
    'currency_code' => $_POST['currency_code'] ?? ($doc['currency_code'] ?? 'AED'),
    'notes' => $_POST['notes'] ?? ($doc['notes'] ?? ''),
];
if ($form['supplier_trn'] === '' && $vendorId > 0) {
    foreach ($vendors as $v) {
        if ((int)$v['id'] === $vendorId && !empty($v['tax_id'])) {
            $form['supplier_trn'] = $v['tax_id'];
            break;
        }
    }
}

$pageTitle = $docId ? 'Edit Advance VAT Document' : 'New Advance VAT Document';
require_once __DIR__ . '/../includes/re_layout_header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <div class="page-header-label"><i data-lucide="file-plus" class="me-1"></i> <?= h($pageTitle) ?></div>
    <a href="vendor_advance_vat_documents.php" class="btn btn-outline-secondary">Back to list</a>
</div>

<?php if ($error): ?><div class="alert alert-danger"><?= h($error) ?></div><?php endif; ?>

<div class="alert alert-info">
    Enter values from the supplier VAT document. Do not infer VAT from the advance payment amount.
    Posting creates a separate journal: <strong>Dr Input VAT / Cr 1410</strong>. The payment journal is not changed.
</div>

<form method="post" enctype="multipart/form-data" class="card card-round">
    <div class="card-body">
        <?php csrf_field(); ?>
        <div class="row g-3">
            <div class="col-md-4">
                <label class="form-label">Vendor *</label>
                <select name="vendor_id" id="vendor_id" class="form-select" required onchange="location.href='?id=<?= (int)$docId ?>&vendor_id='+this.value">
                    <option value="">-- Select --</option>
                    <?php foreach ($vendors as $v): ?>
                        <option value="<?= (int)$v['id'] ?>" <?= $vendorId === (int)$v['id'] ? 'selected' : '' ?>><?= h($v['vendor_name']) ?><?= !empty($v['tax_id']) ? ' (TRN ' . h($v['tax_id']) . ')' : '' ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label">Advance payment *</label>
                <select name="vendor_payment_id" class="form-select" required <?= $vendorId ? '' : 'disabled' ?>>
                    <option value="">-- Select payment --</option>
                    <?php foreach ($payments as $p): ?>
                        <?php $rem = re_ap_payment_advance_net_remaining($conn, $companyId, (int)$p['id']); ?>
                        <option value="<?= (int)$p['id'] ?>" <?= $paymentId === (int)$p['id'] ? 'selected' : '' ?>>
                            PAY-<?= (int)$p['id'] ?> · <?= h($p['payment_date']) ?> · Adv <?= number_format((float)$p['advance_amount'], 2) ?> · Rem <?= number_format($rem, 2) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <div class="form-text">Remaining = advance − applied − posted VAT on that payment.</div>
            </div>
            <div class="col-md-2">
                <label class="form-label">Supplier inv # *</label>
                <input name="supplier_invoice_number" class="form-control" required value="<?= h($form['supplier_invoice_number']) ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label">Supplier inv date *</label>
                <input type="date" name="supplier_invoice_date" class="form-control" required value="<?= h($form['supplier_invoice_date']) ?>">
            </div>
            <div class="col-md-3">
                <label class="form-label">Supplier TRN</label>
                <input name="supplier_trn" class="form-control" value="<?= h($form['supplier_trn']) ?>" placeholder="Per document / vendor">
            </div>
            <div class="col-md-2">
                <label class="form-label">Currency</label>
                <input name="currency_code" class="form-control" maxlength="3" value="<?= h($form['currency_code']) ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label">Taxable *</label>
                <input type="number" step="0.01" min="0" name="taxable_amount" id="taxable_amount" class="form-control" required value="<?= h((string)$form['taxable_amount']) ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label">VAT *</label>
                <input type="number" step="0.01" min="0.01" name="vat_amount" id="vat_amount" class="form-control" required value="<?= h((string)$form['vat_amount']) ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label">Gross *</label>
                <input type="number" step="0.01" min="0.01" name="gross_amount" id="gross_amount" class="form-control" required value="<?= h((string)$form['gross_amount']) ?>">
            </div>
            <div class="col-md-12">
                <label class="form-label">Notes</label>
                <textarea name="notes" class="form-control" rows="2"><?= h($form['notes']) ?></textarea>
            </div>
            <div class="col-md-12">
                <label class="form-label">Attachments (PDF/JPG/PNG/WEBP)</label>
                <input type="file" name="attachments[]" class="form-control" multiple accept=".pdf,.png,.jpg,.jpeg,.webp">
            </div>
        </div>
    </div>
    <div class="card-footer d-flex gap-2 justify-content-end">
        <button type="submit" name="save_draft" value="1" class="btn btn-outline-primary">Save Draft</button>
        <button type="submit" name="save_and_post" value="1" class="btn btn-success" onclick="return confirm('Post this Advance VAT document? This will Dr Input VAT / Cr 1410.');">Save &amp; Post</button>
    </div>
</form>
<script>
(function () {
    var t = document.getElementById('taxable_amount');
    var v = document.getElementById('vat_amount');
    var g = document.getElementById('gross_amount');
    function syncGross() {
        var tv = parseFloat(t.value || '0');
        var vv = parseFloat(v.value || '0');
        if (!isNaN(tv) && !isNaN(vv) && (g.value === '' || Math.abs(parseFloat(g.value || '0') - (tv + vv)) < 0.005 || g.dataset.auto === '1')) {
            g.value = (tv + vv).toFixed(2);
            g.dataset.auto = '1';
        }
    }
    t.addEventListener('input', syncGross);
    v.addEventListener('input', syncGross);
    g.addEventListener('input', function () { g.dataset.auto = '0'; });
})();
</script>
<?php require_once __DIR__ . '/../includes/re_layout_footer.php'; ?>
