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
$camps = $conn->prepare("SELECT id, camp_name FROM co_labor_camps WHERE company_id = ? AND status = 'active' ORDER BY camp_name");
$camps->execute([$cid]);
$camps = $camps->fetchAll(PDO::FETCH_ASSOC);
$clients = $conn->prepare("SELECT id, client_name FROM co_clients WHERE company_id = ? ORDER BY client_name");
$clients->execute([$cid]);
$clients = $clients->fetchAll(PDO::FETCH_ASSOC);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $campId = (int)($_POST['camp_id'] ?? 0);
    $clientId = (int)($_POST['client_id'] ?? 0);
    $contractNumber = trim($_POST['contract_number'] ?? '') ?: co_next_document_number($conn, $cid, 'CAMP', 'co_camp_management_contracts', 'contract_number');
    $startDate = $_POST['start_date'] ?? date('Y-m-d');
    $endDate = $_POST['end_date'] ?? date('Y-m-d', strtotime('+1 year -1 day'));
    $monthlyAmount = (float)($_POST['monthly_amount'] ?? 0);
    $commissionRate = (float)($_POST['commission_rate'] ?? 0);
    $commissionVatRate = (float)($_POST['commission_vat_rate'] ?? 0);
    $remittanceFrequency = $_POST['remittance_frequency'] ?? 'monthly';
    $vatRate = (float)($_POST['vat_rate'] ?? 5);
    $paymentTerms = trim($_POST['payment_terms'] ?? '');
    $notes = trim($_POST['notes'] ?? '');
    if (!$campId || !$clientId) $err = 'Camp and management agent are required.';
    if (!in_array($remittanceFrequency, ['monthly','quarterly','semi_annual','annual','custom'], true)) $remittanceFrequency = 'monthly';
    if (!$err) {
        $stmt = $conn->prepare("INSERT INTO co_camp_management_contracts (company_id, camp_id, client_id, contract_number, start_date, end_date, monthly_amount, commission_rate, commission_vat_rate, remittance_frequency, vat_rate, payment_terms, status, notes, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'active', ?, ?)");
        $stmt->execute([$cid, $campId, $clientId, $contractNumber, $startDate, $endDate, $monthlyAmount, $commissionRate, $commissionVatRate, $remittanceFrequency, $vatRate, $paymentTerms ?: null, $notes ?: null, current_user_id() ?: null]);
        header('Location: camp_management_contract_view.php?id=' . (int)$conn->lastInsertId());
        exit;
    }
}
$pageTitle = 'New Camp Management Contract';
require_once __DIR__ . '/includes/construction_layout_header.php';
?>
<div class="mb-4"><a href="camp_management_contracts.php" class="btn btn-outline-secondary btn-sm mb-2"><i class="bi bi-arrow-left"></i> Back</a><h1 class="h4 mb-0">New Camp Agent Agreement</h1><p class="text-muted mb-0">Use this when an agent manages the camp, collects rent from tenants, deducts commission, then remits the net amount to Madar Alwadi.</p></div>
<?php if ($err): ?><div class="alert alert-danger"><?= h($err) ?></div><?php endif; ?>
<form method="post" class="card card-round"><div class="card-body"><div class="row g-3">
    <div class="col-md-4"><label class="form-label">Contract Number</label><input type="text" name="contract_number" class="form-control" placeholder="Auto-generated"></div>
    <div class="col-md-4"><label class="form-label">Camp *</label><select name="camp_id" class="form-select" required><?php foreach ($camps as $camp): ?><option value="<?= (int)$camp['id'] ?>"><?= h($camp['camp_name']) ?></option><?php endforeach; ?></select></div>
    <div class="col-md-4"><label class="form-label">Management Agent *</label><select name="client_id" class="form-select" required><?php foreach ($clients as $client): ?><option value="<?= (int)$client['id'] ?>"><?= h($client['client_name']) ?></option><?php endforeach; ?></select></div>
    <div class="col-md-3"><label class="form-label">Start Date</label><input type="date" name="start_date" class="form-control" value="<?= h(date('Y-m-d')) ?>"></div>
    <div class="col-md-3"><label class="form-label">End Date</label><input type="date" name="end_date" class="form-control" value="<?= h(date('Y-m-d', strtotime('+1 year -1 day'))) ?>"></div>
    <div class="col-md-3"><label class="form-label">Expected Monthly Gross Rent</label><input type="number" step="0.01" name="monthly_amount" class="form-control" value="0"><div class="form-text">Optional estimate only. Actual settlement uses entered gross rent.</div></div>
    <div class="col-md-3"><label class="form-label">Rent Output VAT %</label><input type="number" step="0.01" name="vat_rate" class="form-control" value="5"></div>
    <div class="col-md-3"><label class="form-label">Agent Commission %</label><input type="number" step="0.01" name="commission_rate" class="form-control" value="0"></div>
    <div class="col-md-3"><label class="form-label">Commission VAT %</label><input type="number" step="0.01" name="commission_vat_rate" class="form-control" value="0"></div>
    <div class="col-md-3"><label class="form-label">Remittance Frequency</label><select name="remittance_frequency" class="form-select"><?php foreach (['monthly','quarterly','semi_annual','annual','custom'] as $f): ?><option value="<?= h($f) ?>"><?= h(ucwords(str_replace('_', ' ', $f))) ?></option><?php endforeach; ?></select></div>
    <div class="col-md-6"><label class="form-label">Payment Terms</label><input type="text" name="payment_terms" class="form-control"></div>
    <div class="col-md-3"><label class="form-label">Notes</label><input type="text" name="notes" class="form-control"></div>
</div><div class="mt-3"><button class="btn btn-primary">Save Contract</button></div></div></form>
<?php require_once __DIR__ . '/includes/construction_layout_footer.php'; ?>
