<?php
require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/../includes/db_connect.php';
require_once __DIR__.'/../includes/ar_helpers.php';
require_once __DIR__.'/../includes/accounting_health_service.php';
require_once __DIR__.'/../includes/company_helper.php';
require_once __DIR__.'/../includes/permissions.php';

// Check permission (backward compatible: if no permission but has role, allow)
if (!has_permission('invoices.create', MODULE_FINANCE, $conn)) {
    require_role(['Owner','Admin','Account'], $conn);
}

// Get current company context
$currentCompanyId = current_company_id($conn) ?: 1;

$msg = $err = '';
$invoice_id = null;

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  try {
    $client_id = (int)($_POST['client_id'] ?? 0);
    $issue_date = $_POST['issue_date'] ?? date('Y-m-d');
    $due_date = $_POST['due_date'] ?? '';
    $terms = $_POST['terms'] ?? '30d';
    $vat_rate = (float)($_POST['vat_rate'] ?? 5.0);
    $discount_amount = (float)($_POST['discount_amount'] ?? 0);
    $notes = trim($_POST['notes'] ?? '');
    $items = $_POST['items'] ?? [];
    $status = (($_POST['status'] ?? 'draft') === 'draft') ? 'draft' : 'issued';
    
    if ($client_id <= 0) {
      throw new Exception('Please select a client');
    }
    
    if (empty($items) || !is_array($items)) {
      throw new Exception('Please add at least one line item');
    }
    
    // Calculate due date if not provided
    if (empty($due_date)) {
      $due_date = ar_compute_due_date($issue_date, $terms);
    }
    
    // Generate invoice number (standardized to 6 digits to match order-based invoices)
    $year = date('Y');
    $maxQ = $conn->prepare("SELECT MAX(CAST(SUBSTRING_INDEX(invoice_no,'-',-1) AS UNSIGNED)) FROM invoices WHERE invoice_no LIKE ?");
    $maxQ->execute(["INV-$year-%"]);
    $next = (int)($maxQ->fetchColumn() ?: 0) + 1;
    $invoice_no = sprintf("INV-%s-%06d", $year, $next); // Changed from %05d to %06d for consistency
    
    $conn->beginTransaction();
    
    // Insert invoice header
    $ins = $conn->prepare("
      INSERT INTO invoices
        (company_id, client_id, invoice_no, issue_date, due_date, terms, currency,
         subtotal, discount_amount, vat_rate, vat_amount, rounding, total,
         status, notes, created_at, created_by)
      VALUES
        (?, ?, ?, ?, ?, ?, 'AED', 0, ?, ?, 0, 0, 0, ?, ?, NOW(), ?)
    ");
    $ins->execute([
      $currentCompanyId, $client_id, $invoice_no, $issue_date, $due_date, $terms,
      $discount_amount, $vat_rate, $status, $notes, $_SESSION['user_id'] ?? null
    ]);
    $invoice_id = (int)$conn->lastInsertId();
    
    // Process line items
    $line_no = 0;
    $subtotal = 0.0;
    
    foreach ($items as $item) {
      if (empty($item['description']) || (float)($item['qty'] ?? 0) <= 0) continue;
      
      $line_no++;
      $qty = (float)($item['qty'] ?? 0);
      $unit_price = (float)($item['unit_price'] ?? 0);
      $line_subtotal = round($qty * $unit_price, 2);
      $subtotal += $line_subtotal;
      
      $insItem = $conn->prepare("
        INSERT INTO invoice_items
          (invoice_id, line_no, description, qty, unit, unit_price,
           line_subtotal, vat_rate, vat_value, line_total)
        VALUES
          (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
      ");
      
      $item_vat_rate = (float)($item['vat_rate'] ?? $vat_rate);
      $vat_value = round($line_subtotal * $item_vat_rate / 100, 2);
      $line_total = round($line_subtotal + $vat_value, 2);
      
      $insItem->execute([
        $invoice_id, $line_no, $item['description'], $qty, $item['unit'] ?? 'ea',
        $unit_price, $line_subtotal, $item_vat_rate, $vat_value, $line_total
      ]);
    }
    
    // Calculate totals
    $vat_amount = round($subtotal * $vat_rate / 100, 2);
    $total = round($subtotal - $discount_amount + $vat_amount, 2);
    
    // Update invoice totals
    $up = $conn->prepare("
      UPDATE invoices 
      SET subtotal = ?, vat_amount = ?, total = ?, updated_at = NOW()
      WHERE id = ?
    ");
    $up->execute([$subtotal, $vat_amount, $total, $invoice_id]);
    
    // Post to GL if status is not draft
    if ($status !== 'draft') {
      ar_post_or_repost_invoice($conn, $invoice_id);
    }

    accounting_health_assert_invoice($conn, $invoice_id);

    $conn->commit();
    
    $msg = "Invoice created successfully! Invoice #: " . $invoice_no;
    
  } catch (Throwable $e) {
    if ($conn->inTransaction()) {
      $conn->rollBack();
    }
    $err = "Error creating invoice: " . $e->getMessage();
  }
}

// Load clients for dropdown
$clients = $conn->query("SELECT id, client_name, terms FROM client ORDER BY client_name")->fetchAll(PDO::FETCH_ASSOC);
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Create Invoice | BMSystem</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
  <style>
    .line-item { border: 1px solid #dee2e6; border-radius: 8px; padding: 15px; margin-bottom: 15px; }
    .totals-section { background: #f8f9fa; border-radius: 8px; padding: 20px; }
  </style>
</head>
<body class="bg-light">
<div class="container my-4">
  <div class="d-flex align-items-center mb-4">
    <a href="../account.php?tab=invoices" class="btn btn-outline-secondary me-3">
      <i class="bi bi-arrow-left"></i> Back to Invoices
    </a>
    <h2 class="mb-0">Create New Invoice</h2>
  </div>

  <?php if($msg): ?><div class="alert alert-success"><?= h($msg) ?></div><?php endif; ?>
  <?php if($err): ?><div class="alert alert-danger"><?= h($err) ?></div><?php endif; ?>

  <form method="post" id="invoiceForm">
    <div class="row">
      <div class="col-lg-8">
        <div class="card shadow-sm">
          <div class="card-header">
            <h5 class="mb-0">Invoice Details</h5>
          </div>
          <div class="card-body">
            <div class="row g-3">
              <div class="col-md-6">
                <label class="form-label">Client <span class="text-danger">*</span></label>
                <select class="form-select" name="client_id" id="clientSelect" required>
                  <option value="">Select Client</option>
                  <?php foreach ($clients as $c): ?>
                    <option value="<?= $c['id'] ?>" data-terms="<?= h($c['terms']) ?>">
                      <?= h($c['client_name']) ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="col-md-3">
                <label class="form-label">Issue Date</label>
                <input type="date" class="form-control" name="issue_date" value="<?= date('Y-m-d') ?>" required>
              </div>
              <div class="col-md-3">
                <label class="form-label">Due Date</label>
                <input type="date" class="form-control" name="due_date" id="dueDate">
              </div>
              <div class="col-md-4">
                <label class="form-label">Payment Terms</label>
                <select class="form-select" name="terms" id="termsSelect">
                  <option value="cash">Cash</option>
                  <option value="15d">15 Days</option>
                  <option value="30d" selected>30 Days</option>
                  <option value="45d">45 Days</option>
                  <option value="60d">60 Days</option>
                </select>
              </div>
              <div class="col-md-4">
                <label class="form-label">VAT Rate (%)</label>
                <input type="number" step="0.01" class="form-control" name="vat_rate" value="5.00" id="vatRate">
              </div>
              <div class="col-md-4">
                <label class="form-label">Discount Amount (AED)</label>
                <input type="number" step="0.01" class="form-control" name="discount_amount" value="0.00" id="discountAmount">
              </div>
              <div class="col-12">
                <label class="form-label">Notes</label>
                <textarea class="form-control" name="notes" rows="2"></textarea>
              </div>
            </div>
          </div>
        </div>

        <div class="card shadow-sm mt-3">
          <div class="card-header d-flex justify-content-between align-items-center">
            <h5 class="mb-0">Line Items</h5>
            <button type="button" class="btn btn-primary btn-sm" onclick="addLineItem()">
              <i class="bi bi-plus"></i> Add Item
            </button>
          </div>
          <div class="card-body">
            <div id="lineItems">
              <!-- Line items will be added here dynamically -->
            </div>
          </div>
        </div>
      </div>

      <div class="col-lg-4">
        <div class="totals-section">
          <h5 class="mb-3">Invoice Totals</h5>
          <div class="d-flex justify-content-between mb-2">
            <span>Subtotal:</span>
            <span id="subtotal">AED 0.00</span>
          </div>
          <div class="d-flex justify-content-between mb-2">
            <span>Discount:</span>
            <span id="discount">AED 0.00</span>
          </div>
          <div class="d-flex justify-content-between mb-2">
            <span>VAT:</span>
            <span id="vat">AED 0.00</span>
          </div>
          <hr>
          <div class="d-flex justify-content-between fw-bold fs-5">
            <span>Total:</span>
            <span id="total">AED 0.00</span>
          </div>
        </div>

        <div class="mt-3">
          <button type="submit" name="status" value="draft" class="btn btn-outline-secondary w-100 mb-2">
            <i class="bi bi-save"></i> Save as Draft
          </button>
          <button type="submit" name="status" value="issued" class="btn btn-success w-100">
            <i class="bi bi-check-circle"></i> Create & Issue Invoice
          </button>
        </div>
      </div>
    </div>
  </form>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
let lineItemCount = 0;

// Add initial line item
document.addEventListener('DOMContentLoaded', function() {
  addLineItem();
  updateTotals();
});

function addLineItem() {
  lineItemCount++;
  const container = document.getElementById('lineItems');
  
  const lineItem = document.createElement('div');
  lineItem.className = 'line-item';
  lineItem.innerHTML = `
    <div class="row g-2">
      <div class="col-md-5">
        <input type="text" class="form-control" name="items[${lineItemCount}][description]" placeholder="Description" required>
      </div>
      <div class="col-md-2">
        <input type="number" step="0.01" class="form-control qty" name="items[${lineItemCount}][qty]" placeholder="Qty" min="0" onchange="updateTotals()" required>
      </div>
      <div class="col-md-2">
        <input type="text" class="form-control" name="items[${lineItemCount}][unit]" placeholder="Unit" value="ea">
      </div>
      <div class="col-md-2">
        <input type="number" step="0.01" class="form-control unit-price" name="items[${lineItemCount}][unit_price]" placeholder="Price" min="0" onchange="updateTotals()" required>
      </div>
      <div class="col-md-1">
        <button type="button" class="btn btn-outline-danger btn-sm" onclick="removeLineItem(this)">
          <i class="bi bi-trash"></i>
        </button>
      </div>
    </div>
  `;
  
  container.appendChild(lineItem);
}

function removeLineItem(button) {
  button.closest('.line-item').remove();
  updateTotals();
}

function updateTotals() {
  let subtotal = 0;
  const vatRate = parseFloat(document.getElementById('vatRate').value) || 0;
  const discountAmount = parseFloat(document.getElementById('discountAmount').value) || 0;
  
  // Calculate subtotal from line items
  document.querySelectorAll('.line-item').forEach(item => {
    const qty = parseFloat(item.querySelector('.qty').value) || 0;
    const unitPrice = parseFloat(item.querySelector('.unit-price').value) || 0;
    subtotal += qty * unitPrice;
  });
  
  const vatAmount = subtotal * vatRate / 100;
  const total = subtotal - discountAmount + vatAmount;
  
  document.getElementById('subtotal').textContent = 'AED ' + subtotal.toFixed(2);
  document.getElementById('discount').textContent = 'AED ' + discountAmount.toFixed(2);
  document.getElementById('vat').textContent = 'AED ' + vatAmount.toFixed(2);
  document.getElementById('total').textContent = 'AED ' + total.toFixed(2);
}

// Auto-calculate due date when terms change
document.getElementById('termsSelect').addEventListener('change', function() {
  const issueDate = document.querySelector('input[name="issue_date"]').value;
  const terms = this.value;
  
  if (issueDate && terms !== 'cash') {
    const date = new Date(issueDate);
    const days = terms === '15d' ? 15 : terms === '30d' ? 30 : terms === '45d' ? 45 : 60;
    date.setDate(date.getDate() + days);
    document.getElementById('dueDate').value = date.toISOString().split('T')[0];
  }
});

// Update totals when VAT rate or discount changes
document.getElementById('vatRate').addEventListener('input', updateTotals);
document.getElementById('discountAmount').addEventListener('input', updateTotals);

// Auto-fill terms from client selection
document.getElementById('clientSelect').addEventListener('change', function() {
  const selectedOption = this.options[this.selectedIndex];
  const clientTerms = selectedOption.getAttribute('data-terms');
  
  if (clientTerms) {
    document.getElementById('termsSelect').value = clientTerms;
    // Trigger change event to update due date
    document.getElementById('termsSelect').dispatchEvent(new Event('change'));
  }
});
</script>
</body>
</html>
