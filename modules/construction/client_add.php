<?php
/**
 * Construction Module — Add Client
 */

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../includes/company_helper.php';
require_once __DIR__ . '/../../includes/module_access.php';
require_once __DIR__ . '/../../includes/rbac_department.php';
require_once __DIR__ . '/includes/construction_income_helpers.php';

require_login();
require_module_access($conn, MODULE_CONSTRUCTION);

require_once __DIR__ . '/../../includes/branding.php';
$brand = getBrandSettings($conn);
$cid = current_company_id($conn) ?: 1;
$err = '';
$hasIncomeClientColumns = co_db_column_exists($conn, 'co_clients', 'client_type');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $client_name = trim($_POST['client_name'] ?? '');
    $client_type = $_POST['client_type'] ?? 'customer';
    $contact_person = trim($_POST['contact_person'] ?? '');
    $billing_contact = trim($_POST['billing_contact'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $tax_number = trim($_POST['tax_number'] ?? '');
    $notes = trim($_POST['notes'] ?? '');

    if (!$client_name) $err = 'Client name is required.';
    if (!$err) {
        if ($hasIncomeClientColumns) {
            $allowedTypes = array_keys(co_client_type_options());
            if (!in_array($client_type, $allowedTypes, true)) {
                $client_type = 'customer';
            }
            $stmt = $conn->prepare("INSERT INTO co_clients (company_id, client_name, client_type, contact_person, billing_contact, email, phone, address, tax_number, notes) VALUES (?,?,?,?,?,?,?,?,?,?)");
            $stmt->execute([$cid, $client_name, $client_type, $contact_person ?: null, $billing_contact ?: null, $email ?: null, $phone ?: null, $address ?: null, $tax_number ?: null, $notes ?: null]);
        } else {
            $stmt = $conn->prepare("INSERT INTO co_clients (company_id, client_name, contact_person, email, phone, address, tax_number, notes) VALUES (?,?,?,?,?,?,?,?)");
            $stmt->execute([$cid, $client_name, $contact_person ?: null, $email ?: null, $phone ?: null, $address ?: null, $tax_number ?: null, $notes ?: null]);
        }
        header('Location: clients.php');
        exit;
    }
}

$pageTitle = 'Add Client';
require_once __DIR__ . '/includes/construction_layout_header.php';
?>

<div class="mb-4"><a href="clients.php" class="btn btn-outline-secondary btn-sm mb-2"><i class="bi bi-arrow-left"></i> Back</a><h1 class="h4 mb-0">Add Client</h1></div>
<?php if ($err): ?><div class="alert alert-danger"><?= h($err) ?></div><?php endif; ?>
<?php if (!$hasIncomeClientColumns): ?><div class="alert alert-warning">Run <code>migrations/construction_income_workflow.sql</code> to enable customer/tenant classifications.</div><?php endif; ?>

<form method="post" class="card card-round">
    <div class="card-body">
        <div class="row g-3">
            <div class="col-md-6"><label class="form-label">Client Name *</label><input type="text" name="client_name" class="form-control" required value="<?= h($_POST['client_name'] ?? '') ?>"></div>
            <?php if ($hasIncomeClientColumns): ?>
            <div class="col-md-6"><label class="form-label">Client Type</label><select name="client_type" class="form-select"><?php foreach (co_client_type_options() as $key => $label): ?><option value="<?= h($key) ?>" <?= ($_POST['client_type'] ?? 'customer') === $key ? 'selected' : '' ?>><?= h($label) ?></option><?php endforeach; ?></select></div>
            <?php endif; ?>
            <div class="col-md-6"><label class="form-label">Contact Person</label><input type="text" name="contact_person" class="form-control" value="<?= h($_POST['contact_person'] ?? '') ?>"></div>
            <?php if ($hasIncomeClientColumns): ?>
            <div class="col-md-6"><label class="form-label">Billing Contact</label><input type="text" name="billing_contact" class="form-control" value="<?= h($_POST['billing_contact'] ?? '') ?>"></div>
            <?php endif; ?>
            <div class="col-md-4"><label class="form-label">Phone</label><input type="text" name="phone" class="form-control" value="<?= h($_POST['phone'] ?? '') ?>"></div>
            <div class="col-md-4"><label class="form-label">Email</label><input type="email" name="email" class="form-control" value="<?= h($_POST['email'] ?? '') ?>"></div>
            <div class="col-md-4"><label class="form-label">Tax Number</label><input type="text" name="tax_number" class="form-control" value="<?= h($_POST['tax_number'] ?? '') ?>"></div>
            <div class="col-12"><label class="form-label">Address</label><textarea name="address" class="form-control" rows="2"><?= h($_POST['address'] ?? '') ?></textarea></div>
            <div class="col-12"><label class="form-label">Notes</label><textarea name="notes" class="form-control" rows="2"><?= h($_POST['notes'] ?? '') ?></textarea></div>
        </div>
        <div class="mt-3"><button type="submit" class="btn btn-primary">Save Client</button></div>
    </div>
</form>

<?php require_once __DIR__ . '/includes/construction_layout_footer.php'; ?>
