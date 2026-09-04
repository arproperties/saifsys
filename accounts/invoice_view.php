<?php
// accounts/invoice_view.php


require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/../includes/db_connect.php';
require_once __DIR__.'/../includes/ar_helpers.php';
require_once __DIR__.'/../includes/cleaning_payment_accounts.php';
require_once __DIR__.'/../includes/permissions.php';
require_once __DIR__.'/../includes/work_order_batch_invoice_service.php';
require_once __DIR__.'/../includes/work_order_financial_guard.php';

$cleaningPaymentAccountOptions = cleaning_payment_account_options($conn);

// Check permission (backward compatible: if no permission but has role, allow)
if (!has_permission('invoices.view', MODULE_FINANCE, $conn)) {
    require_role(['Owner','Admin','Account'], $conn);
}

$invoice_id = (int)($_GET['id'] ?? 0);
if ($invoice_id<=0) { header("Location: ../account.php?tab=invoices"); exit; }

$msg = $err = '';

// Handle payment POST
// Handle payment POST
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['add_payment'])) {
    csrf_verify();
  $pay_date = $_POST['payment_date'] ?: date('Y-m-d');
  $amount   = (float)$_POST['amount'];
  $method   = trim($_POST['payment_method'] ?? 'cash');
  $notes    = trim($_POST['notes'] ?? '');
  $deposit  = trim($_POST['deposit_account_no'] ?? '');

  $result = ar_add_payment($conn, $invoice_id, $pay_date, $amount, $method, $notes, $_SESSION['user_id'] ?? null, $deposit);
  if ($result['success']) {
    $msg = $result['message'];
  } else {
    $err = $result['message'];
  }
}

