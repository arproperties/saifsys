<?php
/**
 * Real Estate Module - Add Billing Item
 * Manually create billing items for leases
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
$userId = current_user_id();
$error = '';
$success = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    
    $leaseId = (int)($_POST['lease_id'] ?? 0);
    $itemType = $_POST['item_type'] ?? 'other';
    $itemName = trim($_POST['item_name'] ?? '');
    $itemDescription = trim($_POST['item_description'] ?? '');
    $amount = (float)($_POST['amount'] ?? 0);
    $quantity = (float)($_POST['quantity'] ?? 1);
    $unitPrice = (float)($_POST['unit_price'] ?? $amount);
    $taxRate = (float)($_POST['tax_rate'] ?? 0);
    $dueDate = $_POST['due_date'] ?? date('Y-m-d', strtotime('+30 days'));
    $billingPeriodStart = !empty($_POST['billing_period_start']) ? $_POST['billing_period_start'] : null;
    $billingPeriodEnd = !empty($_POST['billing_period_end']) ? $_POST['billing_period_end'] : null;
    $installmentId = !empty($_POST['installment_id']) ? (int)$_POST['installment_id'] : null;
    
    // billing_date is required - use billing period start if provided, otherwise use today
    $billingDate = $billingPeriodStart ?: date('Y-m-d');
    
    if (!$leaseId || !$itemName || $amount <= 0) {
        $error = 'Please fill in all required fields (Lease, Item Name, and Amount).';
    } else {
        try {
            // Calculate tax and total
            $taxAmount = ($amount * $taxRate) / 100;
            $totalAmount = $amount + $taxAmount;
            
            $stmt = $conn->prepare("
                INSERT INTO re_billing_items
                (company_id, lease_id, item_type, item_name, item_description, amount, 
                 quantity, unit_price, tax_rate, tax_amount, total_amount, 
                 billing_date, billing_period_start, billing_period_end, due_date, installment_id, 
                 is_paid, paid_amount, created_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, 0, ?)
            ");
            $stmt->execute([
                $currentCompanyId, $leaseId, $itemType, $itemName, $itemDescription,
                $amount, $quantity, $unitPrice, $taxRate, $taxAmount, $totalAmount,
                $billingDate, $billingPeriodStart, $billingPeriodEnd, $dueDate, $installmentId, $userId
            ]);
            
            $_SESSION['success'] = 'Billing item created successfully.';
            header('Location: billing_items.php');
            exit;
        } catch (Exception $e) {
            $error = 'Error creating billing item: ' . $e->getMessage();
        }
    }
}

// Get leases for dropdown
$leases = $conn->prepare("
    SELECT l.id, l.lease_number, u.unit_number, b.name as building_name,
           t.first_name, t.last_name
    FROM re_leases l
    JOIN re_units u ON u.id = l.unit_id
    JOIN re_buildings b ON b.id = u.building_id
    JOIN re_tenants t ON t.id = l.tenant_id
    WHERE l.company_id = ? AND l.status = 'active'
    ORDER BY l.lease_number
");
$leases->execute([$currentCompanyId]);
$leases = $leases->fetchAll(PDO::FETCH_ASSOC);

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

$pageTitle = 'Create Billing Item';
require_once __DIR__ . '/includes/re_layout_header.php';
?>

<div class="card">
    <div class="card-header">
        <h5 class="mb-0"><i class="bi bi-plus-circle"></i> Create New Billing Item</h5>
    </div>
    <div class="card-body">
        <?php if ($error): ?>
            <div class="alert alert-danger"><?= h($error) ?></div>
        <?php endif; ?>
        
        <form method="POST" id="billingItemForm">
            <?= csrf_field() ?>
            
            <div class="row mb-3">
                <div class="col-md-6">
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
                <div class="col-md-6">
                    <label class="form-label">Item Type *</label>
                    <select name="item_type" class="form-select" required>
                        <option value="rent">Rent</option>
                        <option value="service_charge" selected>Service Charge</option>
                        <option value="penalty">Penalty</option>
                        <option value="other">Other</option>
                    </select>
                </div>
            </div>
            
            <div class="row mb-3">
                <div class="col-md-6">
                    <label class="form-label">Item Name *</label>
                    <input type="text" name="item_name" class="form-control" required 
                           placeholder="e.g., Monthly Service Charge">
                </div>
                <div class="col-md-6">
                    <label class="form-label">Due Date *</label>
                    <input type="date" name="due_date" class="form-control" required 
                           value="<?= date('Y-m-d', strtotime('+30 days')) ?>">
                </div>
            </div>
            
            <div class="mb-3">
                <label class="form-label">Description</label>
                <textarea name="item_description" class="form-control" rows="3" 
                          placeholder="Optional description for this billing item"></textarea>
            </div>
            
            <div class="row mb-3">
                <div class="col-md-3">
                    <label class="form-label">Quantity</label>
                    <input type="number" name="quantity" class="form-control" step="0.01" 
                           value="1" min="0.01" onchange="calculateTotal()">
                </div>
                <div class="col-md-3">
                    <label class="form-label">Unit Price (AED)</label>
                    <input type="number" name="unit_price" class="form-control" step="0.01" 
                           value="0" min="0" onchange="calculateTotal()">
                </div>
                <div class="col-md-3">
                    <label class="form-label">Amount (AED) *</label>
                    <input type="number" name="amount" id="amount" class="form-control" 
                           step="0.01" value="0" min="0" required onchange="calculateTotal()">
                </div>
                <div class="col-md-3">
                    <label class="form-label">Tax Rate (%)</label>
                    <input type="number" name="tax_rate" id="tax_rate" class="form-control" 
                           step="0.01" value="0" min="0" max="100" onchange="calculateTotal()">
                </div>
            </div>
            
            <div class="row mb-3">
                <div class="col-md-4">
                    <label class="form-label">Total Amount (AED)</label>
                    <input type="text" id="total_amount_display" class="form-control" 
                           readonly value="0.00 AED">
                    <small class="text-muted">Calculated: Amount + Tax</small>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Billing Period Start (Optional)</label>
                    <input type="date" name="billing_period_start" class="form-control">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Billing Period End (Optional)</label>
                    <input type="date" name="billing_period_end" class="form-control">
                </div>
            </div>
            
            <div class="mb-3">
                <label class="form-label">Link to Installment (Optional)</label>
                <select name="installment_id" id="installment_id" class="form-select">
                    <option value="">-- Not linked to installment --</option>
                </select>
                <small class="text-muted">Optionally link this billing item to a specific rent installment</small>
            </div>
            
            <div class="mt-4">
                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-check-circle"></i> Create Billing Item
                </button>
                <a href="billing_items.php" class="btn btn-secondary">
                    <i class="bi bi-x-circle"></i> Cancel
                </a>
            </div>
        </form>
    </div>
</div>

<script>
    function calculateTotal() {
        const quantity = parseFloat(document.querySelector('input[name="quantity"]').value) || 0;
        const unitPrice = parseFloat(document.querySelector('input[name="unit_price"]').value) || 0;
        const amount = parseFloat(document.getElementById('amount').value) || 0;
        const taxRate = parseFloat(document.getElementById('tax_rate').value) || 0;
        
        // If amount is manually entered, use it; otherwise calculate from quantity * unitPrice
        let subtotal = amount > 0 ? amount : (quantity * unitPrice);
        
        // Update amount field if calculated from quantity/unitPrice
        if (amount === 0 && quantity > 0 && unitPrice > 0) {
            document.getElementById('amount').value = subtotal.toFixed(2);
        }
        
        const taxAmount = (subtotal * taxRate) / 100;
        const total = subtotal + taxAmount;
        
        document.getElementById('total_amount_display').value = total.toFixed(2) + ' AED';
    }
    
    // Load installments when lease is selected
    document.getElementById('lease_id').addEventListener('change', function() {
        const leaseId = this.value;
        const installmentSelect = document.getElementById('installment_id');
        installmentSelect.innerHTML = '<option value="">-- Not linked to installment --</option>';
        
        if (leaseId) {
            fetch(`ajax_get_installments.php?lease_id=${leaseId}`)
                .then(r => r.json())
                .then(installments => {
                    installments.forEach(inst => {
                        const option = document.createElement('option');
                        option.value = inst.id;
                        option.textContent = `${inst.installment_date} - ${parseFloat(inst.amount).toFixed(2)} AED (${inst.status})`;
                        installmentSelect.appendChild(option);
                    });
                })
                .catch(e => console.error('Error loading installments:', e));
        }
    });
</script>

<?php require_once __DIR__ . '/includes/re_layout_footer.php'; ?>
