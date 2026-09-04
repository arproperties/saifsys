<?php
// accounts/payments.php
require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/../includes/db_connect.php';
require_once __DIR__.'/../includes/ar_helpers.php';
require_once __DIR__.'/../includes/advanced_search_service.php';
require_once __DIR__.'/../includes/company_helper.php';
require_once __DIR__.'/../includes/module_access.php';
require_once __DIR__.'/../includes/permissions.php';
require_once __DIR__.'/../includes/cleaning_payment_accounts.php';

// Check permission (backward compatible: if no permission but has role, allow)
if (!has_permission('payments.view', MODULE_FINANCE, $conn)) {
    require_role(['Owner','Admin','Account'], $conn);
}

$userRoles = current_user_roles($conn);
$canEditPayment = has_permission('payments.edit', MODULE_FINANCE, $conn)
    || count(array_intersect($userRoles, ['Owner', 'Admin', 'Account'])) > 0;
$canDeletePayment = in_array('Owner', $userRoles, true);

$cleaningPaymentAccountOptions = cleaning_payment_account_options($conn);

// Get current company context
$currentCompanyId = current_company_id($conn) ?: 1;

// ---------------- Advanced Search Filters ----------------
$search_query = trim($_GET['q'] ?? '');
$search_filters = [];
$allocation_state = trim((string)($_GET['allocation_state'] ?? ''));
if (!in_array($allocation_state, ['', 'allocated', 'partial', 'unallocated', 'broken'], true)) {
    $allocation_state = '';
}

// Parse search filters from URL parameters
// Check both 'date_from'/'date_to' and 'from_date'/'to_date' for compatibility
$from_date = trim($_GET['date_from'] ?? $_GET['from_date'] ?? '');
$to_date = trim($_GET['date_to'] ?? $_GET['to_date'] ?? '');
if ($from_date !== '') { $search_filters['date_from'] = $from_date; }
if ($to_date !== '') { $search_filters['date_to'] = $to_date; }

$amount_min = trim((string)($_GET['amount_min'] ?? ''));
$amount_max = trim((string)($_GET['amount_max'] ?? ''));
if ($amount_min !== '') { $search_filters['amount_min'] = $amount_min; }
if ($amount_max !== '') { $search_filters['amount_max'] = $amount_max; }

// Handle payment_method - can be string or array (including nested arrays from URL)
$payment_method = $_GET['payment_method'] ?? '';
if (is_array($payment_method)) {
    // Flatten nested arrays (e.g., [['bank']] becomes ['bank'])
    $flattened = [];
    array_walk_recursive($payment_method, function($value) use (&$flattened) {
        if (!empty($value) && is_string($value)) {
            $flattened[] = trim($value);
        }
    });
    $payment_method = array_filter(array_unique($flattened));
    if (!empty($payment_method)) {
        // If only one value, use string; otherwise use array for IN clause
        $search_filters['payment_method'] = count($payment_method) === 1 ? $payment_method[0] : $payment_method;
    }
} else {
    $payment_method = trim($payment_method);
    if ($payment_method !== '') { 
        $search_filters['payment_method'] = $payment_method; 
    }
}

// Build search query using AdvancedSearchService
$searchService = new AdvancedSearchService($conn);
$searchQuery = $searchService->buildPaymentSearchQuery(array_merge($search_filters, ['search' => $search_query]));

// Build WHERE
$where = ["1=1"];
$args = [];

// Company filter (always apply)
$where[] = "r.company_id = ?";
$args[] = $currentCompanyId;

// Add search conditions
if (!empty($searchQuery['where_clause'])) {
    // Remove "WHERE " prefix if present
    $where_clause = trim($searchQuery['where_clause']);
    if (stripos($where_clause, 'WHERE ') === 0) {
        $where_clause = trim(substr($where_clause, 6));
    }
    // Add the conditions (they're already joined with AND)
    if (!empty($where_clause)) {
        $where[] = "($where_clause)"; // Wrap in parentheses for safety
        $args = array_merge($args, $searchQuery['params']);
    }
}

