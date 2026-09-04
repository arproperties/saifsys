<?php
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
$clients = $conn->prepare("SELECT id, client_name FROM co_clients WHERE company_id = ? ORDER BY client_name");
$clients->execute([$cid]);
$clients = $clients->fetchAll(PDO::FETCH_ASSOC);
$projects = $conn->prepare("SELECT id, project_code, project_name FROM co_projects WHERE company_id = ? ORDER BY project_name");
$projects->execute([$cid]);
$projects = $projects->fetchAll(PDO::FETCH_ASSOC);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $clientId = (int)($_POST['client_id'] ?? 0);
    $projectId = (int)($_POST['project_id'] ?? 0);
    $contractNumber = trim($_POST['contract_number'] ?? '') ?: co_next_document_number($conn, $cid, 'MAINT', 'co_maintenance_contracts', 'contract_number');
    $serviceName = trim($_POST['service_name'] ?? '');
    $building = trim($_POST['building_or_project_name'] ?? '');
    $serviceType = $_POST['service_type'] ?? 'one_time';
    $startDate = $_POST['start_date'] ?? date('Y-m-d');
    $endDate = $_POST['end_date'] ?? null;
    $amount = (float)($_POST['amount'] ?? 0);
    $frequency = $_POST['billing_frequency'] ?? 'one_time';
    $vatRate = (float)($_POST['vat_rate'] ?? 5);
    $terms = trim($_POST['payment_terms'] ?? '');
    $notes = trim($_POST['notes'] ?? '');
    if (!$clientId || $serviceName === '' || $amount <= 0) $err = 'Customer, service name, and amount are required.';
    if (!$err) {
        $stmt = $conn->prepare("
            INSERT INTO co_maintenance_contracts
                (company_id, client_id, project_id, contract_number, service_name, building_or_project_name, service_type, start_date, end_date, amount, billing_frequency, vat_rate, payment_terms, status, notes, created_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'active', ?, ?)
        ");
        $stmt->execute([$cid, $clientId, $projectId ?: null, $contractNumber, $serviceName, $building ?: null, $serviceType, $startDate, $endDate ?: null, $amount, $frequency, $vatRate, $terms ?: null, $notes ?: null, current_user_id() ?: null]);
        header('Location: maintenance_contract_view.php?id=' . (int)$conn->lastInsertId());
        exit;
    }
}
$pageTitle = 'New Maintenance Contract';
require_once __DIR__ . '/includes/construction_layout_header.php';
?>
<div class="mb-4"><a href="maintenance_contracts.php" class="btn btn-outline-secondary btn-sm mb-2"><i class="bi bi-arrow-left"></i> Back</a><h1 class="h4 mb-0">New Maintenance Service Contract</h1></div>
<?php if ($err): ?><div class="alert alert-danger"><?= h($err) ?></div><?php endif; ?>
<form method="post" class="card card-round"><div class="card-body"><div class="row g-3">
    <div class="col-md-4"><label class="form-label">Contract Number</label><input type="text" name="contract_number" class="form-control" placeholder="Auto-generated"></div>
    <div class="col-md-4"><label class="form-label">Customer *</label><select name="client_id" class="form-select" required><?php foreach ($clients as $client): ?><option value="<?= (int)$client['id'] ?>"><?= h($client['client_name']) ?></option><?php endforeach; ?></select></div>
    <div class="col-md-4"><label class="form-label">Linked Project</label><select name="project_id" class="form-select"><option value="0">— none —</option><?php foreach ($projects as $project): ?><option value="<?= (int)$project['id'] ?>"><?= h($project['project_code'] . ' - ' . $project['project_name']) ?></option><?php endforeach; ?></select></div>
    <div class="col-md-4"><label class="form-label">Service Name *</label><input type="text" name="service_name" class="form-control" required></div>
    <div class="col-md-4"><label class="form-label">Building / Project Name</label><input type="text" name="building_or_project_name" class="form-control"></div>
    <div class="col-md-4"><label class="form-label">Service Type</label><select name="service_type" class="form-select"><option value="one_time">One Time</option><option value="recurring">Recurring</option></select></div>
    <div class="col-md-3"><label class="form-label">Start Date</label><input type="date" name="start_date" class="form-control" value="<?= h(date('Y-m-d')) ?>"></div>
    <div class="col-md-3"><label class="form-label">End Date</label><input type="date" name="end_date" class="form-control"></div>
    <div class="col-md-3"><label class="form-label">Amount</label><input type="number" step="0.01" name="amount" class="form-control" required></div>
    <div class="col-md-3"><label class="form-label">Billing Frequency</label><select name="billing_frequency" class="form-select"><?php foreach (['one_time','monthly','quarterly','semi_annual','annual'] as $f): ?><option value="<?= h($f) ?>"><?= h(ucwords(str_replace('_', ' ', $f))) ?></option><?php endforeach; ?></select></div>
    <div class="col-md-3"><label class="form-label">VAT %</label><input type="number" step="0.01" name="vat_rate" class="form-control" value="5"></div>
    <div class="col-md-9"><label class="form-label">Payment Terms</label><input type="text" name="payment_terms" class="form-control"></div>
    <div class="col-12"><label class="form-label">Notes</label><textarea name="notes" class="form-control" rows="2"></textarea></div>
</div><div class="mt-3"><button class="btn btn-primary">Save Contract</button></div></div></form>
<?php require_once __DIR__ . '/includes/construction_layout_footer.php'; ?>
