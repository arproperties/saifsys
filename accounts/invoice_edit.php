<?php
require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/../includes/db_connect.php';
require_once __DIR__.'/../includes/ar_helpers.php';
require_once __DIR__.'/../includes/accounting_health_service.php';
require_once __DIR__.'/../includes/permissions.php';
require_once __DIR__.'/../includes/work_order_financial_guard.php';

// Check permission (backward compatible: if no permission but has role, allow)
if (!has_permission('invoices.edit', MODULE_FINANCE, $conn)) {
    require_role(['Owner','Admin','Account'], $conn);
}

$invoice_id = (int)($_GET['id'] ?? 0);
if ($invoice_id <= 0) {
  header("Location: ../account.php?tab=invoices");
  exit;
}

$msg = $err = '';

// Load invoice data
$invoice = ar_get_invoice($conn, $invoice_id);
if (!$invoice) {
  header("Location: ../account.php?tab=invoices");
  exit;
}

// Check if invoice can be edited
$editable_statuses = ['draft', 'issued', 'partially_paid'];
if (!in_array($invoice['status'], $editable_statuses)) {
  $err = "This invoice cannot be edited. Current status: " . ucfirst(str_replace('_', ' ', $invoice['status']));
}

$opsInvoiceLock = wo_invoice_from_operations_locked($conn, $invoice_id);
if (empty($err) && $opsInvoiceLock['locked']) {
  $err = $opsInvoiceLock['reason'];
}

