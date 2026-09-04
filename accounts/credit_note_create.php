<?php
require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/../includes/db_connect.php';
require_once __DIR__.'/../includes/ar_helpers.php';
require_role(['Owner','Admin','Account'], $conn);

$msg = $err = '';
$credit_note_id = null;

// Get invoice ID if creating from invoice
$invoice_id = (int)($_GET['invoice_id'] ?? 0);
$invoice = null;
if ($invoice_id > 0) {
  $invoice = ar_get_invoice($conn, $invoice_id);
  if (!$invoice) {
    $err = "Invoice not found";
    $invoice_id = 0;
  }
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  try {
    $client_id = (int)($_POST['client_id'] ?? 0);
    $invoice_id = (int)($_POST['invoice_id'] ?? 0);
    $credit_note_date = $_POST['credit_note_date'] ?? date('Y-m-d');
    $reference = trim($_POST['reference'] ?? '');
    $reason = $_POST['reason'] ?? 'adjustment';
    $reason_description = trim($_POST['reason_description'] ?? '');
    $notes = trim($_POST['notes'] ?? '');
    $items = $_POST['items'] ?? [];
    
    if ($client_id <= 0) {
      throw new Exception('Please select a client');
    }
    
    if (empty($items) || !is_array($items)) {
      throw new Exception('Please add at least one line item');
    }
    
    // Generate credit note number
    $year = date('Y');
    $maxQ = $conn->prepare("SELECT MAX(CAST(SUBSTRING_INDEX(credit_note_number,'-',-1) AS UNSIGNED)) FROM credit_notes WHERE credit_note_number LIKE ?");
    $maxQ->execute(["CN-$year-%"]);
    $next = (int)($maxQ->fetchColumn() ?: 0) + 1;
    $credit_note_number = sprintf("CN-%s-%05d", $year, $next);
    
    $conn->beginTransaction();
    
    // Calculate totals
    $subtotal = 0;
    $tax_amount = 0;
    $total_amount = 0;
    
    foreach ($items as $item) {
      if (empty($item['description']) || (float)$item['quantity'] <= 0) continue;
      
      $quantity = (float)$item['quantity'];
      $unit_price = (float)$item['unit_price'];
      $line_total = $quantity * $unit_price;
      $tax_rate = (float)($item['tax_rate'] ?? 0);
      $item_tax = $line_total * ($tax_rate / 100);
      
      $subtotal += $line_total;
      $tax_amount += $item_tax;
    }
    
    $total_amount = $subtotal + $tax_amount;
    
    // Insert credit note header
    $ins = $conn->prepare("
      INSERT INTO credit_notes
        (credit_note_number, client_id, invoice_id, credit_note_date, reference,
         reason, reason_description, subtotal, tax_amount, total_amount,
         status, notes, created_by)
      VALUES
        (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'draft', ?, ?)
    ");
    
    $ins->execute([
      $credit_note_number, $client_id, $invoice_id ?: null, $credit_note_date, $reference,
      $reason, $reason_description, $subtotal, $tax_amount, $total_amount,
      $notes, $_SESSION['user_id'] ?? null
    ]);
    
    $credit_note_id = $conn->lastInsertId();
    
    // Insert credit note items
    $itemIns = $conn->prepare("
      INSERT INTO credit_note_items
        (credit_note_id, description, quantity, unit_price, line_total, tax_rate, tax_amount)
      VALUES (?, ?, ?, ?, ?, ?, ?)
    ");
    
    foreach ($items as $item) {
      if (empty($item['description']) || (float)$item['quantity'] <= 0) continue;
      
      $quantity = (float)$item['quantity'];
      $unit_price = (float)$item['unit_price'];
      $line_total = $quantity * $unit_price;
      $tax_rate = (float)($item['tax_rate'] ?? 0);
      $item_tax = $line_total * ($tax_rate / 100);
      
      $itemIns->execute([
        $credit_note_id, $item['description'], $quantity, $unit_price, $line_total, $tax_rate, $item_tax
      ]);
    }
    
    // Post to GL if status is 'issued'
    if ($_POST['status'] === 'issued') {
      // Get GL accounts for credit notes
      $glAccounts = [];
      $glQ = $conn->prepare("SELECT id, account_no, name FROM chart_of_accounts WHERE account_no IN ('CN-001', 'CN-002')");
      $glQ->execute();
      while ($row = $glQ->fetch(PDO::FETCH_ASSOC)) {
        $glAccounts[$row['account_no']] = $row;
      }
      
      // Post credit note to GL
      $glIns = $conn->prepare("
        INSERT INTO credit_note_gl_postings
          (credit_note_id, gl_account_id, debit_amount, credit_amount, description, created_by)
        VALUES (?, ?, ?, ?, ?, ?)
      ");
      
      // Debit Credit Notes Receivable (Asset)
      if (isset($glAccounts['CN-001'])) {
        $glIns->execute([
          $credit_note_id, $glAccounts['CN-001']['id'], $total_amount, 0,
          "Credit Note $credit_note_number - Revenue Reversal", $_SESSION['user_id'] ?? null
        ]);
      }
      
      // Credit Credit Notes Payable (Liability)
      if (isset($glAccounts['CN-002'])) {
        $glIns->execute([
          $credit_note_id, $glAccounts['CN-002']['id'], 0, $total_amount,
          "Credit Note $credit_note_number - Revenue Reversal", $_SESSION['user_id'] ?? null
        ]);
      }
      
      // Update status to issued
      $conn->prepare("UPDATE credit_notes SET status = 'issued', issued_at = NOW() WHERE id = ?")
           ->execute([$credit_note_id]);
    }
    
    $conn->commit();
    
    if ($_POST['status'] === 'issued') {
      $msg = "Credit note created and issued successfully.";
    } else {
      $msg = "Credit note created as draft.";
    }
    
    // Redirect to credit note view
    header("Location: credit_note_view.php?id=$credit_note_id");
    exit;
    
  } catch (Exception $e) {
    $conn->rollback();
    $err = "Failed to create credit note: " . $e->getMessage();
  }
}

// Get clients for dropdown
$clients = [];
$clientQ = $conn->prepare("SELECT id, client_name FROM client WHERE is_active = 1 ORDER BY client_name");
$clientQ->execute();
while ($row = $clientQ->fetch(PDO::FETCH_ASSOC)) {
  $clients[] = $row;
}

function h($str) { return htmlspecialchars($str, ENT_QUOTES, 'UTF-8'); }
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Create Credit Note | BMSystem</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
  <style>
    .item-row { border: 1px solid #dee2e6; border-radius: 0.375rem; padding: 1rem; margin-bottom: 0.5rem; }
    .item-row:last-child { margin-bottom: 0; }
    .totals-section { background-color: #f8f9fa; border-radius: 0.375rem; padding: 1rem; }
    .required { color: #dc3545; }
  </style>
</head>
<body class="bg-light">
<div class="container my-4">
  <div class="row">
    <div class="col-12">
      <div class="d-flex justify-content-between align-items-center mb-4">
        <h2><i class="bi bi-receipt"></i> Create Credit Note</h2>
        <a href="account.php?tab=invoices" class="btn btn-outline-secondary">
          <i class="bi bi-arrow-left"></i> Back to Invoices
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

      <form method="POST" id="creditNoteForm">
        <div class="row">
          <div class="col-md-8">
            <div class="card">
              <div class="card-header">
                <h5 class="mb-0">Credit Note Details</h5>
              </div>
              <div class="card-body">
                <div class="row">
                  <div class="col-md-6">
                    <div class="mb-3">
                      <label for="client_id" class="form-label">Client <span class="required">*</span></label>
                      <select class="form-select" id="client_id" name="client_id" required>
                        <option value="">Select Client</option>
                        <?php foreach ($clients as $client): ?>
                        <option value="<?= $client['id'] ?>" <?= ($invoice && $invoice['client_id'] == $client['id']) ? 'selected' : '' ?>>
                          <?= h($client['client_name']) ?>
                        </option>
                        <?php endforeach; ?>
                      </select>
                    </div>
                  </div>
                  <div class="col-md-6">
                    <div class="mb-3">
                      <label for="credit_note_date" class="form-label">Credit Note Date <span class="required">*</span></label>
                      <input type="date" class="form-control" id="credit_note_date" name="credit_note_date" 
                             value="<?= h($credit_note_date ?? date('Y-m-d')) ?>" required>
                    </div>
                  </div>
                </div>

                <div class="row">
                  <div class="col-md-6">
                    <div class="mb-3">
                      <label for="reference" class="form-label">Reference</label>
                      <input type="text" class="form-control" id="reference" name="reference" 
                             value="<?= h($reference ?? '') ?>" placeholder="Optional reference">
                    </div>
                  </div>
                  <div class="col-md-6">
                    <div class="mb-3">
                      <label for="reason" class="form-label">Reason <span class="required">*</span></label>
                      <select class="form-select" id="reason" name="reason" required>
                        <option value="return" <?= ($reason ?? '') === 'return' ? 'selected' : '' ?>>Return</option>
                        <option value="discount" <?= ($reason ?? '') === 'discount' ? 'selected' : '' ?>>Discount</option>
                        <option value="error" <?= ($reason ?? '') === 'error' ? 'selected' : '' ?>>Error</option>
                        <option value="adjustment" <?= ($reason ?? '') === 'adjustment' ? 'selected' : '' ?>>Adjustment</option>
                        <option value="other" <?= ($reason ?? '') === 'other' ? 'selected' : '' ?>>Other</option>
                      </select>
                    </div>
                  </div>
                </div>

                <div class="mb-3">
                  <label for="reason_description" class="form-label">Reason Description</label>
                  <textarea class="form-control" id="reason_description" name="reason_description" rows="2" 
                            placeholder="Describe the reason for this credit note"><?= h($reason_description ?? '') ?></textarea>
                </div>

                <div class="mb-3">
                  <label for="notes" class="form-label">Notes</label>
                  <textarea class="form-control" id="notes" name="notes" rows="2" 
                            placeholder="Additional notes"><?= h($notes ?? '') ?></textarea>
                </div>

                <?php if ($invoice): ?>
                <div class="mb-3">
                  <div class="alert alert-info">
                    <i class="bi bi-info-circle"></i> 
                    <strong>Creating credit note from invoice:</strong> <?= h($invoice['invoice_no']) ?>
                    <input type="hidden" name="invoice_id" value="<?= $invoice['id'] ?>">
                  </div>
                </div>
                <?php endif; ?>
              </div>
            </div>

            <!-- Line Items -->
            <div class="card mt-4">
              <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="mb-0">Line Items</h5>
                <button type="button" class="btn btn-sm btn-outline-primary" id="addItem">
                  <i class="bi bi-plus"></i> Add Item
                </button>
              </div>
              <div class="card-body">
                <div id="itemsContainer">
                  <!-- Items will be added here dynamically -->
                </div>
              </div>
            </div>
          </div>

          <div class="col-md-4">
            <div class="card">
              <div class="card-header">
                <h5 class="mb-0">Totals</h5>
              </div>
              <div class="card-body totals-section">
                <div class="row mb-2">
                  <div class="col-6">Subtotal:</div>
                  <div class="col-6 text-end" id="subtotal">AED 0.00</div>
                </div>
                <div class="row mb-2">
                  <div class="col-6">Tax:</div>
                  <div class="col-6 text-end" id="taxAmount">AED 0.00</div>
                </div>
                <hr>
                <div class="row">
                  <div class="col-6"><strong>Total:</strong></div>
                  <div class="col-6 text-end"><strong id="totalAmount">AED 0.00</strong></div>
                </div>
              </div>
            </div>

            <div class="card mt-3">
              <div class="card-header">
                <h5 class="mb-0">Actions</h5>
              </div>
              <div class="card-body">
                <div class="d-grid gap-2">
                  <button type="submit" name="status" value="draft" class="btn btn-outline-secondary">
                    <i class="bi bi-save"></i> Save as Draft
                  </button>
                  <button type="submit" name="status" value="issued" class="btn btn-primary">
                    <i class="bi bi-check-circle"></i> Create & Issue
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
let itemCount = 0;

function addItem() {
  itemCount++;
  const container = document.getElementById('itemsContainer');
  const itemHtml = `
    <div class="item-row" id="item-${itemCount}">
      <div class="row">
        <div class="col-md-6">
          <label class="form-label">Description</label>
          <input type="text" class="form-control" name="items[${itemCount}][description]" required>
        </div>
        <div class="col-md-2">
          <label class="form-label">Qty</label>
          <input type="number" class="form-control item-qty" name="items[${itemCount}][quantity]" 
                 value="1" min="0" step="0.001" onchange="calculateTotals()">
        </div>
        <div class="col-md-2">
          <label class="form-label">Unit Price</label>
          <input type="number" class="form-control item-price" name="items[${itemCount}][unit_price]" 
                 value="0" min="0" step="0.01" onchange="calculateTotals()">
        </div>
        <div class="col-md-1">
          <label class="form-label">Tax %</label>
          <input type="number" class="form-control item-tax" name="items[${itemCount}][tax_rate]" 
                 value="5" min="0" max="100" step="0.01" onchange="calculateTotals()">
        </div>
        <div class="col-md-1">
          <label class="form-label">&nbsp;</label>
          <button type="button" class="btn btn-sm btn-outline-danger d-block" onclick="removeItem(${itemCount})">
            <i class="bi bi-trash"></i>
          </button>
        </div>
      </div>
    </div>
  `;
  container.insertAdjacentHTML('beforeend', itemHtml);
  calculateTotals();
}

function removeItem(itemId) {
  document.getElementById(`item-${itemId}`).remove();
  calculateTotals();
}

function calculateTotals() {
  let subtotal = 0;
  let taxAmount = 0;
  
  document.querySelectorAll('.item-row').forEach(row => {
    const qty = parseFloat(row.querySelector('.item-qty').value) || 0;
    const price = parseFloat(row.querySelector('.item-price').value) || 0;
    const taxRate = parseFloat(row.querySelector('.item-tax').value) || 0;
    
    const lineTotal = qty * price;
    const itemTax = lineTotal * (taxRate / 100);
    
    subtotal += lineTotal;
    taxAmount += itemTax;
  });
  
  const total = subtotal + taxAmount;
  
  document.getElementById('subtotal').textContent = `AED ${subtotal.toFixed(2)}`;
  document.getElementById('taxAmount').textContent = `AED ${taxAmount.toFixed(2)}`;
  document.getElementById('totalAmount').textContent = `AED ${total.toFixed(2)}`;
}

// Add first item on page load
document.addEventListener('DOMContentLoaded', function() {
  addItem();
});

document.getElementById('addItem').addEventListener('click', addItem);

// Form validation
document.getElementById('creditNoteForm').addEventListener('submit', function(e) {
  const items = document.querySelectorAll('.item-row');
  if (items.length === 0) {
    e.preventDefault();
    alert('Please add at least one line item');
    return;
  }
  
  let hasValidItem = false;
  items.forEach(item => {
    const description = item.querySelector('input[name*="[description]"]').value.trim();
    const qty = parseFloat(item.querySelector('.item-qty').value) || 0;
    if (description && qty > 0) {
      hasValidItem = true;
    }
  });
  
  if (!hasValidItem) {
    e.preventDefault();
    alert('Please add at least one valid line item');
  }
});
</script>
</body>
</html>
