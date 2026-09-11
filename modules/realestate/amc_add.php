<?php
/**
 * Real Estate Module - Add/Edit AMC Contract
 */

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../includes/branding.php';
require_once __DIR__ . '/../../includes/company_helper.php';
require_once __DIR__ . '/../../includes/module_access.php';
require_once __DIR__ . '/../../includes/rbac_department.php';

require_login();
if (!has_department_access(MODULE_REALESTATE, DEPT_REALESTATE_CORE, $conn)) {
    require_module_access($conn, MODULE_REALESTATE);
}

$brand = getBrandSettings($conn);
$currentCompanyId = current_company_id($conn) ?: 1;
$userId = current_user_id();

$contractId = !empty($_GET['id']) ? (int)$_GET['id'] : null;
$isEdit = $contractId !== null;
$contract = null;
$success = '';
$error = '';

// Get dropdown data
$buildings = $conn->prepare("SELECT id, name FROM re_buildings WHERE company_id = ? AND is_active = 1 ORDER BY name");
$buildings->execute([$currentCompanyId]);
$buildings = $buildings->fetchAll(PDO::FETCH_ASSOC);

$categories = $conn->query("SELECT id, name, code FROM re_amc_categories WHERE is_active = 1 ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

$vendors = $conn->prepare("SELECT id, vendor_name FROM re_vendors WHERE company_id = ? AND status = 'active' ORDER BY vendor_name");
$vendors->execute([$currentCompanyId]);
$vendors = $vendors->fetchAll(PDO::FETCH_ASSOC);

// Load existing contract if editing
if ($isEdit) {
    $stmt = $conn->prepare("SELECT * FROM re_amc_contracts WHERE id = ? AND company_id = ?");
    $stmt->execute([$contractId, $currentCompanyId]);
    $contract = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$contract) {
        header('Location: amc.php');
        exit;
    }
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    
    $buildingId = (int)$_POST['building_id'];
    $categoryId = (int)$_POST['category_id'];
    $vendorId = (int)$_POST['vendor_id'];
    $contractNumber = trim($_POST['contract_number'] ?? '');
    $contractTitle = trim($_POST['contract_title'] ?? '');
    $startDate = $_POST['start_date'] ?? '';
    $endDate = $_POST['end_date'] ?? '';
    $amountInclVat = !empty($_POST['amount_incl_vat']) ? (float)$_POST['amount_incl_vat'] : 0;
    $vatPercentage = (isset($_POST['vat_percentage']) && $_POST['vat_percentage'] !== '') ? (float)$_POST['vat_percentage'] : 5;
    $otherCharges = !empty($_POST['other_charges']) ? (float)$_POST['other_charges'] : 0;
    $paymentTerms = trim($_POST['payment_terms'] ?? '');
    $paymentSchedule = $_POST['payment_schedule'] ?? 'annual';
    $visitFrequency = $_POST['visit_frequency'] ?? 'monthly';
    $status = $_POST['status'] ?? 'draft';
    $autoRenew = !empty($_POST['auto_renew']) ? 1 : 0;
    $notes = trim($_POST['notes'] ?? '');
    
    // Auto-generate contract number if not provided
    if (empty($contractNumber)) {
        if (!$buildingId || !$categoryId) {
            $error = "Contract number is required. Please provide a contract number or select Building and Category for auto-generation.";
        } else {
            $stmt = $conn->prepare("SELECT code FROM re_amc_categories WHERE id = ?");
            $stmt->execute([$categoryId]);
            $cat = $stmt->fetch(PDO::FETCH_ASSOC);
            $catCode = strtoupper($cat['code'] ?? 'AMC');
            
            $stmt = $conn->prepare("SELECT name FROM re_buildings WHERE id = ?");
            $stmt->execute([$buildingId]);
            $bld = $stmt->fetch(PDO::FETCH_ASSOC);
            $bldCode = strtoupper(substr(preg_replace('/[^A-Z0-9]/', '', $bld['name'] ?? ''), 0, 3));
            
            $stmt = $conn->prepare("SELECT COUNT(*) as cnt FROM re_amc_contracts WHERE building_id = ? AND category_id = ?");
            $stmt->execute([$buildingId, $categoryId]);
            $count = $stmt->fetch(PDO::FETCH_ASSOC);
            $seq = str_pad(($count['cnt'] + 1), 3, '0', STR_PAD_LEFT);
            
            $contractNumber = $bldCode . '-' . $catCode . '-' . $seq;
        }
    }
    
    // Validate contract number is set
    if (empty($contractNumber)) {
        $error = "Contract number is required. Please provide a contract number or select Building and Category for auto-generation.";
    }
    
    // Only proceed if no errors
    if (empty($error)) {
        // The amount is entered VAT-inclusive: VAT is that percentage of it and the
        // contract value is what remains. Other charges carry no VAT.
        // Editing without touching the amount or VAT % keeps the stored split, so
        // contracts saved under the old VAT-on-top method do not shift.
        $keepStoredSplit = $isEdit
            && abs($amountInclVat - ($contract['contract_value'] + $contract['vat_amount'])) < 0.005
            && abs($vatPercentage - $contract['vat_percentage']) < 0.005;
        if ($keepStoredSplit) {
            $contractValue = (float)$contract['contract_value'];
            $vatAmount = (float)$contract['vat_amount'];
        } else {
            $vatAmount = round($amountInclVat * $vatPercentage / 100, 2);
            $contractValue = $amountInclVat - $vatAmount;
        }
        $totalAmount = $contractValue + $vatAmount + $otherCharges;
        
        if ($isEdit) {
        $stmt = $conn->prepare("
            UPDATE re_amc_contracts SET
                building_id = ?, category_id = ?, vendor_id = ?, contract_number = ?, contract_title = ?,
                start_date = ?, end_date = ?, contract_value = ?, vat_percentage = ?, vat_amount = ?,
                other_charges = ?, total_amount = ?, payment_terms = ?, payment_schedule = ?, visit_frequency = ?,
                status = ?, auto_renew = ?, notes = ?
            WHERE id = ? AND company_id = ?
        ");
        $stmt->execute([
            $buildingId, $categoryId, $vendorId, $contractNumber, $contractTitle,
            $startDate, $endDate, $contractValue, $vatPercentage, $vatAmount,
            $otherCharges, $totalAmount, $paymentTerms, $paymentSchedule, $visitFrequency,
            $status, $autoRenew, $notes,
            $contractId, $currentCompanyId
        ]);
        $success = "AMC contract updated successfully!";
    } else {
        $stmt = $conn->prepare("
            INSERT INTO re_amc_contracts (
                company_id, building_id, category_id, vendor_id, contract_number, contract_title,
                start_date, end_date, contract_value, vat_percentage, vat_amount, other_charges, total_amount,
                payment_terms, payment_schedule, visit_frequency,
                status, auto_renew, notes, created_by
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $currentCompanyId, $buildingId, $categoryId, $vendorId, $contractNumber, $contractTitle,
            $startDate, $endDate, $contractValue, $vatPercentage, $vatAmount, $otherCharges, $totalAmount,
            $paymentTerms, $paymentSchedule, $visitFrequency,
            $status, $autoRenew, $notes, $userId
        ]);
            $contractId = $conn->lastInsertId();
            $success = "AMC contract created successfully!";
        }
        
        if ($success) {
            header("Location: amc_view.php?id={$contractId}");
            exit;
        }
    }
}

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

$pageTitle = $isEdit ? 'Edit AMC Contract' : 'Add AMC Contract';
require_once __DIR__ . '/includes/re_layout_header.php';
?>

<div class="container-fluid px-4 py-3">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="page-header-label"><?= $isEdit ? 'Edit AMC Contract' : 'Add AMC Contract' ?></h1>
        <a href="amc.php" class="btn btn-secondary">
            <i class="bi bi-arrow-left"></i> Back to AMC Contracts
        </a>
    </div>

    <?php if ($error): ?>
        <div class="alert alert-danger"><?= h($error) ?></div>
    <?php endif; ?>

    <form method="POST" id="amcForm">
        <?php csrf_field(); ?>
        
        <div class="card shadow-sm mb-4">
            <div class="card-header bg-primary text-white">
                <h5 class="mb-0">Contract Information</h5>
            </div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-4">
                        <label class="form-label">Building <span class="text-danger">*</span></label>
                        <select name="building_id" class="form-select" required>
                            <option value="">Select Building</option>
                            <?php foreach ($buildings as $b): ?>
                                <option value="<?= $b['id'] ?>" <?= ($contract['building_id'] ?? '') == $b['id'] ? 'selected' : '' ?>>
                                    <?= h($b['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">AMC Category <span class="text-danger">*</span></label>
                        <select name="category_id" class="form-select" required>
                            <option value="">Select Category</option>
                            <?php foreach ($categories as $c): ?>
                                <option value="<?= $c['id'] ?>" <?= ($contract['category_id'] ?? '') == $c['id'] ? 'selected' : '' ?>>
                                    <?= h($c['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Service Provider (Vendor) <span class="text-danger">*</span></label>
                        <select name="vendor_id" class="form-select" required>
                            <option value="">Select Vendor</option>
                            <?php foreach ($vendors as $v): ?>
                                <option value="<?= $v['id'] ?>" <?= ($contract['vendor_id'] ?? '') == $v['id'] ? 'selected' : '' ?>>
                                    <?= h($v['vendor_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Contract Number</label>
                        <input type="text" name="contract_number" class="form-control" 
                            value="<?= h($contract['contract_number'] ?? '') ?>" 
                            placeholder="Leave blank to auto-generate">
                        <small class="text-muted">Leave blank to auto-generate based on Building + Category</small>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Contract Title</label>
                        <input type="text" name="contract_title" class="form-control" 
                            value="<?= h($contract['contract_title'] ?? '') ?>">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Start Date <span class="text-danger">*</span></label>
                        <input type="date" name="start_date" class="form-control" 
                            value="<?= h($contract['start_date'] ?? '') ?>" required>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">End Date <span class="text-danger">*</span></label>
                        <input type="date" name="end_date" class="form-control" 
                            value="<?= h($contract['end_date'] ?? '') ?>" required>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Status <span class="text-danger">*</span></label>
                        <select name="status" class="form-select" required>
                            <option value="draft" <?= ($contract['status'] ?? 'draft') == 'draft' ? 'selected' : '' ?>>Draft</option>
                            <option value="active" <?= ($contract['status'] ?? '') == 'active' ? 'selected' : '' ?>>Active</option>
                            <option value="expired" <?= ($contract['status'] ?? '') == 'expired' ? 'selected' : '' ?>>Expired</option>
                            <option value="terminated" <?= ($contract['status'] ?? '') == 'terminated' ? 'selected' : '' ?>>Terminated</option>
                        </select>
                    </div>
                </div>
            </div>
        </div>

        <div class="card shadow-sm mb-4">
            <div class="card-header bg-success text-white">
                <h5 class="mb-0">Financial Details</h5>
            </div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-3">
                        <label class="form-label">Amount (Incl. VAT) <span class="text-danger">*</span></label>
                        <input type="number" step="0.01" name="amount_incl_vat" class="form-control"
                            value="<?= h($contract ? number_format($contract['contract_value'] + $contract['vat_amount'], 2, '.', '') : '0') ?>" required id="amount_incl_vat">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">VAT Percentage (%)</label>
                        <input type="number" step="0.01" name="vat_percentage" class="form-control"
                            value="<?= h($contract['vat_percentage'] ?? '5') ?>" id="vat_percentage">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">VAT Amount</label>
                        <input type="text" class="form-control" id="vat_amount" readonly>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Contract Value (Excl. VAT)</label>
                        <input type="text" class="form-control" id="contract_value" readonly>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Other Charges (No VAT)</label>
                        <input type="number" step="0.01" min="0" name="other_charges" class="form-control"
                            value="<?= h($contract['other_charges'] ?? '0') ?>" id="other_charges">
                        <small class="text-muted">e.g. DCD charges</small>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Total Amount</label>
                        <input type="text" class="form-control" id="total_amount" readonly>
                        <small class="text-muted">Amount (Incl. VAT) + Other Charges</small>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Payment Schedule</label>
                        <select name="payment_schedule" class="form-select">
                            <option value="monthly" <?= ($contract['payment_schedule'] ?? 'annual') == 'monthly' ? 'selected' : '' ?>>Monthly</option>
                            <option value="quarterly" <?= ($contract['payment_schedule'] ?? '') == 'quarterly' ? 'selected' : '' ?>>Quarterly</option>
                            <option value="semi_annual" <?= ($contract['payment_schedule'] ?? '') == 'semi_annual' ? 'selected' : '' ?>>Semi-Annual</option>
                            <option value="annual" <?= ($contract['payment_schedule'] ?? 'annual') == 'annual' ? 'selected' : '' ?>>Annual</option>
                            <option value="one_time" <?= ($contract['payment_schedule'] ?? '') == 'one_time' ? 'selected' : '' ?>>One Time</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Payment Terms</label>
                        <input type="text" name="payment_terms" class="form-control" 
                            value="<?= h($contract['payment_terms'] ?? '') ?>" placeholder="e.g., Net 30, COD">
                    </div>
                </div>
            </div>
        </div>

        <div class="card shadow-sm mb-4">
            <div class="card-header bg-info text-white">
                <h5 class="mb-0">Service Details</h5>
            </div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-4">
                        <label class="form-label">Visit Frequency <span class="text-danger">*</span></label>
                        <select name="visit_frequency" class="form-select" required>
                            <option value="daily" <?= ($contract['visit_frequency'] ?? 'monthly') == 'daily' ? 'selected' : '' ?>>Daily</option>
                            <option value="weekly" <?= ($contract['visit_frequency'] ?? '') == 'weekly' ? 'selected' : '' ?>>Weekly</option>
                            <option value="monthly" <?= ($contract['visit_frequency'] ?? 'monthly') == 'monthly' ? 'selected' : '' ?>>Monthly</option>
                            <option value="quarterly" <?= ($contract['visit_frequency'] ?? '') == 'quarterly' ? 'selected' : '' ?>>Quarterly</option>
                            <option value="bi_annual" <?= ($contract['visit_frequency'] ?? '') == 'bi_annual' ? 'selected' : '' ?>>Bi-Annual</option>
                            <option value="annual" <?= ($contract['visit_frequency'] ?? '') == 'annual' ? 'selected' : '' ?>>Annual</option>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="auto_renew" id="auto_renew" 
                                <?= ($contract['auto_renew'] ?? 0) ? 'checked' : '' ?>>
                            <label class="form-check-label" for="auto_renew">
                                Auto-renew contract on expiry
                            </label>
                        </div>
                    </div>
                    <div class="col-md-12">
                        <label class="form-label">Notes</label>
                        <textarea name="notes" class="form-control" rows="3"><?= h($contract['notes'] ?? '') ?></textarea>
                    </div>
                </div>
            </div>
        </div>

        <div class="d-flex justify-content-end gap-2">
            <a href="amc.php" class="btn btn-secondary">Cancel</a>
            <button type="submit" class="btn btn-primary">
                <i class="bi bi-save"></i> <?= $isEdit ? 'Update Contract' : 'Create Contract' ?>
            </button>
        </div>
    </form>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const amountInclVat = document.getElementById('amount_incl_vat');
    const vatPercentage = document.getElementById('vat_percentage');
    const vatAmount = document.getElementById('vat_amount');
    const contractValue = document.getElementById('contract_value');
    const totalAmount = document.getElementById('total_amount');
    const otherCharges = document.getElementById('other_charges');
    // Stored split for an existing contract; kept while amount and VAT % are unchanged (mirrors the server)
    const stored = <?= json_encode($contract ? [
        'amount' => round($contract['contract_value'] + $contract['vat_amount'], 2),
        'pct'    => (float)$contract['vat_percentage'],
        'vat'    => (float)$contract['vat_amount'],
        'value'  => (float)$contract['contract_value'],
    ] : null) ?>;

    function calculate() {
        const amount = parseFloat(amountInclVat.value) || 0;
        const pct = parseFloat(vatPercentage.value) || 0;
        const other = parseFloat(otherCharges.value) || 0;
        let vatAmt, value;
        if (stored && Math.abs(amount - stored.amount) < 0.005 && Math.abs(pct - stored.pct) < 0.005) {
            vatAmt = stored.vat;
            value = stored.value;
        } else {
            vatAmt = Math.round(amount * pct) / 100;
            value = amount - vatAmt;
        }

        vatAmount.value = vatAmt.toFixed(2);
        contractValue.value = value.toFixed(2);
        totalAmount.value = (amount + other).toFixed(2);
    }

    amountInclVat.addEventListener('input', calculate);
    vatPercentage.addEventListener('input', calculate);
    otherCharges.addEventListener('input', calculate);
    calculate();
});
</script>

<?php require_once __DIR__ . '/includes/re_layout_footer.php'; ?>