// ---------------- Query ----------------
/**
 * We show one row per allocation (receipt -> invoice).
 * If a receipt has no allocations yet, we still show it with the
 * full receipt amount and an empty invoice column.
 */
$sql = "
  SELECT
      r.id              AS receipt_id,
      r.receipt_no,
      r.receipt_date    AS payment_date,
      r.method          AS payment_method,
      r.notes,
      r.amount          AS receipt_amount,
      r.deposit_account_no,
      COALESCE(rt.allocated_total, 0) AS allocated_total,
      ra.id             AS allocation_id,
      COALESCE(ra.amount_applied, r.amount) AS amount,   -- per-row amount
      i.id              AS invoice_id,
      ra.invoice_id     AS allocation_invoice_id,
      i.invoice_no,
      COALESCE(c.client_name, c2.client_name) AS client_name
  FROM receipts r
  LEFT JOIN receipt_allocations ra ON ra.receipt_id = r.id
  LEFT JOIN (
    SELECT receipt_id, ROUND(SUM(amount_applied), 2) AS allocated_total
    FROM receipt_allocations
    GROUP BY receipt_id
  ) rt ON rt.receipt_id = r.id
  LEFT JOIN invoices i            ON i.id = ra.invoice_id
  LEFT JOIN client   c            ON c.id = i.client_id          -- client via invoice
  LEFT JOIN client   c2           ON c2.id = r.client_id         -- fallback client on receipt
  WHERE ".implode(' AND ', $where)."
  ORDER BY r.receipt_date DESC, r.id DESC, ra.id DESC
  LIMIT 300
";

$st = $conn->prepare($sql);
try {
    $st->execute($args);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    // Log error for debugging
    error_log("Payment query error: " . $e->getMessage());
    error_log("SQL: " . $sql);
    error_log("Args: " . print_r($args, true));
    $rows = [];
}

foreach ($rows as &$row) {
    $receiptAmount = (float)($row['receipt_amount'] ?? 0);
    $allocatedTotal = (float)($row['allocated_total'] ?? 0);
    $hasAllocation = !empty($row['allocation_id']);
    $broken = $hasAllocation && !empty($row['allocation_invoice_id']) && empty($row['invoice_id']);
    if ($broken) {
        $row['allocation_state'] = 'broken';
        $row['allocation_state_label'] = 'Broken Allocation';
        $row['allocation_state_class'] = 'danger';
    } elseif ($allocatedTotal <= 0.005) {
        $row['allocation_state'] = 'unallocated';
        $row['allocation_state_label'] = 'Unallocated Credit';
        $row['allocation_state_class'] = 'warning';
    } elseif ($allocatedTotal + 0.005 < $receiptAmount) {
        $row['allocation_state'] = 'partial';
        $row['allocation_state_label'] = 'Partially Allocated';
        $row['allocation_state_class'] = 'info';
    } else {
        $row['allocation_state'] = 'allocated';
        $row['allocation_state_label'] = 'Allocated';
        $row['allocation_state_class'] = 'success';
    }
}
unset($row);

if ($allocation_state !== '') {
    $rows = array_values(array_filter($rows, static fn($row) => ($row['allocation_state'] ?? '') === $allocation_state));
}

// Calculate total of the listed rows
$total_listed = 0.0;
foreach ($rows as $r) $total_listed += (float)$r['amount'];

// Calculate payment method totals for the filtered results
$payment_method_totals = [];
$payment_methods = ['Cash', 'Card', 'Bank', 'Cheque'];
foreach ($payment_methods as $method) {
  $method_total = 0.0;
  foreach ($rows as $r) {
    // Case-insensitive comparison for payment method
    $row_method = trim($r['payment_method'] ?? '');
    if (strcasecmp($row_method, $method) === 0) {
      $method_total += (float)$r['amount'];
    }
  }
  $payment_method_totals[$method] = $method_total;
}