// Load invoice, lines, payments
$inv    = ar_get_invoice($conn, $invoice_id);
$items  = ar_get_invoice_items($conn, $invoice_id);
$pays   = ar_get_invoice_payments($conn, $invoice_id);
$batchDetail = sm_get_batch_for_summary_invoice($conn, $invoice_id);
$isBatchSummary = !empty($inv['is_batch_summary']) || $batchDetail;
$opsInvoiceLock = wo_invoice_from_operations_locked($conn, $invoice_id);
$amountPaid = isset($inv['amount_paid']) ? (float)$inv['amount_paid'] : 0.0;
$balanceDue = isset($inv['balance_due']) ? (float)$inv['balance_due'] : max(0.0, (float)$inv['total'] - $amountPaid);
$balanceDue = max(0.0, $balanceDue);
$available_credit = 0.0;
$credit_receipts  = [];
if (!empty($inv['client_name']) && !empty($inv['client_id'])) {
  $available_credit = ar_get_client_unapplied_credit($conn, (int)$inv['client_id']);
  if ($available_credit > 0) {
    $credit_receipts = ar_list_unapplied_receipts($conn, (int)$inv['client_id']);
  }
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Invoice <?= h($inv['invoice_no']) ?></title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="csrf-token" content="<?= h(csrf_token()) ?>">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
</head>
<body class="bg-light">
<div class="container my-4">

  <a href="../account.php?tab=invoices" class="btn btn-outline-secondary">&larr; Back</a>

  <!-- FIX: use $inv (or $invoice_id) not $invoice -->
  <a href="invoice_print.php?id=<?= (int)$invoice_id ?>" class="btn btn-secondary" target="_blank" rel="noopener">
    <i class="bi bi-printer"></i> Print / Tax Invoice
  </a>

  <button class="btn btn-info" data-bs-toggle="modal" data-bs-target="#emailModal">
    <i class="bi bi-envelope"></i> Send Email
  </button>

  <?php if (in_array($inv['status'], ['draft', 'issued', 'partially_paid']) && !$opsInvoiceLock['locked']): ?>
  <a href="invoice_edit.php?id=<?= (int)$invoice_id ?>" class="btn btn-primary">
    <i class="bi bi-pencil"></i> Edit Invoice
  </a>
  <?php endif; ?>

  <br><br>

  <?php if($msg): ?><div class="alert alert-success"><?= h($msg) ?></div><?php endif; ?>
  <?php if($err): ?><div class="alert alert-danger"><?= h($err) ?></div><?php endif; ?>

  <?php if ($opsInvoiceLock['locked']): ?>
  <div class="alert alert-warning">
    <i class="bi bi-lock"></i> <strong>Locked — operations invoice.</strong>
    <?= h($opsInvoiceLock['reason']) ?>
    <a href="adjustment_requests.php" class="alert-link">Open Adjustment Requests</a>
    <?php if ($opsInvoiceLock['order_id']): ?>
      · <a href="../operation/order_edit.php?id=<?= (int)$opsInvoiceLock['order_id'] ?>" class="alert-link">Work order #<?= (int)$opsInvoiceLock['order_id'] ?></a>
    <?php endif; ?>
  </div>
  <?php endif; ?>

  <?php if ($isBatchSummary): ?>
  <div class="alert alert-info">
    <strong>Batch summary invoice (BINV)</strong> — revenue GL is on each child invoice below. This document is for client billing only.
  </div>
  <?php endif; ?>

  <div class="card shadow-sm rounded-4">
    <div class="card-body">
      <div class="d-flex">
        <div>
          <h4 class="mb-0">Invoice <?= h($inv['invoice_no']) ?></h4>
          <div class="text-muted">Client: <?= h($inv['client_name'] ?: '—') ?></div>
          <div class="text-muted">
            Date: <?= h($inv['issue_date']) ?>
            <?= $inv['due_date'] ? " • Due: ".h($inv['due_date']) : "" ?>
          </div>
          <div class="mt-2">
            <?php
              $status = $inv['status'];
              $badge = ($status==='paid' ? 'success' :
                        ($status==='partially_paid' ? 'warning' :
                        ($status==='void' ? 'secondary' : 'info')));
            ?>
            <span class="badge text-bg-<?= $badge ?>">
              <?= ucfirst(str_replace('_',' ',$status)) ?>
            </span>
          </div>
        </div>
        <div class="ms-auto text-end">
          <div class="text-muted">Total</div>
          <div class="fs-4 fw-bold" data-total="total"><?= money($inv['total']) ?></div>
          <div class="text-muted">
            Paid: <span data-total="amount_paid"><?= money($amountPaid) ?></span> |
            Balance: <span class="fw-semibold" data-total="balance_due"><?= money($balanceDue) ?></span>
          </div>
        </div>
       </div>

<?php if ($available_credit > 0): ?>
  <div class="mt-1">
    <span class="badge text-bg-warning">
      Available credit: <?= money($available_credit) ?>
    </span>
    <button class="btn btn-sm btn-outline-primary ms-2" data-bs-toggle="modal" data-bs-target="#applyCreditModal">
      Apply Credit
    </button>
  </div>
<?php endif; ?>

      <hr>

      <?php if ($batchDetail && !empty($batchDetail['lines'])): ?>
      <h6 class="mb-2">Child work orders &amp; invoices</h6>
      <div class="table-responsive mb-3">
        <table class="table table-sm table-bordered">
          <thead class="table-light">
            <tr><th>WO</th><th>Date</th><th>Child invoice</th><th class="text-end">Total</th></tr>
          </thead>
          <tbody>
          <?php foreach ($batchDetail['lines'] as $bl): ?>
            <tr>
              <td><a href="../operation/order_edit.php?id=<?= (int)$bl['order_id'] ?>">#<?= (int)$bl['order_id'] ?></a></td>
              <td><?= h($bl['svc_date_calc'] ?? '') ?></td>
              <td><a href="invoice_view.php?id=<?= (int)$bl['child_invoice_id'] ?>"><?= h($bl['child_invoice_no'] ?? '') ?></a></td>
              <td class="text-end"><?= money($bl['line_total']) ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <hr>
      <?php endif; ?>

      <div class="table-responsive">
        <table class="table table-sm align-middle">
          <thead class="table-light">
            <tr>
              <th>#</th>
              <th>Description</th>
              <th class="text-end">Qty</th>
              <th class="text-end">Rate</th>
              <th class="text-end">Line</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach($items as $ix=>$it): ?>
            <tr data-item-id="<?= $it['id'] ?>">
              <td><?= $ix+1 ?></td>
              <td><?= h($it['description']) ?></td>
              <!-- Inline editable qty -->
              <td class="text-end">
                <span class="editable-qty" data-field="qty" data-value="<?= h($it['qty']) ?>">
                  <?= rtrim(rtrim(number_format((float)$it['qty'], 2, '.', ''), '0'), '.') ?>
                </span>
              </td>
              <!-- Inline editable unit price -->
              <td class="text-end">
                <span class="editable-price" data-field="unit_price" data-value="<?= h($it['unit_price']) ?>">
                  <?= money($it['unit_price']) ?>
                </span>
              </td>
              <td class="text-end">
                <span class="line-total"><?= money($it['line_total']) ?></span>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>

      <div class="row g-3 mt-3">
        <div class="col-md-6"></div>
        <div class="col-md-6">
          <table class="table table-sm">
            <tr>
              <td class="text-end">Subtotal:</td>
              <td class="text-end" style="width:160px" data-total="subtotal"><?= money($inv['subtotal']) ?></td>
            </tr>
            <tr>
              <td class="text-end"><button class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#discountModal">Edit Discount</button></td>
              <td class="text-end" data-total="discount_amount"><?= money($inv['discount_amount']) ?></td>
            </tr>
            <tr>
              <!-- FIX: show VAT % as a percent, not as money -->
              <td class="text-end">VAT (<?= number_format((float)$inv['vat_rate'], 2) ?>%):</td>
              <td class="text-end" data-total="vat_amount"><?= money($inv['vat_amount']) ?></td>
            </tr>
            <tr>
              <th class="text-end">Total:</th>
              <th class="text-end" data-total="total"><?= money($inv['total']) ?></th>
            </tr>
            <?php if ($amountPaid > 0): ?>
            <tr>
              <td class="text-end text-success">Payments / Credits Applied:</td>
              <td class="text-end text-success" data-total="amount_paid">-<?= money($amountPaid) ?></td>
            </tr>
            <tr class="table-light">
              <th class="text-end">Balance Due:</th>
              <th class="text-end" data-total="balance_due"><?= money($balanceDue) ?></th>
            </tr>
            <?php else: ?>
            <tr class="table-light">
              <th class="text-end">Amount Due:</th>
              <th class="text-end" data-total="balance_due"><?= money($balanceDue) ?></th>
            </tr>
            <?php endif; ?>
          </table>
        </div>
      </div>

      <div class="d-flex align-items-center mt-2">
        <div class="fw-semibold">Payments</div>
        <?php if (!$isBatchSummary): ?>
        <button class="btn btn-sm btn-primary ms-auto" data-bs-toggle="collapse" data-bs-target="#addPay">
          + Add Payment
        </button>
        <?php endif; ?>
      </div>

      <?php if ($isBatchSummary): ?>
      <div class="alert alert-warning py-2 small mt-2 mb-0">
        <strong>Do not pay this BINV.</strong> Allocate the client's payment to the <strong>child INV-</strong> job invoices listed above.
      </div>
      <?php else: ?>

      <div id="addPay" class="collapse mt-2">
        <form method="post" class="row g-2">
<?php csrf_field(); ?>
          <input type="hidden" name="add_payment" value="1">
          <div class="col-md-3">
            <label class="form-label">Date</label>
            <input type="date" class="form-control" name="payment_date" value="<?= date('Y-m-d') ?>">
          </div>
          <div class="col-md-3">
            <label class="form-label">Amount</label>
            <input type="number" step="0.01" class="form-control" name="amount" required>
          </div>
          <div class="col-md-3">
            <label class="form-label">Method</label>
            <select class="form-select" name="payment_method" id="invPayMethod">
              <option>cash</option>
              <option>card</option>
              <option>bank</option>
              <option>cheque</option>
            </select>
          </div>
          <div class="col-md-3">
            <label class="form-label">Deposit account <span class="text-danger">*</span></label>
            <select class="form-select" name="deposit_account_no" id="invDepositAccount" required>
              <option value="">— Select —</option>
              <?php foreach ($cleaningPaymentAccountOptions as $co): ?>
                <option value="<?= h($co['account_no']) ?>"><?= h($co['account_no'].' – '.$co['name']) ?></option>
              <?php endforeach; ?>
            </select>
            <div class="form-text small">Where money was received (chart of accounts).</div>
          </div>
          <div class="col-md-3">
            <label class="form-label">Notes</label>
            <input class="form-control" name="notes">
          </div>
          <div class="col-12 text-end">
            <button class="btn btn-success">Save Payment</button>
          </div>
        </form>
      </div>
      <?php endif; ?>

      <div class="table-responsive mt-2">
        <table class="table table-sm">
          <thead class="table-light">
            <tr><th>Date</th><th>Receipt</th><th>Method</th><th>Deposit a/c</th><th class="text-end">Amount</th><th>Notes</th><th style="width:120px">Actions</th></tr>
          </thead>
          <tbody>
            <?php foreach($pays as $p): ?>
              <tr data-receipt-id="<?= (int)($p['receipt_id'] ?? 0) ?>">
                <td><?= h($p['payment_date']) ?></td>
                <td><?= h($p['receipt_no'] ?? '—') ?></td>
                <td><?= h($p['payment_method']) ?></td>
                <td><?= !empty($p['deposit_account_no']) ? h($p['deposit_account_no']) : '<span class="text-muted">legacy</span>' ?></td>
                <td class="text-end"><?= money($p['amount']) ?></td>
                <td><?= h($p['notes'] ?? '') ?></td>
                <td>
                  <button class="btn btn-sm btn-outline-primary" onclick="editPayment(<?= (int)($p['receipt_id'] ?? 0) ?>, <?= htmlspecialchars(json_encode($p), ENT_QUOTES) ?>)" title="Edit Payment">
                    <i class="bi bi-pencil"></i>
                  </button>
                  <button class="btn btn-sm btn-outline-danger" onclick="deletePayment(<?= (int)($p['receipt_id'] ?? 0) ?>, '<?= h($p['receipt_no'] ?? '') ?>')" title="Delete Payment">
                    <i class="bi bi-trash"></i>
                  </button>
                </td>
              </tr>
            <?php endforeach; if(!$pays): ?>
              <tr><td colspan="7" class="text-muted text-center">No payments yet.</td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>

      <!-- Activity Timeline -->
      <div class="mt-4">
        <div class="d-flex align-items-center mb-3">
          <h5 class="mb-0">Activity Timeline</h5>
          <button class="btn btn-sm btn-outline-primary ms-auto" onclick="refreshActivityTimeline()">
            <i class="bi bi-arrow-clockwise"></i> Refresh
          </button>
        </div>
        
        <div id="activityTimeline" class="timeline-container">
          <div class="text-center py-4">
            <div class="spinner-border text-primary" role="status">
              <span class="visually-hidden">Loading...</span>
            </div>
            <div class="mt-2 text-muted">Loading activity timeline...</div>
          </div>
        </div>
      </div>

    </div>
  </div>
<div class="modal fade" id="discountModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <form class="modal-content" id="discountForm">
      <div class="modal-header">
        <h5 class="modal-title">Set Discount (AED)</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <input type="hidden" name="invoice_id" value="<?= (int)$inv['id'] ?>">
        <div class="mb-2">
          <input type="number" step="0.01" min="0" class="form-control" name="discount_amount"
                 value="<?= h($inv['discount_amount']) ?>" required>
        </div>
        <div class="text-danger small" id="discountErr" style="display:none;"></div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-primary">Save</button>
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
      </div>
    </form>
  </div>
</div>

<?php if ($available_credit > 0): ?>
<div class="modal fade" id="applyCreditModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg">
    <form class="modal-content" id="applyCreditForm">
      <div class="modal-header">
        <h5 class="modal-title">Apply Client Credit (<?= money($available_credit) ?> available)</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <input type="hidden" name="invoice_id" value="<?= (int)$invoice_id ?>">
        <div class="table-responsive">
          <table class="table table-sm align-middle">
            <thead class="table-light">
              <tr><th>Date</th><th>Receipt</th><th>Method</th><th class="text-end">Remaining</th><th style="width:160px">Apply</th></tr>
            </thead>
            <tbody>
              <?php foreach ($credit_receipts as $cr): ?>
                <tr>
                  <td><?= h($cr['receipt_date']) ?></td>
                  <td><?= h($cr['receipt_no']) ?></td>
                  <td><?= h($cr['method']) ?></td>
                  <td class="text-end"><?= money($cr['remaining']) ?></td>
                  <td>
                    <div class="input-group input-group-sm">
                      <input type="number" step="0.01" min="0" max="<?= h($cr['remaining']) ?>" class="form-control"
                             name="apply_amount[<?= (int)$cr['id'] ?>]" value="<?= h(min($cr['remaining'], $balanceDue)) ?>">
                      <span class="input-group-text">AED</span>
                    </div>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <div class="text-danger small" id="applyCredErr" style="display:none"></div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-primary">Apply Selected</button>
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>

<!-- Email Modal -->
<div class="modal fade" id="emailModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <form class="modal-content" id="emailForm">
      <div class="modal-header">
        <h5 class="modal-title">Send Invoice Email</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <input type="hidden" name="invoice_id" value="<?= (int)$invoice_id ?>">
        
        <div class="mb-3">
          <label class="form-label">Recipient Email</label>
          <input type="email" class="form-control" name="recipient_email" id="recipient_email" 
                 value="<?= h($inv['client_email'] ?? '') ?>" required>
          <div class="form-text">Leave empty to use client's email address</div>
        </div>
        
        <div class="mb-3">
          <label class="form-label">Email Template</label>
          <select class="form-select" name="template_id" id="template_id">
            <option value="">Use Default Template</option>
            <!-- Templates will be loaded via AJAX -->
          </select>
        </div>
        
        <div class="text-danger small" id="emailErr" style="display:none;"></div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="submit" class="btn btn-primary">
          <i class="bi bi-envelope"></i> Send Email
        </button>
      </div>
    </form>
  </div>
</div>


</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="../includes/keyboard_shortcuts.js"></script>
<script>
document.getElementById('discountForm')?.addEventListener('submit', function(e){
  e.preventDefault();
  const fd = new FormData(this);
  document.getElementById('discountErr').style.display = 'none';

  fetch('ajax_set_invoice_discount.php', { method: 'POST', body: fd })
    .then(r => r.json())
    .then(j => {
      if (j.success) {
        // Refresh to show new totals
        location.reload();
      } else {
        document.getElementById('discountErr').textContent = j.error || 'Error saving discount';
        document.getElementById('discountErr').style.display = '';
      }
    })
    .catch(() => {
      document.getElementById('discountErr').textContent = 'Server error';
      document.getElementById('discountErr').style.display = '';
    });
});
</script>
<script>
document.getElementById('applyCreditForm')?.addEventListener('submit', function(e){
  e.preventDefault();
  const fd = new FormData(this);
  fetch('ajax_apply_credit.php', { method:'POST', body:fd })
    .then(r=>r.json()).then(j=>{
      if (j.success) { location.reload(); }
      else {
        const el = document.getElementById('applyCredErr');
        el.textContent = j.error || 'Failed to apply credit';
        el.style.display = '';
      }
    }).catch(()=>{
      const el = document.getElementById('applyCredErr');
      el.textContent = 'Server error';
      el.style.display = '';
    });
});
</script>

<script>
// Email functionality
document.getElementById('emailForm')?.addEventListener('submit', function(e) {
  e.preventDefault();
  const fd = new FormData(this);
  document.getElementById('emailErr').style.display = 'none';

  fetch('ajax/send_invoice_email.php', { method: 'POST', body: fd })
    .then(r => {
      console.log('Email response status:', r.status);
      return r.json();
    })
    .then(j => {
      console.log('Email response:', j);
      if (j.success) {
        alert('Email sent successfully!');
        bootstrap.Modal.getInstance(document.getElementById('emailModal')).hide();
        // Refresh page to update activity timeline
        setTimeout(() => window.location.reload(), 500);
      } else {
        const errorMsg = j.error || 'Error sending email';
        console.error('Email error:', errorMsg);
        document.getElementById('emailErr').textContent = errorMsg;
        document.getElementById('emailErr').style.display = '';
      }
    })
    .catch(error => {
      console.error('Email fetch error:', error);
      document.getElementById('emailErr').textContent = 'Failed to send email. Please check your browser console for details.';
      document.getElementById('emailErr').style.display = '';
    });
});

// Load email templates when modal is shown
document.getElementById('emailModal')?.addEventListener('show.bs.modal', function() {
  const templateSelect = document.getElementById('template_id');
  if (templateSelect.children.length <= 1) { // Only has default option
    fetch('ajax/get_email_templates.php?type=invoice')
      .then(response => response.json())
      .then(data => {
        if (data.success) {
          data.templates.forEach(template => {
            const option = document.createElement('option');
            option.value = template.id;
            option.textContent = template.template_name;
            templateSelect.appendChild(option);
          });
        }
      })
      .catch(error => console.error('Error loading templates:', error));
  }
});
</script>

<style>
.editable-qty, .editable-price {
  cursor: pointer;
  padding: 4px 8px;
  border-radius: 4px;
  transition: background-color 0.2s;
}

.editable-qty:hover, .editable-price:hover {
  background-color: #f8f9fa;
  border: 1px dashed #dee2e6;
}

.editable-input {
  width: 100%;
  border: 1px solid #007bff;
  border-radius: 4px;
  padding: 4px 8px;
  text-align: right;
  font-size: inherit;
}

.editable-input:focus {
  outline: none;
  box-shadow: 0 0 0 2px rgba(0, 123, 255, 0.25);
}

.editing {
  background-color: #e3f2fd !important;
}

.saving {
  opacity: 0.6;
  pointer-events: none;
}

/* Activity Timeline Styles */
.timeline-container {
  position: relative;
  padding-left: 30px;
}

.timeline-container::before {
  content: '';
  position: absolute;
  left: 15px;
  top: 0;
  bottom: 0;
  width: 2px;
  background: linear-gradient(to bottom, #007bff, #6c757d);
}

.timeline-item {
  position: relative;
  margin-bottom: 20px;
  padding-left: 20px;
}

.timeline-item::before {
  content: '';
  position: absolute;
  left: -8px;
  top: 8px;
  width: 16px;
  height: 16px;
  border-radius: 50%;
  background: #fff;
  border: 3px solid #007bff;
  z-index: 1;
}

.timeline-item.payment::before {
  border-color: #28a745;
}

.timeline-item.status_change::before {
  border-color: #ffc107;
}

.timeline-item.edit::before {
  border-color: #007bff;
}

.timeline-item.item_edit::before {
  border-color: #17a2b8;
}

.timeline-item.created::before {
  border-color: #28a745;
}

.timeline-item.email::before {
  border-color: #6f42c1;
}

.timeline-item.discount::before {
  border-color: #fd7e14;
}

.timeline-card {
  background: #fff;
  border: 1px solid #e9ecef;
  border-radius: 8px;
  padding: 16px;
  box-shadow: 0 2px 4px rgba(0,0,0,0.1);
  transition: box-shadow 0.2s;
}

.timeline-card:hover {
  box-shadow: 0 4px 8px rgba(0,0,0,0.15);
}

.timeline-header {
  display: flex;
  align-items: center;
  margin-bottom: 8px;
}

.timeline-icon {
  width: 32px;
  height: 32px;
  border-radius: 50%;
  display: flex;
  align-items: center;
  justify-content: center;
  margin-right: 12px;
  font-size: 14px;
}

.timeline-icon.payment {
  background: #d4edda;
  color: #155724;
}

.timeline-icon.status_change {
  background: #fff3cd;
  color: #856404;
}

.timeline-icon.edit {
  background: #cce7ff;
  color: #004085;
}

.timeline-icon.item_edit {
  background: #d1ecf1;
  color: #0c5460;
}

.timeline-icon.created {
  background: #d4edda;
  color: #155724;
}

.timeline-icon.email {
  background: #e2e3f1;
  color: #4c4c6a;
}

.timeline-icon.discount {
  background: #ffeaa7;
  color: #856404;
}

.timeline-title {
  font-weight: 600;
  margin: 0;
  color: #212529;
}

.timeline-time {
  font-size: 0.875rem;
  color: #6c757d;
  margin-left: auto;
}

.timeline-description {
  color: #495057;
  margin-bottom: 8px;
}

.timeline-details {
  font-size: 0.875rem;
  color: #6c757d;
  margin-bottom: 4px;
}

.timeline-additional {
  font-size: 0.8rem;
  color: #868e96;
  font-style: italic;
}

.timeline-empty {
  text-align: center;
  padding: 40px 20px;
  color: #6c757d;
}

.timeline-empty i {
  font-size: 3rem;
  margin-bottom: 16px;
  opacity: 0.5;
}
</style>

<script>
const INVOICE_OPS_LOCKED = <?= $opsInvoiceLock['locked'] ? 'true' : 'false' ?>;
// Inline editing functionality
document.addEventListener('DOMContentLoaded', function() {
    if (INVOICE_OPS_LOCKED) return;
    // Make qty and price fields editable
    document.querySelectorAll('.editable-qty, .editable-price').forEach(function(element) {
        element.addEventListener('click', function() {
            if (this.classList.contains('saving')) return;
            
            const field = this.dataset.field;
            const currentValue = this.dataset.value;
            const isPrice = field === 'unit_price';
            
            // Create input field
            const input = document.createElement('input');
            input.type = 'number';
            input.step = isPrice ? '0.01' : '0.01';
            input.min = '0';
            input.className = 'editable-input';
            input.value = currentValue;
            
            // Replace content with input
            const originalContent = this.innerHTML;
            this.innerHTML = '';
            this.appendChild(input);
            this.classList.add('editing');
            
            // Focus and select
            input.focus();
            input.select();
            
            // Handle save on blur or enter
            function saveValue() {
                const newValue = parseFloat(input.value);
                if (isNaN(newValue) || newValue < 0) {
                    // Revert on invalid input
                    this.innerHTML = originalContent;
                    this.classList.remove('editing');
                    return;
                }
                
                // Update display immediately
                this.dataset.value = newValue;
                this.classList.add('saving');
                
                // Send update to server
                const formData = new FormData();
                formData.append('item_id', this.closest('tr').dataset.itemId);
                formData.append('field', field);
                formData.append('value', newValue);
                
                fetch('ajax/update_invoice_item.php', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        // Update display with formatted value
                        if (isPrice) {
                            this.innerHTML = formatMoney(data.item[field]);
                        } else {
                            this.innerHTML = formatNumber(data.item[field]);
                        }
                        
                        // Update line total
                        const lineTotalElement = this.closest('tr').querySelector('.line-total');
                        if (lineTotalElement) {
                            lineTotalElement.textContent = formatMoney(data.item.line_total);
                        }
                        
                        // Update invoice totals if available
                        if (data.invoice_totals) {
                            updateInvoiceTotals(data.invoice_totals);
                        }
                        
                        this.classList.remove('editing', 'saving');
                    } else {
                        // Revert on error
                        this.innerHTML = originalContent;
                        this.classList.remove('editing', 'saving');
                        alert('Error updating item: ' + data.error);
                    }
                })
                .catch(error => {
                    // Revert on error
                    this.innerHTML = originalContent;
                    this.classList.remove('editing', 'saving');
                    alert('Error updating item');
                });
            }
            
            // Save on blur
            input.addEventListener('blur', saveValue.bind(this));
            
            // Save on enter
            input.addEventListener('keypress', function(e) {
                if (e.key === 'Enter') {
                    saveValue.call(this.parentElement);
                }
            });
            
            // Cancel on escape
            input.addEventListener('keydown', function(e) {
                if (e.key === 'Escape') {
                    this.parentElement.innerHTML = originalContent;
                    this.parentElement.classList.remove('editing');
                }
            });
        });
    });
});

