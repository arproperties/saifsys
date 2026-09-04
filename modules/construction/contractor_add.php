<?php
/**
 * Construction Module — Add Contractor
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
$err = '';
$linkableSuppliers = co_contractor_linkable_suppliers($conn, $cid, null);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (function_exists('csrf_verify')) {
        csrf_verify();
    }
    $contractor_name = trim($_POST['contractor_name'] ?? '');
    $contact_person = trim($_POST['contact_person'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $tax_number = trim($_POST['tax_number'] ?? '');
    $bank_name = trim($_POST['bank_name'] ?? '');
    $account_number = trim($_POST['account_number'] ?? '');
    $iban = trim($_POST['iban'] ?? '');
    $swift_code = trim($_POST['swift_code'] ?? '');
    $bank_details = trim($_POST['bank_details'] ?? '');
    $notes = trim($_POST['notes'] ?? '');
    $supplier_id = (int)($_POST['supplier_id'] ?? 0);
    $create_supplier = !empty($_POST['create_supplier']);

    if (!$contractor_name) $err = 'Contractor name is required.';
    if (!$err) {
        $stmt = $conn->prepare("INSERT INTO co_contractors (company_id, contractor_name, contact_person, email, phone, address, tax_number, bank_name, account_number, iban, swift_code, bank_details, notes, is_active) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,1)");
        $stmt->execute([$cid, $contractor_name, $contact_person ?: null, $email ?: null, $phone ?: null, $address ?: null, $tax_number ?: null, $bank_name ?: null, $account_number ?: null, $iban ?: null, $swift_code ?: null, $bank_details ?: null, $notes ?: null]);
        $newId = (int)$conn->lastInsertId();
        if (co_contractor_link_schema_ready($conn)) {
            if ($create_supplier) {
                $res = co_contractor_create_supplier_from_contractor($conn, $cid, $newId, current_user_id());
                if (!$res['ok']) {
                    $err = $res['error'] ?? 'Contractor saved but supplier create failed.';
                }
            } elseif ($supplier_id > 0) {
                $res = co_contractor_set_supplier_link($conn, $cid, $newId, $supplier_id);
                if (!$res['ok']) {
                    $err = $res['error'] ?? 'Contractor saved but link failed.';
                }
            }
        }
        if (!$err) {
            header('Location: contractor_view.php?id=' . $newId);
            exit;
        }
    }
}

$pageTitle = 'Add Contractor';
require_once __DIR__ . '/includes/construction_layout_header.php';
?>

<div class="mb-4"><a href="contractors.php" class="btn btn-outline-secondary btn-sm mb-2"><i class="bi bi-arrow-left"></i> Back</a><h1 class="h4 mb-0">Add Contractor</h1></div>
<?php if ($err): ?><div class="alert alert-danger"><?= h($err) ?></div><?php endif; ?>

<form method="post" class="card card-round">
    <?php if (function_exists('csrf_field')) csrf_field(); ?>
    <div class="card-body">
        <div class="row g-3">
            <div class="col-md-8"><label class="form-label">Contractor Name *</label><input type="text" name="contractor_name" class="form-control" required value="<?= h($_POST['contractor_name'] ?? '') ?>"></div>
            <div class="col-md-4"><label class="form-label">Contact Person</label><input type="text" name="contact_person" class="form-control" value="<?= h($_POST['contact_person'] ?? '') ?>"></div>
            <?php if (co_contractor_link_schema_ready($conn)): ?>
            <div class="col-md-8">
                <label class="form-label">Linked Supplier (optional)</label>
                <select name="supplier_id" class="form-select">
                    <option value="">— Link later —</option>
                    <?php foreach ($linkableSuppliers as $sup): ?>
                    <option value="<?= (int)$sup['id'] ?>" <?= (int)($_POST['supplier_id'] ?? 0) === (int)$sup['id'] ? 'selected' : '' ?>><?= h($sup['supplier_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-4 d-flex align-items-end">
                <div class="form-check mb-2">
                    <input type="checkbox" class="form-check-input" name="create_supplier" id="create_supplier" value="1" <?= !empty($_POST['create_supplier']) ? 'checked' : '' ?>>
                    <label class="form-check-label" for="create_supplier">Create Supplier from contractor</label>
                </div>
            </div>
            <?php endif; ?>
            <div class="col-md-4"><label class="form-label">Phone</label><input type="text" name="phone" class="form-control" value="<?= h($_POST['phone'] ?? '') ?>"></div>
            <div class="col-md-4"><label class="form-label">Email</label><input type="email" name="email" class="form-control" value="<?= h($_POST['email'] ?? '') ?>"></div>
            <div class="col-md-4"><label class="form-label">Tax Number</label><input type="text" name="tax_number" class="form-control" value="<?= h($_POST['tax_number'] ?? '') ?>"></div>
            <div class="col-12"><label class="form-label">Address</label><textarea name="address" class="form-control" rows="2"><?= h($_POST['address'] ?? '') ?></textarea></div>
            <div class="col-12"><h6 class="text-muted mb-2 mt-2">Bank Details</h6></div>
            <div class="col-md-6"><label class="form-label">Bank Name</label><input type="text" name="bank_name" class="form-control" value="<?= h($_POST['bank_name'] ?? '') ?>" placeholder="e.g. Emirates NBD"></div>
            <div class="col-md-6"><label class="form-label">Account Number</label><input type="text" name="account_number" class="form-control" value="<?= h($_POST['account_number'] ?? '') ?>"></div>
            <div class="col-md-6"><label class="form-label">IBAN</label><input type="text" name="iban" class="form-control" value="<?= h($_POST['iban'] ?? '') ?>" placeholder="AE07 0331 2345 6789 0123 456"></div>
            <div class="col-md-6"><label class="form-label">SWIFT Code</label><input type="text" name="swift_code" class="form-control" value="<?= h($_POST['swift_code'] ?? '') ?>" placeholder="e.g. EBILAEAD"></div>
            <div class="col-12"><label class="form-label">Additional Bank Notes</label><textarea name="bank_details" class="form-control" rows="1" placeholder="Optional extra info (branch, beneficiary name, etc.)"><?= h($_POST['bank_details'] ?? '') ?></textarea></div>
            <div class="col-12"><label class="form-label">Notes</label><textarea name="notes" class="form-control" rows="2"><?= h($_POST['notes'] ?? '') ?></textarea></div>
        </div>
        <div class="mt-3"><button type="submit" class="btn btn-primary">Save Contractor</button></div>
    </div>
</form>

<?php require_once __DIR__ . '/includes/construction_layout_footer.php'; ?>
