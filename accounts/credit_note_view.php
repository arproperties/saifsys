<?php
require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/../includes/db_connect.php';
require_once __DIR__.'/../includes/ar_helpers.php';
require_once __DIR__.'/../includes/email_service.php';
require_role(['Owner','Admin','Account'], $conn);

$credit_note_id = (int)($_GET['id'] ?? 0);
if ($credit_note_id <= 0) { 
  header("Location: ../account.php?tab=invoices"); 
  exit; 
}

$msg = $err = '';

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_verify();
  
  if (isset($_POST['issue_credit_note'])) {
    try {
      $conn->beginTransaction();
      
      // Update status to issued
      $conn->prepare("UPDATE credit_notes SET status = 'issued', issued_at = NOW() WHERE id = ?")
           ->execute([$credit_note_id]);
      
      // Post to GL
      $credit_note = getCreditNote($conn, $credit_note_id);
      if ($credit_note) {
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
            $credit_note_id, $glAccounts['CN-001']['id'], $credit_note['total_amount'], 0,
            "Credit Note {$credit_note['credit_note_number']} - Revenue Reversal", $_SESSION['user_id'] ?? null
          ]);
        }
        
        // Credit Credit Notes Payable (Liability)
        if (isset($glAccounts['CN-002'])) {
          $glIns->execute([
            $credit_note_id, $glAccounts['CN-002']['id'], 0, $credit_note['total_amount'],
            "Credit Note {$credit_note['credit_note_number']} - Revenue Reversal", $_SESSION['user_id'] ?? null
          ]);
        }
      }
      
      $conn->commit();
      $msg = "Credit note issued successfully.";
      
    } catch (Exception $e) {
      $conn->rollback();
      $err = "Failed to issue credit note: " . $e->getMessage();
    }
  }
  
  if (isset($_POST['void_credit_note'])) {
    try {
      $void_reason = trim($_POST['void_reason'] ?? '');
      if (empty($void_reason)) {
        throw new Exception('Void reason is required');
      }
      
      $conn->beginTransaction();
      
      // Update status to void
      $conn->prepare("UPDATE credit_notes SET status = 'void', voided_at = NOW(), voided_by = ?, void_reason = ? WHERE id = ?")
           ->execute([$_SESSION['user_id'] ?? null, $void_reason, $credit_note_id]);
      
      // Reverse GL postings
      $glDel = $conn->prepare("DELETE FROM credit_note_gl_postings WHERE credit_note_id = ?");
      $glDel->execute([$credit_note_id]);
      
      $conn->commit();
      $msg = "Credit note voided successfully.";
      
    } catch (Exception $e) {
      $conn->rollback();
      $err = "Failed to void credit note: " . $e->getMessage();
    }
  }
}

// Load credit note data
$credit_note = getCreditNote($conn, $credit_note_id);
if (!$credit_note) {
  header("Location: ../account.php?tab=invoices");
  exit;
}

$items = getCreditNoteItems($conn, $credit_note_id);
$allocations = getCreditNoteAllocations($conn, $credit_note_id);
$refunds = getCreditNoteRefunds($conn, $credit_note_id);

