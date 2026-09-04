<?php
/**
 * Real Estate Module - Create Invoice
 * Generate invoice from billing items or create new invoice
 */

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../includes/branding.php';
require_once __DIR__ . '/../../includes/company_helper.php';
require_once __DIR__ . '/../../includes/module_access.php';
require_once __DIR__ . '/../../includes/csrf.php';
require_once __DIR__ . '/includes/billing_helper.php';

require_login();
require_module_access($conn, MODULE_REALESTATE);

$brand = getBrandSettings($conn);
$currentCompanyId = current_company_id($conn) ?: 1;
$currentUserId = $_SESSION['user_id'] ?? null;

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    
    $leaseId = (int)$_POST['lease_id'];
    $installmentId = !empty($_POST['installment_id']) ? (int)$_POST['installment_id'] : null;
    $invoiceDate = $_POST['invoice_date'] ?? date('Y-m-d');
    $dueDate = $_POST['due_date'] ?? '';
    $taxRate = (float)($_POST['tax_rate'] ?? 0);
    $discountAmount = (float)($_POST['discount_amount'] ?? 0);
    $notes = $_POST['notes'] ?? '';
    $billingItemIds = $_POST['billing_item_ids'] ?? [];
    
    // Allow creating invoice without billing items if installment is selected
    $allowWithoutItems = !empty($installmentId);
    
    if (!$leaseId || !$dueDate) {
        $_SESSION['error'] = 'Please fill in all required fields.';
        header('Location: billing_invoice_create.php');
        exit;
    }
    
    if (empty($billingItemIds) && !$allowWithoutItems) {
        $_SESSION['error'] = 'Please select at least one billing item, or link the invoice to an installment.';
        header('Location: billing_invoice_create.php');
        exit;
    }
    
    try {
        $conn->beginTransaction();
        
        // Generate invoice number
        $invoiceNumber = generate_invoice_number($conn, $currentCompanyId);
        
        // Calculate totals from selected billing items or installment
        $subtotal = 0;  // Base amount before tax
        $billingItemsTax = 0;  // Tax already in billing items
        $billingItems = [];
        
        if (!empty($billingItemIds)) {
            // Get billing items
            $placeholders = str_repeat('?,', count($billingItemIds) - 1) . '?';
            $itemsStmt = $conn->prepare("
                SELECT * FROM re_billing_items
                WHERE id IN ($placeholders) AND company_id = ? AND is_paid = 0
            ");
            $itemsStmt->execute(array_merge($billingItemIds, [$currentCompanyId]));
            $billingItems = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);
            
            foreach ($billingItems as $item) {
                // Use base amount (before tax) for subtotal
                $itemBase = (float)($item['amount'] ?? $item['total_amount']);
                $itemTax = (float)($item['tax_amount'] ?? 0);
                
                $subtotal += $itemBase;
                $billingItemsTax += $itemTax;
            }
        } elseif ($installmentId) {
            // Get installment amount
            $instStmt = $conn->prepare("
                SELECT amount FROM re_lease_installments
                WHERE id = ? AND lease_id = ?
            ");
            $instStmt->execute([$installmentId, $leaseId]);
            $installment = $instStmt->fetch(PDO::FETCH_ASSOC);
            
            if ($installment) {
                $subtotal = (float)$installment['amount'];
                // Create a virtual billing item for the invoice
                $billingItems[] = [
                    'id' => null,
                    'item_name' => 'Rent Installment',
                    'item_description' => 'Rent for installment dated ' . date('Y-m-d', strtotime($installment['installment_date'] ?? '')),
                    'total_amount' => $subtotal,
                    'quantity' => 1,
                    'amount' => $subtotal,
                    'tax_rate' => 0,
                    'tax_amount' => 0
                ];
            } else {
                throw new Exception('Installment not found.');
            }
        } else {
            throw new Exception('Please select billing items or link to an installment.');
        }
        
        // Use tax from billing items if form tax rate is 0, otherwise use form tax rate
        if ($taxRate > 0) {
            // User specified a tax rate on the form - apply it to subtotal
            $taxAmount = ($subtotal * $taxRate) / 100;
        } else {
            // Use the tax already calculated in billing items
            $taxAmount = $billingItemsTax;
            // Calculate effective tax rate for reporting
            if ($subtotal > 0 && $billingItemsTax > 0) {
                $taxRate = round(($billingItemsTax / $subtotal) * 100, 2);
            }
        }
        
        $totalAmount = $subtotal + $taxAmount - $discountAmount;
        $outstandingAmount = $totalAmount;
        
        // Create invoice
        // Check if installment_id column exists
        $hasInstallmentId = false;
        try {
            $checkCol = $conn->query("SHOW COLUMNS FROM re_invoices LIKE 'installment_id'");
            $hasInstallmentId = $checkCol->rowCount() > 0;
        } catch (Exception $e) {
            // Column doesn't exist, continue without it
        }
        
        if ($hasInstallmentId) {
            $stmt = $conn->prepare("
                INSERT INTO re_invoices
                (company_id, lease_id, installment_id, invoice_number, invoice_date, due_date, subtotal, 
                 tax_rate, tax_amount, discount_amount, total_amount, outstanding_amount, 
                 status, notes, created_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'sent', ?, ?)
            ");
            $stmt->execute([
                $currentCompanyId, $leaseId, $installmentId, $invoiceNumber, $invoiceDate, $dueDate,
                $subtotal, $taxRate, $taxAmount, $discountAmount, $totalAmount, 
                $outstandingAmount, $notes, $currentUserId
            ]);
        } else {
            $stmt = $conn->prepare("
                INSERT INTO re_invoices
                (company_id, lease_id, invoice_number, invoice_date, due_date, subtotal, 
                 tax_rate, tax_amount, discount_amount, total_amount, outstanding_amount, 
                 status, notes, created_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'sent', ?, ?)
            ");
            $stmt->execute([
                $currentCompanyId, $leaseId, $invoiceNumber, $invoiceDate, $dueDate,
                $subtotal, $taxRate, $taxAmount, $discountAmount, $totalAmount, 
                $outstandingAmount, $notes, $currentUserId
            ]);
        }
        $invoiceId = $conn->lastInsertId();

        // Create invoice items
        $itemStmt = $conn->prepare("
            INSERT INTO re_invoice_items
            (company_id, invoice_id, billing_item_id, item_name, item_description, 
             quantity, unit_price, tax_rate, tax_amount, line_total, display_order)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        
        $order = 1;
        foreach ($billingItems as $item) {
            // Use tax from billing item, or calculate if using form tax rate
            $itemBase = (float)($item['amount'] ?? $item['total_amount']);
            $itemTaxRate = (float)($item['tax_rate'] ?? $taxRate);
            $itemTax = (float)($item['tax_amount'] ?? 0);
            
            // If billing item has no tax but form has tax rate, calculate it
            if ($itemTax == 0 && $taxRate > 0) {
                $itemTax = ($itemBase * $taxRate) / 100;
            }
            
            $lineTotal = $itemBase + $itemTax;
            
            $itemStmt->execute([
                $currentCompanyId,
                $invoiceId,
                $item['id'], // Will be NULL if created from installment
                $item['item_name'],
                $item['item_description'] ?? '',
                $item['quantity'] ?? 1,
                $item['unit_price'] ?? $itemBase,
                $itemTaxRate,
                $itemTax,
                $lineTotal,
                $order++
            ]);
        }
        
        $conn->commit();

        // Tenant in-app + push notification: new invoice issued
        require_once __DIR__ . '/../../includes/tenant_notifications.php';
        tenant_notification_create($conn, [
            'company_id' => $currentCompanyId,
            'lease_id' => (int)$leaseId,
            'type' => 'invoice_issued',
            'entity_type' => 'invoice',
            'entity_id' => (int)$invoiceId,
            'title' => 'New invoice issued',
            'body' => 'Invoice ' . $invoiceNumber . ' for AED ' . number_format((float)$totalAmount, 2) . ' has been issued.',
        ]);
        
        // Post invoice to accounting (non-blocking — invoice is always saved first)
        try {
            require_once __DIR__ . '/accounting/accounting_integration.php';
            $accountingResult = post_invoice_to_accounting($invoiceId, $currentCompanyId, $currentUserId);
            if (!$accountingResult['success']) {
                error_log("Accounting posting failed for invoice {$invoiceId}: " . $accountingResult['error']);
                $_SESSION['accounting_warning'] = "Invoice saved, but the accounting journal could not be posted. Reason: {$accountingResult['error']}. Please post it manually from Journal Entries.";
            }
        } catch (Exception $e) {
            error_log("Accounting integration error for invoice {$invoiceId}: " . $e->getMessage());
            $_SESSION['accounting_warning'] = "Invoice saved, but the accounting journal could not be posted. Reason: {$e->getMessage()}. Please post it manually from Journal Entries.";
        }
        
        $_SESSION['success'] = 'Invoice created successfully.';
        header('Location: billing_invoice_view.php?id=' . $invoiceId);
        exit;
        
    } catch (Exception $e) {
        $conn->rollBack();
        $_SESSION['error'] = 'Error creating invoice: ' . $e->getMessage();
        header('Location: billing_invoice_create.php');
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

// Get unpaid billing items (will be loaded via AJAX when lease is selected)
$billingItems = [];

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

// Set page title and include layout
$pageTitle = 'Create Invoice';
require_once __DIR__ . '/includes/re_layout_header.php';
?>
        <div class="row">
            <div class="col-md-10 mx-auto">
                <div class="card">
                    <div class="card-header">
                        <h4 class="mb-0"><i class="bi bi-file-text"></i> Create New Invoice</h4>
                    </div>
                    <div class="card-body">
                        <?php if (isset($_SESSION['error'])): ?>
                            <div class="alert alert-danger"><?= h($_SESSION['error']) ?></div>
                            <?php unset($_SESSION['error']); ?>
                        <?php endif; ?>
                        
                        <form method="POST" id="invoiceForm">
                            <?= csrf_field() ?>
                            
                            <div class="row mb-4">
                                <div class="col-md-4">
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
                                <div class="col-md-4" id="installmentWrapper" style="display: none;">
                                    <label class="form-label">Link to Installment (Optional)</label>
                                    <select name="installment_id" id="installment_id" class="form-select">
                                        <option value="">-- Not linked to installment --</option>
                                    </select>
                                    <small class="text-muted">Optionally link this invoice to a specific rent installment</small>
                                </div>
                                <div class="col-md-2">
                                    <label class="form-label">Invoice Date *</label>
                                    <input type="date" name="invoice_date" class="form-control" value="<?= date('Y-m-d') ?>" required>
                                </div>
                                <div class="col-md-2">
                                    <label class="form-label">Due Date *</label>
                                    <input type="date" name="due_date" class="form-control" required>
                                </div>
                            </div>
                            
                            <!-- Billing Items Selection -->
                            <div id="billingItemsSection" style="display: none;">
                                <h5 class="mb-3">Select Billing Items</h5>
                                <div id="billingItemsList" class="mb-3"></div>
                                <div class="alert alert-info" id="noBillingItemsAlert" style="display: none;">
                                    <i class="bi bi-info-circle"></i> No unpaid billing items found. You can still create an invoice manually or link it to an installment above.
                                </div>
                            </div>
                            
                            <!-- Alternative: Create from Installment -->
                            <div id="createFromInstallmentSection" style="display: none;" class="mb-3">
                                <div class="alert alert-warning">
                                    <i class="bi bi-lightbulb"></i> <strong>Tip:</strong> If you selected an installment above, you can create an invoice for that installment amount. 
                                    <button type="button" class="btn btn-sm btn-primary ms-2" onclick="createInvoiceFromInstallment()">
                                        <i class="bi bi-file-earmark-plus"></i> Create Invoice from Installment
                                    </button>
                                </div>
                            </div>
                            
                            <div class="row mb-3">
                                <div class="col-md-4">
                                    <label class="form-label">Tax Rate (%)</label>
                                    <input type="number" step="0.01" name="tax_rate" class="form-control" value="0" min="0" max="100">
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label">Discount Amount (AED)</label>
                                    <input type="number" step="0.01" name="discount_amount" class="form-control" value="0" min="0">
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label">Total Amount</label>
                                    <input type="text" id="total_amount_display" class="form-control" readonly value="0.00 AED">
                                    <small class="text-muted"><i class="bi bi-info-circle"></i> Calculated automatically from selected billing items</small>
                                </div>
                            </div>
                            
                            <div class="mb-3">
                                <label class="form-label">Notes</label>
                                <textarea name="notes" class="form-control" rows="3"></textarea>
                            </div>
                            
                            <div class="d-flex justify-content-between">
                                <a href="billing.php" class="btn btn-secondary">
                                    <i class="bi bi-arrow-left"></i> Cancel
                                </a>
                                <button type="submit" class="btn btn-primary" id="submitBtn" disabled>
                                    <i class="bi bi-check-circle"></i> Create Invoice
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
        let selectedItems = [];
        
        document.getElementById('lease_id').addEventListener('change', function() {
            const leaseId = this.value;
            if (!leaseId) {
                document.getElementById('billingItemsSection').style.display = 'none';
                document.getElementById('installmentWrapper').style.display = 'none';
                return;
            }
            
            // Show installment selector
            document.getElementById('installmentWrapper').style.display = 'block';
            
            // Fetch installments for this lease
            fetch(`ajax_get_installments.php?lease_id=${leaseId}`)
                .then(r => r.json())
                .then(installments => {
                    const installmentSelect = document.getElementById('installment_id');
                    installmentSelect.innerHTML = '<option value="">-- Not linked to installment --</option>';
                    
                    installments.forEach(inst => {
                        const option = document.createElement('option');
                        option.value = inst.id;
                        let text = `${inst.installment_date} - ${parseFloat(inst.amount).toFixed(2)} AED (${inst.status})`;
                        if (inst.cheque_number) {
                            text += ` - Chq #${inst.cheque_number}`;
                        }
                        option.textContent = text;
                        installmentSelect.appendChild(option);
                    });
                    
                    // After loading installments, update total if one is selected
                    setTimeout(() => {
                        if (installmentSelect.value) {
                            updateTotal();
                        }
                    }, 100);
                })
                .catch(e => console.error('Error loading installments:', e));
            
            // Fetch unpaid billing items for this lease
            fetch(`ajax_get_billing_items.php?lease_id=${leaseId}`)
                .then(r => r.json())
                .then(data => {
                    const itemsDiv = document.getElementById('billingItemsList');
                    itemsDiv.innerHTML = '';
                    
                    if (data.length === 0) {
                        document.getElementById('billingItemsSection').style.display = 'block';
                        document.getElementById('noBillingItemsAlert').style.display = 'block';
                        itemsDiv.innerHTML = '';
                        // Show create from installment option if installment is selected
                        const installmentId = document.getElementById('installment_id').value;
                        if (installmentId) {
                            document.getElementById('createFromInstallmentSection').style.display = 'block';
                        }
                        return;
                    }
                    
                    document.getElementById('billingItemsSection').style.display = 'block';
                    document.getElementById('noBillingItemsAlert').style.display = 'none';
                    
                    data.forEach(item => {
                        const div = document.createElement('div');
                        div.className = 'form-check mb-2';
                        const baseAmount = parseFloat(item.amount) || parseFloat(item.total_amount) || 0;
                        const taxAmount = parseFloat(item.tax_amount) || 0;
                        const taxRate = parseFloat(item.tax_rate) || 0;
                        const totalAmount = parseFloat(item.total_amount) || 0;
                        
                        let amountText = `${totalAmount.toFixed(2)} AED`;
                        if (taxAmount > 0) {
                            amountText = `${baseAmount.toFixed(2)} + ${taxAmount.toFixed(2)} VAT = ${totalAmount.toFixed(2)} AED`;
                        }
                        
                        div.innerHTML = `
                            <input class="form-check-input billing-item-checkbox" 
                                   type="checkbox" 
                                   value="${item.id}" 
                                   data-base="${baseAmount}"
                                   data-tax="${taxAmount}"
                                   data-tax-rate="${taxRate}"
                                   data-total="${totalAmount}"
                                   id="billing_item_${item.id}">
                            <label class="form-check-label" for="billing_item_${item.id}">
                                <strong>${item.item_name}</strong> - 
                                ${item.item_type === 'rent' ? 'Rent' : 
                                  item.item_type === 'service_charge' ? 'Service Charge' : 
                                  item.item_type === 'penalty' ? 'Penalty' : 'Other'}
                                <br>
                                <small class="text-muted">Due: ${item.due_date} | Amount: ${amountText}</small>
                            </label>
                        `;
                        itemsDiv.appendChild(div);
                        
                        // Add event listener to checkbox
                        const checkbox = div.querySelector('.billing-item-checkbox');
                        checkbox.addEventListener('change', updateTotal);
                    });
                    
                    // Initial total update
                    updateTotal();
                });
        });
        
        function updateTotal() {
            const checkboxes = document.querySelectorAll('.billing-item-checkbox:checked');
            let baseSubtotal = 0;
            let billingItemsTax = 0;
            selectedItems = [];
            
            // First, check if an installment is selected (takes priority)
            const installmentSelect = document.getElementById('installment_id');
            const installmentId = installmentSelect ? installmentSelect.value : '';
            
            if (installmentId) {
                // Get amount from installment option text
                const option = installmentSelect.options[installmentSelect.selectedIndex];
                const text = option.textContent;
                const match = text.match(/(\d+\.\d+)\s+AED/);
                if (match) {
                    baseSubtotal = parseFloat(match[1]);
                }
            } else {
                // Calculate from selected billing items - use base amounts and tax separately
                checkboxes.forEach(cb => {
                    const base = parseFloat(cb.dataset.base) || parseFloat(cb.dataset.total) || 0;
                    const tax = parseFloat(cb.dataset.tax) || 0;
                    baseSubtotal += base;
                    billingItemsTax += tax;
                    selectedItems.push(cb.value);
                });
            }
            
            // Get tax rate and discount from form
            const taxRateInput = document.querySelector('input[name="tax_rate"]');
            const discountInput = document.querySelector('input[name="discount_amount"]');
            const formTaxRate = parseFloat(taxRateInput ? taxRateInput.value : 0) || 0;
            const discountAmount = parseFloat(discountInput ? discountInput.value : 0) || 0;
            
            // Calculate tax: use form tax rate if set, otherwise use tax from billing items
            let taxAmount = 0;
            if (formTaxRate > 0) {
                taxAmount = (baseSubtotal * formTaxRate) / 100;
            } else {
                taxAmount = billingItemsTax;
            }
            
            const total = baseSubtotal + taxAmount - discountAmount;
            
            const totalDisplay = document.getElementById('total_amount_display');
            if (totalDisplay) {
                totalDisplay.value = total.toFixed(2) + ' AED';
            }
            
            const submitBtn = document.getElementById('submitBtn');
            if (submitBtn) {
                // Enable if installment is selected OR billing items are selected
                submitBtn.disabled = !installmentId && selectedItems.length === 0;
            }
            
            // Add hidden inputs for selected items (only if no installment)
            const existingInputs = document.querySelectorAll('input[name="billing_item_ids[]"]');
            existingInputs.forEach(input => input.remove());
            
            if (!installmentId) {
                selectedItems.forEach(id => {
                    const input = document.createElement('input');
                    input.type = 'hidden';
                    input.name = 'billing_item_ids[]';
                    input.value = id;
                    document.getElementById('invoiceForm').appendChild(input);
                });
            }
            
            console.log('Total updated:', { subtotal, taxAmount, discountAmount, total, selectedItems, installmentId });
        }
        
        // Create invoice from selected installment
        function createInvoiceFromInstallment() {
            const installmentId = document.getElementById('installment_id').value;
            if (!installmentId) {
                alert('Please select an installment first');
                return;
            }
            
            // Get installment details from the option text
            const option = document.getElementById('installment_id').options[document.getElementById('installment_id').selectedIndex];
            const text = option.textContent;
            const match = text.match(/(\d+\.\d+)\s+AED/);
            const amount = match ? parseFloat(match[1]) : 0;
            
            if (amount > 0) {
                // Update the total display
                const taxRate = parseFloat(document.querySelector('input[name="tax_rate"]').value) || 0;
                const discountAmount = parseFloat(document.querySelector('input[name="discount_amount"]').value) || 0;
                const taxAmount = (amount * taxRate) / 100;
                const total = amount + taxAmount - discountAmount;
                
                document.getElementById('total_amount_display').value = total.toFixed(2) + ' AED';
                document.getElementById('submitBtn').disabled = false;
                
                // Clear any selected billing items
                document.querySelectorAll('.billing-item-checkbox').forEach(cb => cb.checked = false);
                selectedItems = [];
                updateTotal();
                
                alert(`Invoice total set to ${total.toFixed(2)} AED based on installment. You can now create the invoice.`);
            }
        }
        
        // Auto-update total when installment is selected
        const installmentSelect = document.getElementById('installment_id');
        if (installmentSelect) {
            installmentSelect.addEventListener('change', function() {
                if (this.value) {
                    document.getElementById('createFromInstallmentSection').style.display = 'block';
                    // Clear any selected billing items when installment is selected
                    document.querySelectorAll('.billing-item-checkbox').forEach(cb => cb.checked = false);
                    selectedItems = [];
                } else {
                    document.getElementById('createFromInstallmentSection').style.display = 'none';
                }
                // Update total (will check for installment first, then billing items)
                updateTotal();
            });
            
            // Also trigger on page load if installment is already selected
            if (installmentSelect.value) {
                updateTotal();
            }
        }
        
        // Update total when tax rate or discount changes
        const taxRateInput = document.querySelector('input[name="tax_rate"]');
        const discountInput = document.querySelector('input[name="discount_amount"]');
        if (taxRateInput) {
            taxRateInput.addEventListener('input', updateTotal);
        }
        if (discountInput) {
            discountInput.addEventListener('input', updateTotal);
        }
        
        
        // Set default due date to 30 days from today
        const dueDateInput = document.querySelector('input[name="due_date"]');
        const dueDate = new Date();
        dueDate.setDate(dueDate.getDate() + 30);
        dueDateInput.valueAsDate = dueDate;
    </script>

<?php require_once __DIR__ . '/includes/re_layout_footer.php'; ?>

