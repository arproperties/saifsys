<?php
/**
 * Construction Module — Add Supplier (Expenses Phase 1)
 */

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../includes/company_helper.php';
require_once __DIR__ . '/../../includes/module_access.php';
require_once __DIR__ . '/../../includes/rbac_department.php';
require_once __DIR__ . '/includes/construction_helpers.php';
require_once __DIR__ . '/includes/construction_supplier_ap_helpers.php';

require_login();
require_module_access($conn, MODULE_CONSTRUCTION);

require_once __DIR__ . '/../../includes/branding.php';
$brand = getBrandSettings($conn);
$cid = co_supplier_require_company_id($conn);
$err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $supplier_name = trim($_POST['supplier_name'] ?? '');
    $contact_person = trim($_POST['contact_person'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $trn = trim($_POST['tax_vat_number'] ?? '');
    $tax_number = $trn;
    $vat_number = $trn;
    $bank_name = trim($_POST['bank_name'] ?? '');
    $account_number = trim($_POST['account_number'] ?? '');
    $iban = trim($_POST['iban'] ?? '');
    $swift_code = trim($_POST['swift_code'] ?? '');
    $bank_details = trim($_POST['bank_details'] ?? '');
    $notes = trim($_POST['notes'] ?? '');
    $is_active = isset($_POST['is_active']) ? 1 : 1;

    if (!$supplier_name) $err = 'Supplier name is required.';
    if (!$err) {
        $stmt = $conn->prepare("INSERT INTO co_suppliers (company_id, supplier_name, contact_person, email, phone, address, tax_number, vat_number, bank_name, account_number, iban, swift_code, bank_details, notes, is_active) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,1)");
        $stmt->execute([$cid, $supplier_name, $contact_person ?: null, $email ?: null, $phone ?: null, $address ?: null, $tax_number ?: null, $vat_number ?: null, $bank_name ?: null, $account_number ?: null, $iban ?: null, $swift_code ?: null, $bank_details ?: null, $notes ?: null]);
        header('Location: supplier_view.php?id=' . (int)$conn->lastInsertId());
        exit;
    }
}

$pageTitle = 'Add Supplier';
require_once __DIR__ . '/includes/construction_layout_header.php';
?>

<div class="mb-4"><a href="suppliers.php" class="btn btn-outline-secondary btn-sm mb-2"><i class="bi bi-arrow-left"></i> Back</a><h1 class="h4 mb-0">Add Supplier</h1></div>
<?php if ($err): ?><div class="alert alert-danger"><?= h($err) ?></div><?php endif; ?>

<form method="post" class="card card-round">
    <div class="card-body">
        <div class="row g-3">
            <div class="col-md-8"><label class="form-label">Supplier Name *</label><input type="text" name="supplier_name" class="form-control" required value="<?= h($_POST['supplier_name'] ?? '') ?>"></div>
            <div class="col-md-4"><label class="form-label">Contact Person</label><input type="text" name="contact_person" class="form-control" value="<?= h($_POST['contact_person'] ?? '') ?>"></div>
            <div class="col-md-4"><label class="form-label">Phone</label><input type="text" name="phone" class="form-control" value="<?= h($_POST['phone'] ?? '') ?>"></div>
            <div class="col-md-4"><label class="form-label">Email</label><input type="email" name="email" class="form-control" value="<?= h($_POST['email'] ?? '') ?>"></div>
            <div class="col-md-4">
                <label class="form-label">Tax / VAT registration (TRN)</label>
                <input type="text" name="tax_vat_number" class="form-control" value="<?= h($_POST['tax_vat_number'] ?? '') ?>" placeholder="e.g. UAE TRN">
                <small class="text-muted">Tax or VAT registration number.</small>
            </div>
            <div class="col-12"><label class="form-label">Address</label><textarea name="address" class="form-control" rows="2"><?= h($_POST['address'] ?? '') ?></textarea></div>
            <div class="col-12"><h6 class="text-muted mb-2 mt-2">Bank Details</h6></div>
            <div class="col-md-6"><label class="form-label">Bank Name</label><input type="text" name="bank_name" class="form-control" value="<?= h($_POST['bank_name'] ?? '') ?>"></div>
            <div class="col-md-6"><label class="form-label">Account Number</label><input type="text" name="account_number" class="form-control" value="<?= h($_POST['account_number'] ?? '') ?>"></div>
            <div class="col-md-6"><label class="form-label">IBAN</label><input type="text" name="iban" class="form-control" value="<?= h($_POST['iban'] ?? '') ?>"></div>
            <div class="col-md-6"><label class="form-label">SWIFT Code</label><input type="text" name="swift_code" class="form-control" value="<?= h($_POST['swift_code'] ?? '') ?>"></div>
            <div class="col-12"><label class="form-label">Additional Bank Notes</label><textarea name="bank_details" class="form-control" rows="1"><?= h($_POST['bank_details'] ?? '') ?></textarea></div>
            <div class="col-12"><label class="form-label">Notes</label><textarea name="notes" class="form-control" rows="2"><?= h($_POST['notes'] ?? '') ?></textarea></div>
        </div>
        <div class="mt-3"><button type="submit" class="btn btn-primary">Save Supplier</button></div>
    </div>
</form>

<?php require_once __DIR__ . '/includes/construction_layout_footer.php'; ?>