function getCreditNote($conn, $id) {
  $stmt = $conn->prepare("
    SELECT cn.*, c.client_name, c.client_email, c.client_phone,
           i.invoice_no, i.invoice_date
    FROM credit_notes cn
    LEFT JOIN client c ON cn.client_id = c.id
    LEFT JOIN invoices i ON cn.invoice_id = i.id
    WHERE cn.id = ?
  ");
  $stmt->execute([$id]);
  return $stmt->fetch(PDO::FETCH_ASSOC);
}

function getCreditNoteItems($conn, $id) {
  $stmt = $conn->prepare("SELECT * FROM credit_note_items WHERE credit_note_id = ? ORDER BY id");
  $stmt->execute([$id]);
  return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function getCreditNoteAllocations($conn, $id) {
  $stmt = $conn->prepare("
    SELECT cna.*, i.invoice_no, i.invoice_date, i.total as invoice_total
    FROM credit_note_allocations cna
    LEFT JOIN invoices i ON cna.invoice_id = i.id
    WHERE cna.credit_note_id = ?
    ORDER BY cna.allocation_date DESC
  ");
  $stmt->execute([$id]);
  return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function getCreditNoteRefunds($conn, $id) {
  $stmt = $conn->prepare("SELECT * FROM refunds WHERE credit_note_id = ? ORDER BY refund_date DESC");
  $stmt->execute([$id]);
  return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function h($str) { return htmlspecialchars($str, ENT_QUOTES, 'UTF-8'); }
function money($amount) { return number_format($amount, 2); }
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Credit Note <?= h($credit_note['credit_note_number']) ?> | BMSystem</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
  <style>
    .status-badge { font-size: 0.875rem; }
    .totals-section { background-color: #f8f9fa; border-radius: 0.375rem; padding: 1rem; }
    .action-buttons .btn { margin-right: 0.5rem; margin-bottom: 0.5rem; }
  </style>
</head>
<body class="bg-light">
<div class="container my-4">
  <div class="row">
    <div class="col-12">
      <div class="d-flex justify-content-between align-items-center mb-4">
        <h2><i class="bi bi-receipt"></i> Credit Note <?= h($credit_note['credit_note_number']) ?></h2>
        <div class="action-buttons">
          <a href="account.php?tab=invoices" class="btn btn-outline-secondary">
            <i class="bi bi-arrow-left"></i> Back to Invoices
          </a>
          <?php if ($credit_note['status'] === 'draft'): ?>
          <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#issueModal">
            <i class="bi bi-check-circle"></i> Issue Credit Note
          </button>
          <?php endif; ?>
          <?php if ($credit_note['status'] === 'issued'): ?>
          <a href="credit_note_print.php?id=<?= $credit_note_id ?>" class="btn btn-outline-primary" target="_blank">
            <i class="bi bi-file-pdf"></i> Print PDF
          </a>
          <button type="button" class="btn btn-outline-success" data-bs-toggle="modal" data-bs-target="#emailModal">
            <i class="bi bi-envelope"></i> Send Email
          </button>
          <button type="button" class="btn btn-outline-warning" data-bs-toggle="modal" data-bs-target="#voidModal">
            <i class="bi bi-x-circle"></i> Void
          </button>
          <?php endif; ?>
        </div>
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

      <div class="row">
        <div class="col-md-8">
          <!-- Credit Note Details -->
          <div class="card mb-4">
            <div class="card-header d-flex justify-content-between align-items-center">
              <h5 class="mb-0">Credit Note Details</h5>
              <span class="badge status-badge bg-<?= $credit_note['status'] === 'issued' ? 'success' : ($credit_note['status'] === 'void' ? 'danger' : 'warning') ?>">
                <?= strtoupper($credit_note['status']) ?>
              </span>
            </div>
            <div class="card-body">
              <div class="row">
                <div class="col-md-6">
                  <table class="table table-sm">
                    <tr>
                      <td><strong>Credit Note Number:</strong></td>
                      <td><?= h($credit_note['credit_note_number']) ?></td>
                    </tr>
                    <tr>
                      <td><strong>Date:</strong></td>
                      <td><?= date('M d, Y', strtotime($credit_note['credit_note_date'])) ?></td>
                    </tr>
                    <tr>
                      <td><strong>Client:</strong></td>
                      <td><?= h($credit_note['client_name']) ?></td>
                    </tr>
                    <?php if ($credit_note['invoice_no']): ?>
                    <tr>
                      <td><strong>Original Invoice:</strong></td>
                      <td>
                        <a href="invoice_view.php?id=<?= $credit_note['invoice_id'] ?>">
                          <?= h($credit_note['invoice_no']) ?>
                        </a>
                      </td>
                    </tr>
                    <?php endif; ?>
                  </table>
                </div>
                <div class="col-md-6">
                  <table class="table table-sm">
                    <tr>
                      <td><strong>Reference:</strong></td>
                      <td><?= h($credit_note['reference'] ?: 'N/A') ?></td>
                    </tr>
                    <tr>
                      <td><strong>Reason:</strong></td>
                      <td><?= ucfirst($credit_note['reason']) ?></td>
                    </tr>
                    <tr>
                      <td><strong>Status:</strong></td>
                      <td>
                        <span class="badge bg-<?= $credit_note['status'] === 'issued' ? 'success' : ($credit_note['status'] === 'void' ? 'danger' : 'warning') ?>">
                          <?= strtoupper($credit_note['status']) ?>
                        </span>
                      </td>
                    </tr>
                    <?php if ($credit_note['issued_at']): ?>
                    <tr>
                      <td><strong>Issued:</strong></td>
                      <td><?= date('M d, Y H:i', strtotime($credit_note['issued_at'])) ?></td>
                    </tr>
                    <?php endif; ?>
                  </table>
                </div>
              </div>
              
              <?php if ($credit_note['reason_description']): ?>
              <div class="mt-3">
                <strong>Reason Description:</strong>
                <p class="mt-1"><?= h($credit_note['reason_description']) ?></p>
              </div>
              <?php endif; ?>
              
              <?php if ($credit_note['notes']): ?>
              <div class="mt-3">
                <strong>Notes:</strong>
                <p class="mt-1"><?= h($credit_note['notes']) ?></p>
              </div>
              <?php endif; ?>
            </div>
          </div>

          <!-- Line Items -->
          <div class="card mb-4">
            <div class="card-header">
              <h5 class="mb-0">Line Items</h5>
            </div>
            <div class="card-body">
              <div class="table-responsive">
                <table class="table table-striped">
                  <thead>
                    <tr>
                      <th>Description</th>
                      <th class="text-end">Qty</th>
                      <th class="text-end">Unit Price</th>
                      <th class="text-end">Tax %</th>
                      <th class="text-end">Tax Amount</th>
                      <th class="text-end">Total</th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php foreach ($items as $item): ?>
                    <tr>
                      <td><?= h($item['description']) ?></td>
                      <td class="text-end"><?= number_format($item['quantity'], 3) ?></td>
                      <td class="text-end">AED <?= money($item['unit_price']) ?></td>
                      <td class="text-end"><?= money($item['tax_rate']) ?>%</td>
                      <td class="text-end">AED <?= money($item['tax_amount']) ?></td>
                      <td class="text-end">AED <?= money($item['line_total']) ?></td>
                    </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
            </div>
          </div>

          <!-- Allocations -->
          <?php if (!empty($allocations)): ?>
          <div class="card mb-4">
            <div class="card-header">
              <h5 class="mb-0">Allocations</h5>
            </div>
            <div class="card-body">
              <div class="table-responsive">
                <table class="table table-striped">
                  <thead>
                    <tr>
                      <th>Invoice</th>
                      <th class="text-end">Allocated Amount</th>
                      <th>Date</th>
                      <th>Notes</th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php foreach ($allocations as $allocation): ?>
                    <tr>
                      <td>
                        <a href="invoice_view.php?id=<?= $allocation['invoice_id'] ?>">
                          <?= h($allocation['invoice_no']) ?>
                        </a>
                      </td>
                      <td class="text-end">AED <?= money($allocation['allocated_amount']) ?></td>
                      <td><?= date('M d, Y', strtotime($allocation['allocation_date'])) ?></td>
                      <td><?= h($allocation['notes'] ?: 'N/A') ?></td>
                    </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
            </div>
          </div>
          <?php endif; ?>

          <!-- Refunds -->
          <?php if (!empty($refunds)): ?>
          <div class="card mb-4">
            <div class="card-header">
              <h5 class="mb-0">Refunds</h5>
            </div>
            <div class="card-body">
              <div class="table-responsive">
                <table class="table table-striped">
                  <thead>
                    <tr>
                      <th>Refund Number</th>
                      <th class="text-end">Amount</th>
                      <th>Method</th>
                      <th>Status</th>
                      <th>Date</th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php foreach ($refunds as $refund): ?>
                    <tr>
                      <td><?= h($refund['refund_number']) ?></td>
                      <td class="text-end">AED <?= money($refund['refund_amount']) ?></td>
                      <td><?= ucfirst(str_replace('_', ' ', $refund['refund_method'])) ?></td>
                      <td>
                        <span class="badge bg-<?= $refund['status'] === 'processed' ? 'success' : ($refund['status'] === 'cancelled' ? 'danger' : 'warning') ?>">
                          <?= strtoupper($refund['status']) ?>
                        </span>
                      </td>
                      <td><?= date('M d, Y', strtotime($refund['refund_date'])) ?></td>
                    </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
            </div>
          </div>
          <?php endif; ?>
        </div>

        <div class="col-md-4">
          <!-- Totals -->
          <div class="card mb-4">
            <div class="card-header">
              <h5 class="mb-0">Totals</h5>
            </div>
            <div class="card-body totals-section">
              <div class="row mb-2">
                <div class="col-6">Subtotal:</div>
                <div class="col-6 text-end">AED <?= money($credit_note['subtotal']) ?></div>
              </div>
              <div class="row mb-2">
                <div class="col-6">Tax:</div>
                <div class="col-6 text-end">AED <?= money($credit_note['tax_amount']) ?></div>
              </div>
              <hr>
              <div class="row">
                <div class="col-6"><strong>Total:</strong></div>
                <div class="col-6 text-end"><strong>AED <?= money($credit_note['total_amount']) ?></strong></div>
              </div>
            </div>
          </div>

          <!-- Client Information -->
          <div class="card mb-4">
            <div class="card-header">
              <h5 class="mb-0">Client Information</h5>
            </div>
            <div class="card-body">
              <p><strong><?= h($credit_note['client_name']) ?></strong></p>
              <?php if ($credit_note['client_email']): ?>
              <p><i class="bi bi-envelope"></i> <?= h($credit_note['client_email']) ?></p>
              <?php endif; ?>
              <?php if ($credit_note['client_phone']): ?>
              <p><i class="bi bi-telephone"></i> <?= h($credit_note['client_phone']) ?></p>
              <?php endif; ?>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- Issue Credit Note Modal -->
<div class="modal fade" id="issueModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="POST">
        <div class="modal-header">
          <h5 class="modal-title">Issue Credit Note</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <p>Are you sure you want to issue this credit note? This action will:</p>
          <ul>
            <li>Change the status to "Issued"</li>
            <li>Post entries to the General Ledger</li>
            <li>Make the credit note available for allocations</li>
          </ul>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" name="issue_credit_note" class="btn btn-primary">Issue Credit Note</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Void Credit Note Modal -->
<div class="modal fade" id="voidModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="POST">
        <div class="modal-header">
          <h5 class="modal-title">Void Credit Note</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="mb-3">
            <label for="void_reason" class="form-label">Void Reason <span class="text-danger">*</span></label>
            <textarea class="form-control" id="void_reason" name="void_reason" rows="3" required 
                      placeholder="Enter the reason for voiding this credit note"></textarea>
          </div>
          <p class="text-danger"><strong>Warning:</strong> This action cannot be undone and will reverse all GL postings.</p>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" name="void_credit_note" class="btn btn-danger">Void Credit Note</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Email Modal -->
<div class="modal fade" id="emailModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="POST" id="emailForm">
        <div class="modal-header">
          <h5 class="modal-title">Send Credit Note by Email</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="mb-3">
            <label for="email_to" class="form-label">To</label>
            <input type="email" class="form-control" id="email_to" name="email_to" 
                   value="<?= h($credit_note['client_email'] ?? '') ?>" required>
          </div>
          <div class="mb-3">
            <label for="email_subject" class="form-label">Subject</label>
            <input type="text" class="form-control" id="email_subject" name="email_subject" 
                   value="Credit Note <?= h($credit_note['credit_note_number']) ?>" required>
          </div>
          <div class="mb-3">
            <label for="email_message" class="form-label">Message</label>
            <textarea class="form-control" id="email_message" name="email_message" rows="4" 
                      placeholder="Optional message to include with the credit note"></textarea>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" name="send_email" class="btn btn-primary">Send Email</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
// Handle email form submission
document.getElementById('emailForm').addEventListener('submit', function(e) {
  e.preventDefault();
  
  const formData = new FormData(this);
  formData.append('credit_note_id', <?= $credit_note_id ?>);
  
  fetch('ajax/send_credit_note_email.php', {
    method: 'POST',
    body: formData
  })
  .then(response => response.json())
  .then(data => {
    if (data.success) {
      alert('Email sent successfully!');
      bootstrap.Modal.getInstance(document.getElementById('emailModal')).hide();
    } else {
      alert('Error sending email: ' + (data.error || 'Unknown error'));
    }
  })
  .catch(error => {
    alert('Error sending email: ' + error.message);
  });
});
</script>
</body>
</html>
