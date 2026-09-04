<?php
/**
 * Real Estate Module - Add Post-Dated Cheque
 */

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../includes/branding.php';
require_once __DIR__ . '/../../includes/company_helper.php';
require_once __DIR__ . '/../../includes/module_access.php';
require_once __DIR__ . '/../../includes/csrf.php';

require_login();
require_module_access($conn, MODULE_REALESTATE);

$brand = getBrandSettings($conn);
$currentCompanyId = current_company_id($conn) ?: 1;
$currentUserId = $_SESSION['user_id'] ?? null;

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    
    $leaseId = (int)$_POST['lease_id'];
    $chequeNumber = $_POST['cheque_number'] ?? '';
    $bankName = $_POST['bank_name'] ?? '';
    $accountHolderName = $_POST['account_holder_name'] ?? '';
    $chequeAmount = $_POST['cheque_amount'] ?? 0;
    $chequeDate = $_POST['cheque_date'] ?? '';
    $receivedDate = $_POST['received_date'] ?? date('Y-m-d');
    $paymentMethod = 'cheque';
    $billingItemId = !empty($_POST['billing_item_id']) ? (int)$_POST['billing_item_id'] : null;
    $installmentId = !empty($_POST['installment_id']) ? (int)$_POST['installment_id'] : null;
    $notes = $_POST['notes'] ?? '';
    
    if (!$leaseId || !$chequeNumber || !$chequeAmount || !$chequeDate) {
        $_SESSION['error'] = 'Please fill in all required fields.';
        header('Location: billing_cheque_add.php');
        exit;
    }
    
    try {
        $stmt = $conn->prepare("
            INSERT INTO re_post_dated_cheques
            (company_id, lease_id, cheque_number, bank_name, account_holder_name, cheque_amount, 
             cheque_date, received_date, status, payment_method, billing_item_id, installment_id, notes, created_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'collected', ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $currentCompanyId, $leaseId, $chequeNumber, $bankName, $accountHolderName,
            $chequeAmount, $chequeDate, $receivedDate, $paymentMethod, $billingItemId, $installmentId, $notes, $currentUserId
        ]);
        
        $_SESSION['success'] = 'Post-dated cheque added successfully.';
        header('Location: billing_cheques.php');
        exit;
        
    } catch (Exception $e) {
        $_SESSION['error'] = 'Error adding cheque: ' . $e->getMessage();
        header('Location: billing_cheque_add.php');
        exit;
    }
}

// Get active leases
$leases = $conn->prepare("
    SELECT 
        l.id,
        l.lease_number,
        u.unit_number,
        b.name as building_name,
        t.first_name,
        t.last_name
    FROM re_leases l
    JOIN re_units u ON u.id = l.unit_id
    JOIN re_buildings b ON b.id = u.building_id
    JOIN re_tenants t ON t.id = l.tenant_id
    WHERE l.company_id = ? AND l.status = 'active'
    ORDER BY b.name, u.unit_number
");
$leases->execute([$currentCompanyId]);
$leases = $leases->fetchAll(PDO::FETCH_ASSOC);

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

// Set page title and include layout
$pageTitle = 'Add Post-Dated Cheque';
require_once __DIR__ . '/includes/re_layout_header.php';
?>
        <div class="row">
            <div class="col-md-8 mx-auto">
                <div class="card">
                    <div class="card-header">
                        <h4 class="mb-0"><i class="bi bi-bank"></i> Add Post-Dated Cheque</h4>
                    </div>
                    <div class="card-body">
                        <?php if (isset($_SESSION['error'])): ?>
                            <div class="alert alert-danger"><?= h($_SESSION['error']) ?></div>
                            <?php unset($_SESSION['error']); ?>
                        <?php endif; ?>
                        
                        <form method="POST">
                            <?= csrf_field() ?>
                            
                            <div class="mb-3">
                                <label class="form-label">Select Lease *</label>
                                <select name="lease_id" id="lease_id" class="form-select" required>
                                    <option value="">-- Select Lease --</option>
                                    <?php foreach ($leases as $lease): ?>
                                        <option value="<?= $lease['id'] ?>">
                                            <?= h($lease['lease_number']) ?> - 
                                            <?= h($lease['building_name']) ?> - 
                                            <?= h($lease['unit_number']) ?> - 
                                            <?= h($lease['first_name'] . ' ' . $lease['last_name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Cheque Number *</label>
                                    <input type="text" name="cheque_number" class="form-control" required>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Cheque Amount (AED) *</label>
                                    <input type="number" step="0.01" name="cheque_amount" class="form-control" required>
                                </div>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Bank Name</label>
                                    <input type="text" name="bank_name" class="form-control">
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Account Holder Name</label>
                                    <input type="text" name="account_holder_name" class="form-control">
                                </div>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Cheque Date *</label>
                                    <input type="date" name="cheque_date" class="form-control" required>
                                    <small class="form-text text-muted">Date written on the cheque</small>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Received Date</label>
                                    <input type="date" name="received_date" class="form-control" value="<?= date('Y-m-d') ?>">
                                    <small class="form-text text-muted">Date cheque was received</small>
                                </div>
                            </div>
                            
                            <div class="mb-3">
                                <label class="form-label">Link to Billing Item (Optional)</label>
                                <select name="billing_item_id" id="billing_item_id" class="form-select">
                                    <option value="">-- Select Billing Item (Optional) --</option>
                                    <!-- Will be loaded via AJAX when lease is selected -->
                                </select>
                            </div>
                            
                            <div class="mb-3">
                                <label class="form-label">Link to Installment (Optional)</label>
                                <select name="installment_id" id="installment_id" class="form-select">
                                    <option value="">-- Select Installment (Optional) --</option>
                                    <!-- Will be loaded via AJAX when lease is selected -->
                                </select>
                            </div>
                            
                            <div class="mb-3">
                                <label class="form-label">Notes</label>
                                <textarea name="notes" class="form-control" rows="3"></textarea>
                            </div>
                            
                            <div class="d-flex justify-content-between">
                                <a href="billing_cheques.php" class="btn btn-secondary">
                                    <i class="bi bi-arrow-left"></i> Cancel
                                </a>
                                <button type="submit" class="btn btn-primary">
                                    <i class="bi bi-check-circle"></i> Add Cheque
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        document.getElementById('lease_id').addEventListener('change', function() {
            const leaseId = this.value;
            if (!leaseId) return;
            
            // Load billing items
            fetch(`ajax_get_billing_items.php?lease_id=${leaseId}`)
                .then(r => r.json())
                .then(data => {
                    const select = document.getElementById('billing_item_id');
                    select.innerHTML = '<option value="">-- Select Billing Item (Optional) --</option>';
                    data.forEach(item => {
                        const option = document.createElement('option');
                        option.value = item.id;
                        option.textContent = `${item.item_name} - ${parseFloat(item.total_amount).toFixed(2)} AED (Due: ${item.due_date})`;
                        select.appendChild(option);
                    });
                });
            
            // Load installments
            fetch(`ajax_get_installments.php?lease_id=${leaseId}`)
                .then(r => r.json())
                .then(data => {
                    const select = document.getElementById('installment_id');
                    select.innerHTML = '<option value="">-- Select Installment (Optional) --</option>';
                    data.forEach(inst => {
                        const option = document.createElement('option');
                        option.value = inst.id;
                        option.textContent = `${inst.installment_date} - ${parseFloat(inst.amount).toFixed(2)} AED`;
                        select.appendChild(option);
                    });
                });
        });
    </script>

<?php require_once __DIR__ . '/includes/re_layout_footer.php'; ?>

