<?php
/**
 * Real Estate Module - AMC Payments Management
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

$contractId = !empty($_GET['contract_id']) ? (int)$_GET['contract_id'] : 0;
$paymentId = !empty($_GET['id']) ? (int)$_GET['id'] : 0;
$success = '';
$error = '';

// Get contract info
$contract = null;
if ($contractId) {
    $stmt = $conn->prepare("
        SELECT ac.*, b.name as building_name, cat.name as category_name, v.vendor_name
        FROM re_amc_contracts ac
        LEFT JOIN re_buildings b ON b.id = ac.building_id
        LEFT JOIN re_amc_categories cat ON cat.id = ac.category_id
        LEFT JOIN re_vendors v ON v.id = ac.vendor_id
        WHERE ac.id = ? AND ac.company_id = ?
    ");
    $stmt->execute([$contractId, $currentCompanyId]);
    $contract = $stmt->fetch(PDO::FETCH_ASSOC);
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    
    $paymentType = $_POST['payment_type'] ?? 'installment';
    $paymentNumber = trim($_POST['payment_number'] ?? '');
    $paymentDate = $_POST['payment_date'] ?? '';
    $dueDate = $_POST['due_date'] ?? null;
    $amount = !empty($_POST['amount']) ? (float)$_POST['amount'] : 0;
    $vatAmount = !empty($_POST['vat_amount']) ? (float)$_POST['vat_amount'] : 0;
    $totalAmount = $amount + $vatAmount;
    $paymentStatus = $_POST['payment_status'] ?? 'pending';
    $paymentMethod = trim($_POST['payment_method'] ?? '');
    $referenceNumber = trim($_POST['reference_number'] ?? '');
    $paidDate = !empty($_POST['paid_date']) ? $_POST['paid_date'] : null;
    $paidAmount = !empty($_POST['paid_amount']) ? (float)$_POST['paid_amount'] : 0;
    $notes = trim($_POST['notes'] ?? '');
    
    if ($paymentId) {
        // Update
        $stmt = $conn->prepare("
            UPDATE re_amc_payments SET
                payment_type = ?, payment_number = ?, payment_date = ?, due_date = ?,
                amount = ?, vat_amount = ?, total_amount = ?, payment_status = ?,
                payment_method = ?, reference_number = ?, paid_date = ?, paid_amount = ?, notes = ?
            WHERE id = ? AND contract_id = ? AND company_id = ?
        ");
        $stmt->execute([$paymentType, $paymentNumber, $paymentDate, $dueDate, 
                       $amount, $vatAmount, $totalAmount, $paymentStatus,
                       $paymentMethod, $referenceNumber, $paidDate, $paidAmount, $notes,
                       $paymentId, $contractId, $currentCompanyId]);
        $success = "Payment updated successfully!";
    } else {
        // Insert
        if (!$paymentNumber && $contractId) {
            $stmt = $conn->prepare("SELECT COALESCE(MAX(CAST(SUBSTRING(payment_number, -3) AS UNSIGNED)), 0) + 1 as next_num 
                FROM re_amc_payments WHERE contract_id = ?");
            $stmt->execute([$contractId]);
            $nextNum = str_pad($stmt->fetchColumn(), 3, '0', STR_PAD_LEFT);
            $paymentNumber = ($contract['contract_number'] ?? 'AMC') . '-PAY-' . $nextNum;
        }
        
        $stmt = $conn->prepare("
            INSERT INTO re_amc_payments (
                contract_id, company_id, payment_type, payment_number, payment_date, due_date,
                amount, vat_amount, total_amount, payment_status, payment_method,
                reference_number, paid_date, paid_amount, notes
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([$contractId, $currentCompanyId, $paymentType, $paymentNumber, $paymentDate, $dueDate,
                       $amount, $vatAmount, $totalAmount, $paymentStatus, $paymentMethod,
                       $referenceNumber, $paidDate, $paidAmount, $notes]);
        $success = "Payment logged successfully!";
    }
}

// Get payments
$where = [];
$params = [];
if ($contractId) {
    $where[] = "p.contract_id = ?";
    $params[] = $contractId;
}
$whereSql = $where ? "WHERE " . implode(" AND ", $where) : "";

$payments = [];
$stmt = $conn->prepare("
    SELECT p.*, ac.contract_number, b.name as building_name, cat.name as category_name
    FROM re_amc_payments p
    LEFT JOIN re_amc_contracts ac ON ac.id = p.contract_id
    LEFT JOIN re_buildings b ON b.id = ac.building_id
    LEFT JOIN re_amc_categories cat ON cat.id = ac.category_id
    {$whereSql}
    ORDER BY p.payment_date DESC
");
$stmt->execute($params);
$payments = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get payment for editing
$payment = null;
if ($paymentId) {
    $stmt = $conn->prepare("SELECT * FROM re_amc_payments WHERE id = ?");
    $stmt->execute([$paymentId]);
    $payment = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($payment && !$contractId) {
        $contractId = $payment['contract_id'];
        // Reload contract
        $stmt = $conn->prepare("
            SELECT ac.*, b.name as building_name, cat.name as category_name, v.vendor_name
            FROM re_amc_contracts ac
            LEFT JOIN re_buildings b ON b.id = ac.building_id
            LEFT JOIN re_amc_categories cat ON cat.id = ac.category_id
            LEFT JOIN re_vendors v ON v.id = ac.vendor_id
            WHERE ac.id = ? AND ac.company_id = ?
        ");
        $stmt->execute([$contractId, $currentCompanyId]);
        $contract = $stmt->fetch(PDO::FETCH_ASSOC);
    }
}

// Calculate totals
$totalAmount = array_sum(array_column($payments, 'total_amount'));
$paidAmount = array_sum(array_filter(array_column($payments, 'paid_amount'), fn($v) => $v > 0));
$outstanding = $totalAmount - $paidAmount;

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

$pageTitle = $contract ? 'AMC Payments: ' . h($contract['contract_number']) : 'AMC Payments';
require_once __DIR__ . '/includes/re_layout_header.php';
?>

<div class="container-fluid px-4 py-3">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h1 class="page-header-label">AMC Payments</h1>
            <?php if ($contract): ?>
                <p class="text-muted mb-0">
                    <strong><?= h($contract['contract_number']) ?></strong> - 
                    <?= h($contract['building_name']) ?> - Total: <?= number_format($contract['total_amount'], 2) ?> AED
                </p>
            <?php endif; ?>
        </div>
        <div class="btn-group">
            <?php if ($contractId): ?>
                <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addPaymentModal">
                    <i class="bi bi-plus-circle"></i> Log Payment
                </button>
            <?php endif; ?>
            <?php if ($contractId): ?>
                <a href="amc_view.php?id=<?= $contractId ?>" class="btn btn-secondary">
                    <i class="bi bi-arrow-left"></i> Back to Contract
                </a>
            <?php else: ?>
                <a href="amc.php" class="btn btn-secondary">
                    <i class="bi bi-arrow-left"></i> Back to AMC
                </a>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($success): ?>
        <div class="alert alert-success alert-dismissible fade show">
            <?= h($success) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- Summary Cards -->
    <div class="row g-3 mb-4">
        <div class="col-md-4">
            <div class="card shadow-sm">
                <div class="card-body">
                    <div class="text-muted small">Total Amount</div>
                    <div class="h4 mb-0"><?= number_format($totalAmount, 2) ?> AED</div>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card shadow-sm">
                <div class="card-body">
                    <div class="text-muted small">Paid Amount</div>
                    <div class="h4 mb-0 text-success"><?= number_format($paidAmount, 2) ?> AED</div>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card shadow-sm">
                <div class="card-body">
                    <div class="text-muted small">Outstanding</div>
                    <div class="h4 mb-0 text-<?= $outstanding > 0 ? 'danger' : 'success' ?>">
                        <?= number_format($outstanding, 2) ?> AED
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Payments Table -->
    <div class="card shadow-sm">
        <div class="card-body">
            <?php if (empty($payments)): ?>
                <p class="text-center text-muted py-4">No payments found.</p>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead class="table-light">
                            <tr>
                                <th>Payment #</th>
                                <th>Contract</th>
                                <th>Type</th>
                                <th>Date</th>
                                <th>Amount</th>
                                <th>Status</th>
                                <th>Method</th>
                                <th>Reference</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($payments as $p): ?>
                                <tr>
                                    <td><strong><?= h($p['payment_number'] ?: 'N/A') ?></strong></td>
                                    <td>
                                        <?= h($p['contract_number']) ?><br>
                                        <small class="text-muted"><?= h($p['building_name']) ?></small>
                                    </td>
                                    <td><span class="badge bg-info"><?= ucfirst(str_replace('_', ' ', $p['payment_type'])) ?></span></td>
                                    <td><?= h($p['payment_date']) ?></td>
                                    <td><strong><?= number_format($p['total_amount'], 2) ?> AED</strong></td>
                                    <td>
                                        <span class="badge bg-<?= $p['payment_status'] == 'paid' ? 'success' : ($p['payment_status'] == 'overdue' ? 'danger' : 'warning') ?>">
                                            <?= ucfirst($p['payment_status']) ?>
                                        </span>
                                    </td>
                                    <td><?= h($p['payment_method'] ?: 'N/A') ?></td>
                                    <td><?= h($p['reference_number'] ?: '-') ?></td>
                                    <td>
                                        <button class="btn btn-sm btn-outline-primary" onclick="editPayment(<?= $p['id'] ?>)">
                                            <i class="bi bi-pencil"></i>
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Add Payment Modal -->
<div class="modal fade" id="addPaymentModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <?php csrf_field(); ?>
                <input type="hidden" name="contract_id" value="<?= $contractId ?>">
                <div class="modal-header">
                    <h5 class="modal-title">Log Payment</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Payment Type <span class="text-danger">*</span></label>
                            <select name="payment_type" class="form-select" required>
                                <option value="advance">Advance</option>
                                <option value="installment" selected>Installment</option>
                                <option value="final">Final</option>
                                <option value="penalty">Penalty</option>
                                <option value="refund">Refund</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Payment Number</label>
                            <input type="text" name="payment_number" class="form-control">
                            <small class="text-muted">Auto-generated if blank</small>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Payment Date <span class="text-danger">*</span></label>
                            <input type="date" name="payment_date" class="form-control" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Due Date</label>
                            <input type="date" name="due_date" class="form-control">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Amount (Excl. VAT) <span class="text-danger">*</span></label>
                            <input type="number" step="0.01" name="amount" class="form-control" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">VAT Amount</label>
                            <input type="number" step="0.01" name="vat_amount" class="form-control">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Total Amount</label>
                            <input type="text" class="form-control" id="total_amount" readonly>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Payment Status <span class="text-danger">*</span></label>
                            <select name="payment_status" class="form-select" required>
                                <option value="pending" selected>Pending</option>
                                <option value="paid">Paid</option>
                                <option value="partial">Partial</option>
                                <option value="overdue">Overdue</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Payment Method</label>
                            <input type="text" name="payment_method" class="form-control" placeholder="Cash, Cheque, Bank Transfer">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Reference Number</label>
                            <input type="text" name="reference_number" class="form-control" placeholder="Cheque #, Transaction ID">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Paid Date</label>
                            <input type="date" name="paid_date" class="form-control">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Paid Amount</label>
                            <input type="number" step="0.01" name="paid_amount" class="form-control">
                        </div>
                        <div class="col-12">
                            <label class="form-label">Notes</label>
                            <textarea name="notes" class="form-control" rows="2"></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Log Payment</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const amount = document.querySelector('[name="amount"]');
    const vatAmount = document.querySelector('[name="vat_amount"]');
    const totalAmount = document.getElementById('total_amount');
    
    function calculate() {
        const amt = parseFloat(amount.value) || 0;
        const vat = parseFloat(vatAmount.value) || 0;
        totalAmount.value = (amt + vat).toFixed(2);
    }
    
    amount.addEventListener('input', calculate);
    vatAmount.addEventListener('input', calculate);
    calculate();
});

function editPayment(id) {
    window.location.href = 'amc_payments.php?id=' + id + '&contract_id=<?= $contractId ?>';
}
</script>

<?php require_once __DIR__ . '/includes/re_layout_footer.php'; ?>
