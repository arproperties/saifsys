<?php
/**
 * Construction Module — Edit Contractor
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

$linkableSuppliers = co_contractor_linkable_suppliers($conn, $cid, $id);
$err = '';
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
    $is_active = isset($_POST['is_active']) ? 1 : 0;
    $supplier_id = (int)($_POST['supplier_id'] ?? 0);

    if (!$contractor_name) $err = 'Contractor name is required.';
    if (!$err) {
        $stmt = $conn->prepare("
            UPDATE co_contractors SET contractor_name=?, contact_person=?, email=?, phone=?, address=?, tax_number=?,
                bank_name=?, account_number=?, iban=?, swift_code=?, bank_details=?, notes=?, is_active=?
            WHERE id=? AND company_id=?
        ");
        $stmt->execute([$contractor_name, $contact_person ?: null, $email ?: null, $phone ?: null, $address ?: null, $tax_number ?: null, $bank_name ?: null, $account_number ?: null, $iban ?: null, $swift_code ?: null, $bank_details ?: null, $notes ?: null, $is_active, $id, $cid]);
        if (co_contractor_link_schema_ready($conn)) {
            $link = co_contractor_set_supplier_link($conn, $cid, $id, $supplier_id > 0 ? $supplier_id : null);
            if (!$link['ok']) {
                $err = $link['error'] ?? 'Could not update supplier link.';
            }
        }
        if (!$err) {
            header('Location: contractor_view.php?id=' . $id);
            exit;
        }
    }
} else {
    $_POST = $c;
}

$pageTitle = 'Edit Contractor';
require_once __DIR__ . '/includes/construction_layout_header.php';
?>

<div class="mb-4">
    <a href="contractor_view.php?id=<?= $id ?>" class="btn btn-outline-secondary btn-sm mb-2"><i class="bi bi-arrow-left"></i> Back</a>
    <h1 class="h4 mb-0">Edit Contractor</h1>
</div>
<?php if ($err): ?><div class="alert alert-danger"><?= h($err) ?></div><?php endif; ?>

<form method="post" class="card card-round">
    <?php if (function_exists('csrf_field')) csrf_field(); ?>
    <div class="card-body">
        <div class="row g-3">
            <div class="col-md-8"><label class="form-label">Contractor Name *</label><input type="text" name="contractor_name" class="form-control" required value="<?= h($_POST['contractor_name'] ?? '') ?>"></div>
            <div class="col-md-4"><label class="form-label">Contact Person</label><input type="text" name="contact_person" class="form-control" value="<?= h($_POST['contact_person'] ?? '') ?>"></div>
            <?php if (co_contractor_link_schema_ready($conn)): ?>
            <div class="col-md-8">
                <label class="form-label">Linked Supplier (Business Partner)</label>
                <select name="supplier_id" class="form-select">
                    <option value="">— Not linked —</option>
                    <?php
                    $currentSid = (int)($_POST['supplier_id'] ?? 0);
                    $opts = $linkableSuppliers;
                    if ($currentSid > 0) {
                        $found = false;
                        foreach ($opts as $o) { if ((int)$o['id'] === $currentSid) { $found = true; break; } }
                        if (!$found) {
                            $sn = $conn->prepare("SELECT id, supplier_name FROM co_suppliers WHERE id = ? AND company_id = ?");
                            $sn->execute([$currentSid, $cid]);
                            $row = $sn->fetch(PDO::FETCH_ASSOC);
                            if ($row) array_unshift($opts, $row);
                        }
                    }
                    foreach ($opts as $sup):
                    ?>
                    <option value="<?= (int)$sup['id'] ?>" <?= $currentSid === (int)$sup['id'] ? 'selected' : '' ?>><?= h($sup['supplier_name']) ?></option>
                    <?php endforeach; ?>
                </select>
                <div class="form-text">Accounting entity for invoices and payments. One supplier per contractor.</div>
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
            <div class="col-12">
                <div class="form-check">
                    <input type="checkbox" name="is_active" id="is_active" class="form-check-input" value="1" <?= !empty($_POST['is_active']) ? 'checked' : '' ?>>
                    <label class="form-check-label" for="is_active">Active</label>
                </div>
            </div>
        </div>
        <div class="mt-3"><button type="submit" class="btn btn-primary">Save Changes</button></div>
    </div>
</form>

<?php require_once __DIR__ . '/includes/construction_layout_footer.php'; ?>