// Quick summary of clients with credit > 0 (current company)
$credit_clients = $conn->prepare("
  SELECT c.id, c.client_name,
         (SELECT COALESCE(SUM(amount),0) FROM receipts r WHERE r.client_id=c.id AND r.company_id = ?) -
         (SELECT COALESCE(SUM(ra.amount_applied),0)
            FROM receipt_allocations ra JOIN receipts r2 ON r2.id=ra.receipt_id
           WHERE r2.client_id=c.id AND r2.company_id = ?) AS credit
    FROM client c
   HAVING credit > 0
   ORDER BY credit DESC
   LIMIT 20
");
$credit_clients->execute([$currentCompanyId, $currentCompanyId]);
$credit_clients = $credit_clients->fetchAll(PDO::FETCH_ASSOC);

?>
<form method="get" class="row g-2 align-items-end mb-3">
  <?php foreach ($_GET as $key => $value): ?>
    <?php if (in_array($key, ['allocation_state', 'page'], true)) continue; ?>
    <?php if (is_array($value)): ?>
      <?php foreach ($value as $v): ?>
        <input type="hidden" name="<?= h($key) ?>[]" value="<?= h($v) ?>">
      <?php endforeach; ?>
    <?php else: ?>
      <input type="hidden" name="<?= h($key) ?>" value="<?= h($value) ?>">
    <?php endif; ?>
  <?php endforeach; ?>
  <input type="hidden" name="tab" value="payments">
  <div class="col-md-3">
    <label class="form-label">Allocation State</label>
    <select name="allocation_state" class="form-select">
      <option value="" <?= $allocation_state === '' ? 'selected' : '' ?>>All payment states</option>
      <option value="allocated" <?= $allocation_state === 'allocated' ? 'selected' : '' ?>>Allocated</option>
      <option value="partial" <?= $allocation_state === 'partial' ? 'selected' : '' ?>>Partially Allocated</option>
      <option value="unallocated" <?= $allocation_state === 'unallocated' ? 'selected' : '' ?>>Unallocated Credit</option>
      <option value="broken" <?= $allocation_state === 'broken' ? 'selected' : '' ?>>Broken Allocation</option>
    </select>
  </div>
  <div class="col-md-2">
    <button class="btn btn-primary w-100">Apply</button>
  </div>
</form>
<!-- Payment Method Cards -->
<div class="row g-3 mb-4">
  <?php foreach ($payment_methods as $method): ?>
    <div class="col-md-3">
      <div class="card border-0 shadow-sm h-100">
        <div class="card-body text-center">
          <div class="d-flex justify-content-between align-items-center mb-2">
            <h6 class="card-title mb-0 text-muted"><?= h($method) ?></h6>
            <i class="bi bi-<?= strtolower($method) === 'cash' ? 'cash-coin' : (strtolower($method) === 'card' ? 'credit-card' : (strtolower($method) === 'bank' ? 'bank' : 'receipt')) ?> text-muted"></i>
          </div>
          <h4 class="text-primary fw-bold"><?= money($payment_method_totals[$method]) ?></h4>
          <small class="text-muted">Total Amount</small>
        </div>
      </div>
    </div>
  <?php endforeach; ?>
</div>

<?php
// Include the search component
require_once __DIR__.'/../includes/search_component.php';
renderSearchComponent('payments', $search_filters, $search_query);

?>

<?php if ($credit_clients): ?>
  <div class="alert alert-warning d-flex flex-wrap gap-2">
    <div class="fw-semibold me-2">Clients with credit:</div>
    <?php foreach ($credit_clients as $cc): ?>
      <span class="badge text-bg-warning">
        <?= h($cc['client_name']) ?> · <?= money($cc['credit']) ?>
      </span>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<div class="table-responsive">
  <table class="table table-striped align-middle">
    <thead class="table-light">
      <tr>
        <th style="width:11rem">Date</th>
        <th style="width:14rem">Receipt</th>
        <th>Client</th>
        <th style="width:14rem">Invoice</th>
        <th style="width:12rem">Allocation State</th>
        <th style="width:10rem">Method</th>
        <th class="text-end" style="width:10rem">Amount</th>
        <th>Notes</th>
        <th style="width:120px">Actions</th>
      </tr>
    </thead>
    <tbody>
      <?php $paymentActionReceipts = []; ?>
      <?php foreach ($rows as $r): ?>
        <?php
          $receiptId = (int)($r['receipt_id'] ?? 0);
          $showPaymentActions = $receiptId > 0 && !isset($paymentActionReceipts[$receiptId]);
          if ($showPaymentActions) {
              $paymentActionReceipts[$receiptId] = true;
          }
        ?>
        <tr data-receipt-id="<?= $receiptId ?>">
          <td><?= h($r['payment_date']) ?></td>

          <!-- Receipt (link to printable view) -->
          <td>
            <?php if (!empty($r['receipt_id'])): ?>
              <a class="btn btn-sm btn-outline-secondary"
                 href="accounts/receipt_view.php?id=<?= (int)$r['receipt_id'] ?>"
                 target="_blank">
                <?= h($r['receipt_no'] ?: ('RCT-'.(int)$r['receipt_id'])) ?>
              </a>
            <?php else: ?>
              —
            <?php endif; ?>
          </td>

          <td><?= h($r['client_name'] ?: '—') ?></td>

          <!-- Invoice (link if present) -->
          <td>
            <?php if (!empty($r['invoice_id'])): ?>
              <a class="btn btn-sm btn-outline-primary"
                 href="accounts/invoice_view.php?id=<?= (int)$r['invoice_id'] ?>"
                 target="_blank">
                <?= h($r['invoice_no']) ?>
              </a>
            <?php else: ?>
              <span class="text-muted"><?= ($r['allocation_state'] ?? '') === 'unallocated' ? 'Client credit' : '—' ?></span>
            <?php endif; ?>
          </td>

          <td><span class="badge text-bg-<?= h($r['allocation_state_class'] ?? 'secondary') ?>"><?= h($r['allocation_state_label'] ?? 'Unknown') ?></span></td>
          <td><?= h(ucfirst($r['payment_method'] ?? '')) ?></td>
          <td class="text-end"><?= money($r['amount']) ?></td>
          <td><?= h($r['notes'] ?? '') ?></td>
          <td>
            <?php if ($showPaymentActions && ($canEditPayment || $canDeletePayment)): ?>
              <?php if ($canEditPayment): ?>
              <button class="btn btn-sm btn-outline-primary" 
                      onclick="editPayment(<?= $receiptId ?>, <?= htmlspecialchars(json_encode([
                        'receipt_id' => $receiptId,
                        'receipt_no' => $r['receipt_no'] ?? '',
                        'payment_date' => $r['payment_date'] ?? '',
                        'amount' => $r['receipt_amount'] ?? $r['amount'] ?? 0,
                        'payment_method' => $r['payment_method'] ?? 'cash',
                        'deposit_account_no' => $r['deposit_account_no'] ?? '',
                        'notes' => $r['notes'] ?? '',
                        'allocated_total' => $r['allocated_total'] ?? 0,
                      ]), ENT_QUOTES) ?>)" 
                      title="Edit Payment">
                <i class="bi bi-pencil"></i>
              </button>
              <?php endif; ?>
              <?php if ($canDeletePayment): ?>
              <button class="btn btn-sm btn-outline-danger" 
                      onclick="deletePayment(<?= $receiptId ?>, '<?= h($r['receipt_no'] ?? '') ?>', <?= empty($r['invoice_id']) ? 'true' : 'false' ?>)" 
                      title="Delete Payment">
                <i class="bi bi-trash"></i>
              </button>
              <?php endif; ?>
            <?php elseif (!$showPaymentActions && $receiptId > 0): ?>
              <span class="text-muted small">—</span>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; if (!$rows): ?>
        <tr><td colspan="9" class="text-center text-muted">No payments found.</td></tr>
      <?php endif; ?>
    </tbody>

    <?php if ($rows): ?>
      <tfoot>
        <tr>
          <th colspan="6" class="text-end">Total (listed rows):</th>
          <th class="text-end"><?= money($total_listed) ?></th>
          <th></th>
          <th></th>
        </tr>
        <tr class="table-light">
          <th colspan="9" class="text-center py-2">
            <small class="text-muted">
              Payment Method Breakdown: 
              <?php 
              $breakdown = [];
              foreach ($payment_methods as $method) {
                if ($payment_method_totals[$method] > 0) {
                  $breakdown[] = $method . ': ' . money($payment_method_totals[$method]);
                }
              }
              echo implode(' | ', $breakdown);
              ?>
            </small>
          </th>
        </tr>
      </tfoot>
    <?php endif; ?>
  </table>
