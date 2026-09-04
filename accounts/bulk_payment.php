<?php
require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/../includes/db_connect.php';
require_once __DIR__.'/../includes/ar_helpers.php';
require_role(['Owner','Admin','Account'], $conn);

$msg = $err = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  try {
    $payment_date = $_POST['payment_date'] ?? date('Y-m-d');
    $payment_method = $_POST['payment_method'] ?? 'cash';
    $total_amount = (float)($_POST['total_amount'] ?? 0);
    $notes = trim($_POST['notes'] ?? '');
    $allocations = $_POST['allocations'] ?? [];
    
    if ($total_amount <= 0) {
      throw new Exception('Total payment amount must be greater than zero');
    }
    
    if (empty($allocations) || !is_array($allocations)) {
      throw new Exception('Please add at least one allocation');
    }
    
    // Validate allocations
    $allocated_total = 0;
    foreach ($allocations as $allocation) {
      $amount = (float)($allocation['amount'] ?? 0);
      if ($amount > 0) {
        $allocated_total += $amount;
      }
    }
    
    if (abs($allocated_total - $total_amount) > 0.01) {
      throw new Exception('Allocated amounts must equal the total payment amount');
    }
    
    $conn->beginTransaction();
    
    // Create receipt
    $year = date('Y');
    $maxQ = $conn->prepare("SELECT MAX(CAST(SUBSTRING_INDEX(receipt_no,'-',-1) AS UNSIGNED)) FROM receipts WHERE receipt_no LIKE ?");
    $maxQ->execute(["RCP-$year-%"]);
    $next = (int)($maxQ->fetchColumn() ?: 0) + 1;
    $receipt_no = sprintf("RCP-%s-%05d", $year, $next);
    
    $ins = $conn->prepare("
      INSERT INTO receipts
        (receipt_no, receipt_date, total_amount, payment_method, notes, created_by)
      VALUES (?, ?, ?, ?, ?, ?)
    ");
    
    $ins->execute([
      $receipt_no, $payment_date, $total_amount, $payment_method, $notes, $_SESSION['user_id'] ?? null
    ]);
    
    $receipt_id = $conn->lastInsertId();
    
    // Create allocations
    $allocationIns = $conn->prepare("
      INSERT INTO receipt_allocations
        (receipt_id, invoice_id, allocated_amount, allocation_date, created_by)
      VALUES (?, ?, ?, ?, ?)
    ");
    
    foreach ($allocations as $allocation) {
      $invoice_id = (int)($allocation['invoice_id'] ?? 0);
      $amount = (float)($allocation['amount'] ?? 0);
      
      if ($invoice_id > 0 && $amount > 0) {
        $allocationIns->execute([
          $receipt_id, $invoice_id, $amount, $payment_date, $_SESSION['user_id'] ?? null
        ]);
        
        // Update invoice status
        ar_refresh_invoice_status($conn, $invoice_id);
      }
    }
    
    // Post to GL
    $glAccounts = [];
    $glQ = $conn->prepare("SELECT id, account_no, name FROM chart_of_accounts WHERE account_no IN ('AR-001', 'CASH-001', 'BANK-001')");
    $glQ->execute();
    while ($row = $glQ->fetch(PDO::FETCH_ASSOC)) {
      $glAccounts[$row['account_no']] = $row;
    }
    
    // Post payment to GL
    $glIns = $conn->prepare("
      INSERT INTO gl_journal_lines
        (journal_id, gl_account_id, debit_amount, credit_amount, description, created_by)
      VALUES (?, ?, ?, ?, ?, ?)
    ");
    
    // Create journal entry
    $journalIns = $conn->prepare("
      INSERT INTO gl_journals
        (journal_date, description, total_debit, total_credit, created_by)
      VALUES (?, ?, ?, ?, ?)
    ");
    
    $journalIns->execute([
      $payment_date, "Bulk Payment $receipt_no", $total_amount, $total_amount, $_SESSION['user_id'] ?? null
    ]);
    
    $journal_id = $conn->lastInsertId();
    
    // Debit Cash/Bank (Asset)
    $cash_account = $payment_method === 'bank_transfer' ? 'BANK-001' : 'CASH-001';
    if (isset($glAccounts[$cash_account])) {
      $glIns->execute([
        $journal_id, $glAccounts[$cash_account]['id'], $total_amount, 0,
        "Bulk Payment $receipt_no - Cash Receipt", $_SESSION['user_id'] ?? null
      ]);
    }
    
    // Credit Accounts Receivable (Asset)
    if (isset($glAccounts['AR-001'])) {
      $glIns->execute([
        $journal_id, $glAccounts['AR-001']['id'], 0, $total_amount,
        "Bulk Payment $receipt_no - AR Reduction", $_SESSION['user_id'] ?? null
      ]);
    }
    
    $conn->commit();
    $msg = "Bulk payment recorded successfully. Receipt #: $receipt_no";
    
    // Redirect to receipt view
    header("Location: receipt_view.php?id=$receipt_id");
    exit;
    
  } catch (Exception $e) {
    $conn->rollback();
    $err = "Failed to record bulk payment: " . $e->getMessage();
  }
}

// Get open invoices for allocation
$open_invoices = [];
$invoiceQ = $conn->prepare("
  SELECT i.id, i.invoice_no, i.invoice_date, i.due_date, i.total, i.status,
         c.client_name, c.client_id,
         (i.total - COALESCE(SUM(ra.allocated_amount), 0)) as remaining_amount
  FROM invoices i
  LEFT JOIN client c ON i.client_id = c.id
  LEFT JOIN receipt_allocations ra ON i.id = ra.invoice_id
  WHERE i.status IN ('issued', 'partially_paid')
    AND " . ar_collectible_invoice_sql('i') . "
  GROUP BY i.id, i.invoice_no, i.invoice_date, i.due_date, i.total, i.status, c.client_name, c.client_id
  HAVING remaining_amount > 0
  ORDER BY i.due_date ASC, i.invoice_date ASC
");
$invoiceQ->execute();
while ($row = $invoiceQ->fetch(PDO::FETCH_ASSOC)) {
  $open_invoices[] = $row;
}

function h($str) { return htmlspecialchars($str, ENT_QUOTES, 'UTF-8'); }
function money($amount) { return number_format($amount, 2); }
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Bulk Payment Recording | BMSystem</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
  <style>
    .allocation-row { border: 1px solid #dee2e6; border-radius: 0.375rem; padding: 1rem; margin-bottom: 0.5rem; }
    .allocation-row:last-child { margin-bottom: 0; }
    .totals-section { background-color: #f8f9fa; border-radius: 0.375rem; padding: 1rem; }
    .required { color: #dc3545; }
    .invoice-item { cursor: pointer; }
    .invoice-item:hover { background-color: #f8f9fa; }
  </style>
</head>
<body class="bg-light">
<div class="container my-4">
  <div class="row">
    <div class="col-12">
      <div class="d-flex justify-content-between align-items-center mb-4">
        <h2><i class="bi bi-cash-stack"></i> Bulk Payment Recording</h2>
        <a href="account.php?tab=payments" class="btn btn-outline-secondary">
          <i class="bi bi-arrow-left"></i> Back to Payments
        </a>
      </div>

      <?php if ($msg): ?>
      <div class="alert alert-success alert-dismissible fade show" role="alert">
        <i class="bi bi-check-circle"></i> <?= h($msg) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
      </div>
      <?php endif; ?>

      <?php if ($err): ?>
      <div class="alert alert-danger alert-dismissible fade show" role="alert">
        <i class="bi bi-exclamation-triangle"></i> <?= h($err) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
      </div>
      <?php endif; ?>

      <form method="POST" id="bulkPaymentForm">
        <div class="row">
          <div class="col-md-8">
            <!-- Payment Details -->
            <div class="card mb-4">
              <div class="card-header">
                <h5 class="mb-0">Payment Details</h5>
              </div>
              <div class="card-body">
                <div class="row">
                  <div class="col-md-4">
                    <div class="mb-3">
                      <label for="payment_date" class="form-label">Payment Date <span class="required">*</span></label>
                      <input type="date" class="form-control" id="payment_date" name="payment_date" 
                             value="<?= h($payment_date ?? date('Y-m-d')) ?>" required>
                    </div>
                  </div>
                  <div class="col-md-4">
                    <div class="mb-3">
                      <label for="payment_method" class="form-label">Payment Method <span class="required">*</span></label>
                      <select class="form-select" id="payment_method" name="payment_method" required>
                        <option value="cash" <?= ($payment_method ?? '') === 'cash' ? 'selected' : '' ?>>Cash</option>
                        <option value="check" <?= ($payment_method ?? '') === 'check' ? 'selected' : '' ?>>Check</option>
                        <option value="bank_transfer" <?= ($payment_method ?? '') === 'bank_transfer' ? 'selected' : '' ?>>Bank Transfer</option>
                        <option value="card" <?= ($payment_method ?? '') === 'card' ? 'selected' : '' ?>>Card</option>
                      </select>
                    </div>
                  </div>
                  <div class="col-md-4">
                    <div class="mb-3">
                      <label for="total_amount" class="form-label">Total Payment Amount <span class="required">*</span></label>
                      <input type="number" class="form-control" id="total_amount" name="total_amount" 
                             step="0.01" min="0" required onchange="calculateTotals()">
                    </div>
                  </div>
                </div>
                <div class="mb-3">
                  <label for="notes" class="form-label">Notes</label>
                  <textarea class="form-control" id="notes" name="notes" rows="2" 
                            placeholder="Optional notes about this payment"><?= h($notes ?? '') ?></textarea>
                </div>
              </div>
            </div>

            <!-- Invoice Selection -->
            <div class="card mb-4">
              <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="mb-0">Select Invoices to Pay</h5>
                <button type="button" class="btn btn-sm btn-outline-primary" id="selectAll">
                  <i class="bi bi-check-all"></i> Select All
                </button>
              </div>
              <div class="card-body">
                <div class="table-responsive" style="max-height: 400px; overflow-y: auto;">
                  <table class="table table-sm">
                    <thead>
                      <tr>
                        <th width="50">Select</th>
                        <th>Invoice #</th>
                        <th>Client</th>
                        <th class="text-end">Total</th>
                        <th class="text-end">Remaining</th>
                        <th>Due Date</th>
                        <th>Status</th>
                      </tr>
                    </thead>
                    <tbody>
                      <?php foreach ($open_invoices as $invoice): ?>
                      <tr class="invoice-item" data-invoice-id="<?= $invoice['id'] ?>" 
                          data-remaining="<?= $invoice['remaining_amount'] ?>">
                        <td>
                          <input type="checkbox" class="form-check-input invoice-checkbox" 
                                 value="<?= $invoice['id'] ?>" onchange="toggleInvoiceSelection(this)">
                        </td>
                        <td><?= h($invoice['invoice_no']) ?></td>
                        <td><?= h($invoice['client_name']) ?></td>
                        <td class="text-end">AED <?= money($invoice['total']) ?></td>
                        <td class="text-end">AED <?= money($invoice['remaining_amount']) ?></td>
                        <td><?= date('M d, Y', strtotime($invoice['due_date'])) ?></td>
                        <td>
                          <span class="badge bg-<?= $invoice['status'] === 'partially_paid' ? 'warning' : 'info' ?>">
                            <?= strtoupper($invoice['status']) ?>
                          </span>
                        </td>
                      </tr>
                      <?php endforeach; ?>
                    </tbody>
                  </table>
                </div>
              </div>
            </div>

            <!-- Allocations -->
            <div class="card mb-4">
              <div class="card-header">
                <h5 class="mb-0">Payment Allocations</h5>
              </div>
              <div class="card-body">
                <div id="allocationsContainer">
                  <!-- Allocations will be added here dynamically -->
                </div>
                <div class="text-muted text-center" id="noAllocationsMessage">
                  Select invoices above to create allocations
                </div>
              </div>
            </div>
          </div>

          <div class="col-md-4">
            <!-- Summary -->
            <div class="card mb-4">
              <div class="card-header">
                <h5 class="mb-0">Payment Summary</h5>
              </div>
              <div class="card-body totals-section">
                <div class="row mb-2">
                  <div class="col-6">Total Payment:</div>
                  <div class="col-6 text-end" id="totalPayment">AED 0.00</div>
                </div>
                <div class="row mb-2">
                  <div class="col-6">Allocated:</div>
                  <div class="col-6 text-end" id="allocatedAmount">AED 0.00</div>
                </div>
                <hr>
                <div class="row">
                  <div class="col-6"><strong>Remaining:</strong></div>
                  <div class="col-6 text-end"><strong id="remainingAmount">AED 0.00</strong></div>
                </div>
              </div>
            </div>

            <!-- Actions -->
            <div class="card">
              <div class="card-header">
                <h5 class="mb-0">Actions</h5>
              </div>
              <div class="card-body">
                <div class="d-grid gap-2">
                  <button type="submit" class="btn btn-primary" id="submitButton" disabled>
                    <i class="bi bi-check-circle"></i> Record Bulk Payment
                  </button>
                  <button type="button" class="btn btn-outline-secondary" onclick="clearAll()">
                    <i class="bi bi-arrow-clockwise"></i> Clear All
                  </button>
                </div>
              </div>
            </div>
          </div>
        </div>
      </form>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
let selectedInvoices = new Map();
let allocationCount = 0;

function toggleInvoiceSelection(checkbox) {
  const row = checkbox.closest('tr');
  const invoiceId = row.dataset.invoiceId;
  const remaining = parseFloat(row.dataset.remaining);
  
  if (checkbox.checked) {
    selectedInvoices.set(invoiceId, {
      id: invoiceId,
      invoice_no: row.cells[1].textContent,
      client_name: row.cells[2].textContent,
      remaining: remaining
    });
    addAllocation(invoiceId, remaining);
  } else {
    selectedInvoices.delete(invoiceId);
    removeAllocation(invoiceId);
  }
  
  calculateTotals();
}

function addAllocation(invoiceId, maxAmount) {
  const container = document.getElementById('allocationsContainer');
  const noMessage = document.getElementById('noAllocationsMessage');
  
  if (noMessage) {
    noMessage.style.display = 'none';
  }
  
  allocationCount++;
  const allocationHtml = `
    <div class="allocation-row" id="allocation-${allocationCount}">
      <div class="row">
        <div class="col-md-4">
          <label class="form-label">Invoice</label>
          <input type="text" class="form-control" value="${selectedInvoices.get(invoiceId).invoice_no}" readonly>
          <input type="hidden" name="allocations[${allocationCount}][invoice_id]" value="${invoiceId}">
        </div>
        <div class="col-md-4">
          <label class="form-label">Amount</label>
          <input type="number" class="form-control allocation-amount" 
                 name="allocations[${allocationCount}][amount]" 
                 value="${maxAmount.toFixed(2)}" 
                 max="${maxAmount}" 
                 step="0.01" 
                 min="0" 
                 onchange="calculateTotals()">
        </div>
        <div class="col-md-4">
          <label class="form-label">&nbsp;</label>
          <button type="button" class="btn btn-sm btn-outline-danger d-block" 
                  onclick="removeAllocation(${allocationCount}, ${invoiceId})">
            <i class="bi bi-trash"></i> Remove
          </button>
        </div>
      </div>
    </div>
  `;
  container.insertAdjacentHTML('beforeend', allocationHtml);
}

function removeAllocation(allocationId, invoiceId) {
  document.getElementById(`allocation-${allocationId}`).remove();
  selectedInvoices.delete(invoiceId);
  
  // Uncheck the invoice checkbox
  const checkbox = document.querySelector(`input[value="${invoiceId}"]`);
  if (checkbox) {
    checkbox.checked = false;
  }
  
  calculateTotals();
  
  // Show no allocations message if empty
  const container = document.getElementById('allocationsContainer');
  if (container.children.length === 0) {
    document.getElementById('noAllocationsMessage').style.display = 'block';
  }
}

function calculateTotals() {
  const totalPayment = parseFloat(document.getElementById('total_amount').value) || 0;
  let allocatedAmount = 0;
  
  document.querySelectorAll('.allocation-amount').forEach(input => {
    allocatedAmount += parseFloat(input.value) || 0;
  });
  
  const remaining = totalPayment - allocatedAmount;
  
  document.getElementById('totalPayment').textContent = `AED ${totalPayment.toFixed(2)}`;
  document.getElementById('allocatedAmount').textContent = `AED ${allocatedAmount.toFixed(2)}`;
  document.getElementById('remainingAmount').textContent = `AED ${remaining.toFixed(2)}`;
  
  // Enable/disable submit button
  const submitButton = document.getElementById('submitButton');
  if (Math.abs(remaining) < 0.01 && totalPayment > 0) {
    submitButton.disabled = false;
    submitButton.classList.remove('btn-secondary');
    submitButton.classList.add('btn-primary');
  } else {
    submitButton.disabled = true;
    submitButton.classList.remove('btn-primary');
    submitButton.classList.add('btn-secondary');
  }
}

function clearAll() {
  selectedInvoices.clear();
  document.getElementById('allocationsContainer').innerHTML = '';
  document.getElementById('noAllocationsMessage').style.display = 'block';
  document.querySelectorAll('.invoice-checkbox').forEach(cb => cb.checked = false);
  document.getElementById('total_amount').value = '';
  calculateTotals();
}

// Select all invoices
document.getElementById('selectAll').addEventListener('click', function() {
  document.querySelectorAll('.invoice-checkbox').forEach(checkbox => {
    if (!checkbox.checked) {
      checkbox.checked = true;
      toggleInvoiceSelection(checkbox);
    }
  });
});

// Initialize
document.addEventListener('DOMContentLoaded', function() {
  calculateTotals();
});
</script>
</body>
</html>