function formatMoney(amount) {
    return 'AED ' + parseFloat(amount).toLocaleString('en-US', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2
    });
}

function formatNumber(num) {
    const formatted = parseFloat(num).toFixed(2);
    return formatted.replace(/\.?0+$/, '');
}

function updateInvoiceTotals(totals) {
    // Update any invoice total displays on the page
    const totalElements = document.querySelectorAll('[data-total]');
    totalElements.forEach(element => {
        const field = element.dataset.total;
        if (totals[field] !== undefined) {
            element.textContent = formatMoney(totals[field]);
        }
    });
}

// Activity Timeline functionality
function loadActivityTimeline() {
    const timelineContainer = document.getElementById('activityTimeline');
    const invoiceId = <?= $invoice_id ?>;
    
    fetch(`ajax/get_invoice_activity.php?invoice_id=${invoiceId}`)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                displayActivityTimeline(data.activities);
            } else {
                timelineContainer.innerHTML = `
                    <div class="timeline-empty">
                        <i class="bi bi-exclamation-triangle"></i>
                        <div>Error loading activity timeline: ${data.error}</div>
                    </div>
                `;
            }
        })
        .catch(error => {
            timelineContainer.innerHTML = `
                <div class="timeline-empty">
                    <i class="bi bi-exclamation-triangle"></i>
                    <div>Error loading activity timeline</div>
                </div>
            `;
        });
}