</div>

<script>
// Advanced search integration for payments
document.addEventListener('advancedSearch', function(event) {
  const { query, filters } = event.detail;
  
  // Build URL parameters
  const params = new URLSearchParams();
  params.append('tab', 'payments');
  
  if (query) {
    params.append('q', query);
  }
  
  // Add filter parameters
  Object.keys(filters).forEach(key => {
    if (filters[key] !== null && filters[key] !== '' && filters[key] !== false) {
      if (Array.isArray(filters[key])) {
        filters[key].forEach(value => {
          params.append(key + '[]', value);
        });
      } else {
        params.append(key, filters[key]);
      }
    }
  });
  
  window.location.href = 'account?' + params.toString();
});

// Get CSRF token from meta tag or global variable
function getCsrfToken() {
    const meta = document.querySelector('meta[name="csrf-token"]');
    if (meta) return meta.getAttribute('content');
    return window.csrfToken || '';
}

// Delete payment function
function deletePayment(receiptId, receiptNo, isUnallocated) {
    const message = isUnallocated 
        ? `Are you sure you want to delete unallocated payment receipt ${receiptNo}?\n\nThis will:\n- Delete the payment record\n- Reverse GL journal entries (Trade Receivable)\n- Update client available credit\n\nThis action cannot be undone.`
        : `Are you sure you want to delete payment receipt ${receiptNo}?\n\nThis will:\n- Delete the payment record\n- Reverse GL journal entries\n- Update invoice status\n- Update client available credit\n\nThis action cannot be undone.`;
    
    if (!confirm(message)) {
        return;
    }
    
    const formData = new FormData();
    formData.append('receipt_id', receiptId);
    formData.append('_csrf', getCsrfToken());
    
    fetch('accounts/ajax_delete_payment.php', {
        method: 'POST',
        body: formData
    })
    .then(response => {
        if (!response.ok) {
            return response.text().then(text => {
                try {
                    return JSON.parse(text);
                } catch {
                    throw new Error(text || 'Server error');
                }
            });
        }
        return response.json();
    })
    .then(data => {
        if (data.success) {
            alert('Payment deleted successfully!\n\nGL entries have been reversed and client credit has been updated.');
            location.reload();
        } else {
            alert('Error: ' + (data.error || 'Failed to delete payment'));
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('Error deleting payment: ' + (error.message || 'Please try again.'));
    });
}

// Edit payment function
function defaultDepositForMethod(m) {
    const x = String(m || '').toLowerCase();
    return x === 'cash' ? '1010' : '1020';
}

window.cleaningPaymentAccountOptions = <?= json_encode($cleaningPaymentAccountOptions, JSON_UNESCAPED_UNICODE) ?>;

function editPayment(receiptId, paymentData) {
    const dep = (paymentData.deposit_account_no && String(paymentData.deposit_account_no).trim())
        ? String(paymentData.deposit_account_no).trim()
        : defaultDepositForMethod(paymentData.payment_method);
    let depOpts = '';
    (window.cleaningPaymentAccountOptions || []).forEach(function(a) {
        const sel = a.account_no === dep ? ' selected' : '';
        depOpts += `<option value="${a.account_no}"${sel}>${a.account_no} – ${a.name}</option>`;
    });
    const allocatedHint = parseFloat(paymentData.allocated_total || 0) > 0
        ? `<div class="alert alert-info py-2 small mb-3">Allocated: AED ${parseFloat(paymentData.allocated_total).toFixed(2)}. Amount cannot be reduced below this.</div>`
        : '';
    const modalHtml = `
        <div class="modal fade" id="editPaymentModal" tabindex="-1">
            <div class="modal-dialog">
                <form class="modal-content" id="editPaymentForm">
                    <div class="modal-header">
                        <h5 class="modal-title">Edit Payment ${paymentData.receipt_no ? '· ' + paymentData.receipt_no : ''}</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" name="receipt_id" value="${receiptId}">
                        ${allocatedHint}
                        <div class="mb-3">
                            <label class="form-label">Payment Date</label>
                            <input type="date" class="form-control" name="payment_date" value="${paymentData.payment_date || ''}" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Amount (AED)</label>
                            <input type="number" step="0.01" min="0" class="form-control" name="amount" value="${paymentData.amount || 0}" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Payment Method</label>
                            <select class="form-select" name="payment_method" id="editPayMethod" required>
                                <option value="cash" ${paymentData.payment_method === 'cash' ? 'selected' : ''}>Cash</option>
                                <option value="card" ${paymentData.payment_method === 'card' ? 'selected' : ''}>Card</option>
                                <option value="bank" ${paymentData.payment_method === 'bank' ? 'selected' : ''}>Bank Transfer</option>
                                <option value="cheque" ${paymentData.payment_method === 'cheque' ? 'selected' : ''}>Cheque</option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Deposit account <span class="text-danger">*</span></label>
                            <select class="form-select" name="deposit_account_no" id="editDepositAccount" required>${depOpts}</select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Notes</label>
                            <textarea class="form-control" name="notes" rows="2">${paymentData.notes || ''}</textarea>
                        </div>
                        <div class="text-danger small" id="editPaymentErr" style="display:none;"></div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Save Changes</button>
                    </div>
                </form>
            </div>
        </div>
    `;
    
    // Remove existing modal if any
    const existingModal = document.getElementById('editPaymentModal');
    if (existingModal) {
        existingModal.remove();
    }
    
    // Add modal to body
    document.body.insertAdjacentHTML('beforeend', modalHtml);
    
    // Show modal
    const modal = new bootstrap.Modal(document.getElementById('editPaymentModal'));
    modal.show();

    document.getElementById('editPayMethod')?.addEventListener('change', function() {
        const sel = document.getElementById('editDepositAccount');
        if (!sel) return;
        const d = defaultDepositForMethod(this.value);
        if ([...sel.options].some(o => o.value === d)) sel.value = d;
    });
    
    // Handle form submission
    document.getElementById('editPaymentForm').addEventListener('submit', function(e) {
        e.preventDefault();
        const formData = new FormData(this);
        formData.append('_csrf', getCsrfToken());
        document.getElementById('editPaymentErr').style.display = 'none';
        
        fetch('accounts/ajax_edit_payment.php', {
            method: 'POST',
            body: formData
        })
        .then(response => {
            if (!response.ok) {
                return response.text().then(text => {
                    try {
                        return JSON.parse(text);
                    } catch {
                        throw new Error(text || 'Server error');
                    }
                });
            }
            return response.json();
        })
        .then(data => {
            if (data.success) {
                alert('Payment updated successfully!');
                modal.hide();
                location.reload();
            } else {
                document.getElementById('editPaymentErr').textContent = data.error || 'Failed to update payment';
                document.getElementById('editPaymentErr').style.display = '';
            }
        })
        .catch(error => {
            console.error('Error:', error);
            document.getElementById('editPaymentErr').textContent = 'Error updating payment: ' + (error.message || 'Please try again.');
            document.getElementById('editPaymentErr').style.display = '';
        });
    });
    
    // Clean up modal when hidden
    document.getElementById('editPaymentModal').addEventListener('hidden.bs.modal', function() {
        this.remove();
    });
}
</script>