// CRITICAL: Warn if invoice has payments - editing can cause GL discrepancies
$hasPayments = false;
if (empty($err)) {
  $paymentCheck = $conn->prepare("SELECT COUNT(*) FROM receipt_allocations WHERE invoice_id = ?");
  $paymentCheck->execute([$invoice_id]);
  $hasPayments = (int)$paymentCheck->fetchColumn() > 0;
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($err)) {
  try {
    $client_id = (int)($_POST['client_id'] ?? 0);
    $issue_date = $_POST['issue_date'] ?? date('Y-m-d');
    $due_date = $_POST['due_date'] ?? '';
    $terms = $_POST['terms'] ?? '30d';
    $vat_rate = (float)($_POST['vat_rate'] ?? 5.0);
    $discount_amount = (float)($_POST['discount_amount'] ?? 0);
    $notes = trim($_POST['notes'] ?? '');
    $items = $_POST['items'] ?? [];
    $status = $_POST['status'] ?? $invoice['status'];
    
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
    
    $conn->beginTransaction();
    
    // Store original data for audit
    $original_data = [
      'client_id' => $invoice['client_id'],
      'issue_date' => $invoice['issue_date'],
      'due_date' => $invoice['due_date'],
      'terms' => $invoice['terms'],
      'vat_rate' => $invoice['vat_rate'],
      'discount_amount' => $invoice['discount_amount'],
      'notes' => $invoice['notes'],
      'status' => $invoice['status']
    ];
    
    // Update invoice header
    $upd = $conn->prepare("
      UPDATE invoices
      SET client_id = ?, issue_date = ?, due_date = ?, terms = ?,
          vat_rate = ?, discount_amount = ?, notes = ?, status = ?, updated_at = NOW()
      WHERE id = ?
    ");
    $upd->execute([
      $client_id, $issue_date, $due_date, $terms,
      $vat_rate, $discount_amount, $notes, $status, $invoice_id
    ]);
    
    // Delete existing line items
    $conn->prepare("DELETE FROM invoice_items WHERE invoice_id = ?")->execute([$invoice_id]);
    
    // Process new line items
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
    
    // Keep paid/balance/status derived from allocations before GL reposting.
    if ($hasPayments || $status !== 'draft') {
      ar_refresh_status_from_allocations($conn, $invoice_id);
      $statusRow = $conn->prepare("SELECT status FROM invoices WHERE id = ?");
      $statusRow->execute([$invoice_id]);
      $status = (string)$statusRow->fetchColumn();
    }

    // Post to GL if status is not draft
    if ($status !== 'draft') {
      ar_post_or_repost_invoice($conn, $invoice_id);
    }

    accounting_health_assert_invoice($conn, $invoice_id);
    
    // Audit Log: Track invoice edit
    require_once __DIR__ . '/../includes/AuditService.php';
    
    // Get new values for comparison
    $new_data = [
      'client_id' => $client_id,
      'issue_date' => $issue_date,
      'due_date' => $due_date,
      'terms' => $terms,
      'vat_rate' => $vat_rate,
      'discount_amount' => $discount_amount,
      'notes' => $notes,
      'status' => $status,
      'subtotal' => $subtotal,
      'vat_amount' => $vat_amount,
      'total' => $total
    ];
    
    // Always log the update - don't check for changes since we just made changes
    try {
      AuditService::logUpdate(
        'invoices',
        (string)$invoice_id,
        $original_data,
        $new_data,
        "Updated invoice INV-{$invoice['invoice_no']}"
      );
    } catch (Throwable $e) {
      // Don't fail the update if audit logging fails
      error_log("Audit log failed: " . $e->getMessage());
    }
    
    $conn->commit();
    
    // Refresh invoice data
    $invoice = ar_get_invoice($conn, $invoice_id);
    $msg = "Invoice updated successfully!";
    
  } catch (Throwable $e) {
    if ($conn->inTransaction()) {
      $conn->rollBack();
    }
    $err = "Error updating invoice: " . $e->getMessage();
  }
}

// Load clients for dropdown
$clients = $conn->query("SELECT id, client_name, terms FROM client ORDER BY client_name")->fetchAll(PDO::FETCH_ASSOC);

// Load invoice items
$items = ar_get_invoice_items($conn, $invoice_id);
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Edit Invoice <?= h($invoice['invoice_no']) ?> | BMSystem</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
  <style>
    .line-item { border: 1px solid #dee2e6; border-radius: 8px; padding: 15px; margin-bottom: 15px; }
    .totals-section { background: #f8f9fa; border-radius: 8px; padding: 20px; }
    .audit-log { max-height: 300px; overflow-y: auto; }
    .status-badge { font-size: 0.875rem; }
  </style>
</head>
<body class="bg-light">
<div class="container my-4">
  <div class="d-flex align-items-center mb-4">
    <a href="../account.php?tab=invoices" class="btn btn-outline-secondary me-3">
      <i class="bi bi-arrow-left"></i> Back to Invoices
    </a>
    <h2 class="mb-0">Edit Invoice <?= h($invoice['invoice_no']) ?></h2>
    <div class="ms-auto">
      <span class="badge status-badge text-bg-<?= $invoice['status'] === 'paid' ? 'success' : ($invoice['status'] === 'partially_paid' ? 'warning' : ($invoice['status'] === 'void' ? 'secondary' : 'info')) ?>">
        <?= ucfirst(str_replace('_', ' ', $invoice['status'])) ?>
      </span>
    </div>
  </div>

  <?php if($msg): ?><div class="alert alert-success"><?= h($msg) ?></div><?php endif; ?>
  <?php if($err): ?><div class="alert alert-danger"><?= h($err) ?></div><?php endif; ?>
  <?php if($hasPayments && empty($err)): ?>
    <div class="alert alert-warning">
      <i class="bi bi-exclamation-triangle"></i> <strong>Warning:</strong> This invoice has payments recorded. 
      Editing may cause discrepancies in the General Ledger. The system will automatically reverse and repost the journal entry, 
      but please verify the Trial Balance and AR Ageing reports after editing.
    </div>
  <?php endif; ?>

  <?php if (empty($err)): ?>
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
                    <option value="<?= $c['id'] ?>" data-terms="<?= h($c['terms']) ?>" <?= $c['id'] == $invoice['client_id'] ? 'selected' : '' ?>>
                      <?= h($c['client_name']) ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="col-md-3">
                <label class="form-label">Issue Date</label>
                <input type="date" class="form-control" name="issue_date" value="<?= h($invoice['issue_date']) ?>" required>
              </div>
              <div class="col-md-3">
                <label class="form-label">Due Date</label>
                <input type="date" class="form-control" name="due_date" id="dueDate" value="<?= h($invoice['due_date']) ?>">
              </div>
              <div class="col-md-4">
                <label class="form-label">Payment Terms</label>
                <select class="form-select" name="terms" id="termsSelect">
                  <option value="cash" <?= $invoice['terms'] === 'cash' ? 'selected' : '' ?>>Cash</option>
                  <option value="15d" <?= $invoice['terms'] === '15d' ? 'selected' : '' ?>>15 Days</option>
                  <option value="30d" <?= $invoice['terms'] === '30d' ? 'selected' : '' ?>>30 Days</option>
                  <option value="45d" <?= $invoice['terms'] === '45d' ? 'selected' : '' ?>>45 Days</option>
                  <option value="60d" <?= $invoice['terms'] === '60d' ? 'selected' : '' ?>>60 Days</option>
                </select>
              </div>
              <div class="col-md-4">
                <label class="form-label">VAT Rate (%)</label>
                <input type="number" step="0.01" class="form-control" name="vat_rate" value="<?= h($invoice['vat_rate']) ?>" id="vatRate">
              </div>
              <div class="col-md-4">
                <label class="form-label">Discount Amount (AED)</label>
                <input type="number" step="0.01" class="form-control" name="discount_amount" value="<?= h($invoice['discount_amount']) ?>" id="discountAmount">
              </div>
              <div class="col-12">
                <label class="form-label">Notes</label>
                <textarea class="form-control" name="notes" rows="2"><?= h($invoice['notes']) ?></textarea>
              </div>
              <div class="col-md-6">
                <label class="form-label">Status</label>
                <select class="form-select" name="status" id="statusSelect">
                  <option value="draft" <?= $invoice['status'] === 'draft' ? 'selected' : '' ?>>Draft</option>
                  <option value="issued" <?= $invoice['status'] === 'issued' ? 'selected' : '' ?>>Issued</option>
                  <option value="partially_paid" <?= $invoice['status'] === 'partially_paid' ? 'selected' : '' ?>>Partially Paid</option>
                  <option value="paid" <?= $invoice['status'] === 'paid' ? 'selected' : '' ?>>Paid</option>
                  <option value="void" <?= $invoice['status'] === 'void' ? 'selected' : '' ?>>Void</option>
                </select>
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
          <button type="submit" class="btn btn-success w-100 mb-2">
            <i class="bi bi-check-circle"></i> Update Invoice
          </button>
          <a href="invoice_view.php?id=<?= $invoice_id ?>" class="btn btn-outline-secondary w-100">
            <i class="bi bi-eye"></i> View Invoice
          </a>
        </div>

        <!-- Audit Log -->
        <div class="card shadow-sm mt-3">
          <div class="card-header">
            <h6 class="mb-0">Recent Changes</h6>
          </div>
          <div class="card-body">
            <div class="audit-log" id="auditLog">
              <div class="text-muted text-center">Loading audit log...</div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </form>
  <?php endif; ?>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
let lineItemCount = 0;

// Load existing line items
document.addEventListener('DOMContentLoaded', function() {
  loadExistingItems();
  updateTotals();
  loadAuditLog();
});

function loadExistingItems() {
  const items = <?= json_encode($items) ?>;
  
  items.forEach((item, index) => {
    lineItemCount++;
    const container = document.getElementById('lineItems');
    
    const lineItem = document.createElement('div');
    lineItem.className = 'line-item';
    lineItem.innerHTML = `
      <div class="row g-2">
        <div class="col-md-5">
          <input type="text" class="form-control" name="items[${lineItemCount}][description]" 
                 value="${item.description}" placeholder="Description" required>
        </div>
        <div class="col-md-2">
          <input type="number" step="0.01" class="form-control qty" name="items[${lineItemCount}][qty]" 
                 value="${item.qty}" placeholder="Qty" min="0" onchange="updateTotals()" required>
        </div>
        <div class="col-md-2">
          <input type="text" class="form-control" name="items[${lineItemCount}][unit]" 
                 value="${item.unit}" placeholder="Unit">
        </div>
        <div class="col-md-2">
          <input type="number" step="0.01" class="form-control unit-price" name="items[${lineItemCount}][unit_price]" 
                 value="${item.unit_price}" placeholder="Price" min="0" onchange="updateTotals()" required>
        </div>
        <div class="col-md-1">
          <button type="button" class="btn btn-outline-danger btn-sm" onclick="removeLineItem(this)">
            <i class="bi bi-trash"></i>
          </button>
        </div>
      </div>
    `;
    
    container.appendChild(lineItem);
  });
}

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

// Load audit log
function loadAuditLog() {
  console.log('Loading audit log for invoice <?= $invoice_id ?>');
  fetch(`ajax_invoice_audit.php?id=<?= $invoice_id ?>`)
    .then(response => {
      console.log('Response status:', response.status);
      return response.json();
    })
    .then(data => {
      console.log('Audit log data received:', data);
      if (data.success) {
        const auditLog = document.getElementById('auditLog');
        if (data.audit_logs && data.audit_logs.length > 0) {
          console.log('Found ' + data.audit_logs.length + ' audit log entries');
          auditLog.innerHTML = data.audit_logs.map(log => {
            // Format the date
            const date = new Date(log.created_at);
            const formattedDate = date.toLocaleString('en-US', {
              month: 'short',
              day: 'numeric',
              year: 'numeric',
              hour: 'numeric',
              minute: '2-digit',
              hour12: true
            });
            
            return `
            <div class="border-bottom pb-2 mb-2">
              <div class="small text-muted">${formattedDate}</div>
              <div class="fw-semibold">${log.summary || log.action || 'Audit log entry'}</div>
              <div class="small text-muted">by ${log.user_name || 'System'}</div>
            </div>
          `;
          }).join('');
        } else {
          console.log('No audit log entries found');
          auditLog.innerHTML = '<div class="text-muted text-center">No recent changes</div>';
        }
      } else {
        console.error('Audit log error:', data.error);
        document.getElementById('auditLog').innerHTML = '<div class="text-danger small">Failed to load audit log: ' + (data.error || 'Unknown error') + '</div>';
      }
    })
    .catch(error => {
      console.error('Audit log fetch error:', error);
      document.getElementById('auditLog').innerHTML = '<div class="text-danger small">Failed to load audit log: ' + error.message + '</div>';
    });
}
</script>
</body>
</html>