function displayActivityTimeline(activities) {
    const timelineContainer = document.getElementById('activityTimeline');
    
    if (activities.length === 0) {
        timelineContainer.innerHTML = `
            <div class="timeline-empty">
                <i class="bi bi-clock-history"></i>
                <div>No activity recorded yet</div>
            </div>
        `;
        return;
    }
    
    let html = '';
    activities.forEach(activity => {
        html += `
            <div class="timeline-item ${activity.type}">
                <div class="timeline-card">
                    <div class="timeline-header">
                        <div class="timeline-icon ${activity.type}">
                            <i class="bi ${activity.icon}"></i>
                        </div>
                        <div class="timeline-title">${activity.description}</div>
                        <div class="timeline-time">
                            ${activity.formatted_date} at ${activity.formatted_time}
                        </div>
                    </div>
                    <div class="timeline-description">${activity.details}</div>
                    ${activity.additional_info ? `<div class="timeline-additional">${activity.additional_info}</div>` : ''}
                </div>
            </div>
        `;
    });
    
    timelineContainer.innerHTML = html;
}

function refreshActivityTimeline() {
    const timelineContainer = document.getElementById('activityTimeline');
    timelineContainer.innerHTML = `
        <div class="text-center py-4">
            <div class="spinner-border text-primary" role="status">
                <span class="visually-hidden">Loading...</span>
            </div>
            <div class="mt-2 text-muted">Refreshing activity timeline...</div>
        </div>
    `;
    loadActivityTimeline();
}

// Load timeline when page loads
document.addEventListener('DOMContentLoaded', function() {
    loadActivityTimeline();
});

// Get CSRF token
function getCsrfToken() {
    const meta = document.querySelector('meta[name="csrf-token"]');
    if (meta) return meta.getAttribute('content');
    return window.csrfToken || '';
}

// Delete payment function
function deletePayment(receiptId, receiptNo) {
    if (!confirm(`Are you sure you want to delete payment receipt ${receiptNo}?\n\nThis will:\n- Delete the payment record\n- Reverse GL journal entries\n- Update invoice status\n\nThis action cannot be undone.`)) {
        return;
    }
    
    const formData = new FormData();
    formData.append('receipt_id', receiptId);
    formData.append('_csrf', getCsrfToken());
    
    fetch('ajax_delete_payment.php', {
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
            alert('Payment deleted successfully!');
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

window.cleaningPaymentAccountOptions = <?= json_encode($cleaningPaymentAccountOptions, JSON_UNESCAPED_UNICODE) ?>;

function defaultDepositForMethod(m) {
    const x = String(m || '').toLowerCase();
    return x === 'cash' ? '1010' : '1020';
}

document.getElementById('invPayMethod')?.addEventListener('change', function() {
    const sel = document.getElementById('invDepositAccount');
    if (!sel) return;
    const d = defaultDepositForMethod(this.value);
    if ([...sel.options].some(o => o.value === d)) sel.value = d;
});

document.addEventListener('DOMContentLoaded', function() {
    const pm = document.getElementById('invPayMethod');
    const ds = document.getElementById('invDepositAccount');
    if (pm && ds && !ds.value) {
        const d = defaultDepositForMethod(pm.value);
        if ([...ds.options].some(o => o.value === d)) ds.value = d;
    }
});

// Edit payment function
function editPayment(receiptId, paymentData) {
    const dep = (paymentData.deposit_account_no && String(paymentData.deposit_account_no).trim())
        ? String(paymentData.deposit_account_no).trim()
        : defaultDepositForMethod(paymentData.payment_method);
    let depOpts = '';
    (window.cleaningPaymentAccountOptions || []).forEach(function(a) {
        const sel = a.account_no === dep ? ' selected' : '';
        depOpts += `<option value="${a.account_no}"${sel}>${a.account_no} – ${a.name}</option>`;
    });
    const modalHtml = `
        <div class="modal fade" id="editPaymentModal" tabindex="-1">
            <div class="modal-dialog">
                <form class="modal-content" id="editPaymentForm">
                    <div class="modal-header">
                        <h5 class="modal-title">Edit Payment</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" name="receipt_id" value="${receiptId}">
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
                            <select class="form-select" name="deposit_account_no" required>${depOpts}</select>
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
    
    // Handle form submission
    document.getElementById('editPaymentForm').addEventListener('submit', function(e) {
        e.preventDefault();
        const formData = new FormData(this);
        formData.append('_csrf', getCsrfToken());
        document.getElementById('editPaymentErr').style.display = 'none';
        
        fetch('ajax_edit_payment.php', {
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
</body>
</html>
