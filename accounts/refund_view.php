<?php
require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/../includes/db_connect.php';
require_once __DIR__.'/../includes/ar_helpers.php';
require_role(['Owner','Admin','Account'], $conn);

$refund_id = (int)($_GET['id'] ?? 0);
if ($refund_id <= 0) { 
  header("Location: refunds.php"); 
  exit; 
}

$msg = $err = '';

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_verify();
  
  if (isset($_POST['process_refund'])) {
    try {
      $conn->beginTransaction();
      
      // Update refund status
      $conn->prepare("UPDATE refunds SET status = 'processed', processed_at = NOW(), processed_by = ? WHERE id = ?")
           ->execute([$_SESSION['user_id'] ?? null, $refund_id]);
      
      $conn->commit();
      $msg = "Refund processed successfully.";
      
    } catch (Exception $e) {
      $conn->rollback();
      $err = "Failed to process refund: " . $e->getMessage();
    }
  }
  
  if (isset($_POST['cancel_refund'])) {
    try {
      $conn->beginTransaction();
      
      // Update refund status
      $conn->prepare("UPDATE refunds SET status = 'cancelled' WHERE id = ?")
           ->execute([$refund_id]);
      
      $conn->commit();
      $msg = "Refund cancelled successfully.";
      
    } catch (Exception $e) {
      $conn->rollback();
      $err = "Failed to cancel refund: " . $e->getMessage();
    }
  }
}

// Load refund data
$stmt = $conn->prepare("
  SELECT r.*, cn.credit_note_number, cn.credit_note_date, cn.total_amount as credit_note_total,
         cn.reason, cn.reason_description, cn.notes as credit_note_notes,
         c.client_name, c.client_email, c.client_phone, c.client_address,
         c.client_city, c.client_state, c.client_zip, c.client_country
  FROM refunds r
  LEFT JOIN credit_notes cn ON r.credit_note_id = cn.id
  LEFT JOIN client c ON cn.client_id = c.id
  WHERE r.id = ?
");
$stmt->execute([$refund_id]);
$refund = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$refund) {
  header("Location: refunds.php");
  exit;
}

function h($str) { return htmlspecialchars($str, ENT_QUOTES, 'UTF-8'); }
function money($amount) { return number_format($amount, 2); }
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Refund <?= h($refund['refund_number']) ?> | BMSystem</title>
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
        <h2><i class="bi bi-cash-coin"></i> Refund <?= h($refund['refund_number']) ?></h2>
        <div class="action-buttons">
          <a href="refunds.php" class="btn btn-outline-secondary">
            <i class="bi bi-arrow-left"></i> Back to Refunds
          </a>
          <?php if ($refund['status'] === 'pending'): ?>
          <button type="button" class="btn btn-success" data-bs-toggle="modal" data-bs-target="#processModal">
            <i class="bi bi-check-circle"></i> Process Refund
          </button>
          <button type="button" class="btn btn-danger" data-bs-toggle="modal" data-bs-target="#cancelModal">
            <i class="bi bi-x-circle"></i> Cancel Refund
          </button>
          <?php endif; ?>
          <?php if ($refund['status'] === 'processed'): ?>
          <a href="refund_print.php?id=<?= $refund_id ?>" class="btn btn-outline-primary" target="_blank">
            <i class="bi bi-file-pdf"></i> Print Receipt
          </a>
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
          <!-- Refund Details -->
          <div class="card mb-4">
            <div class="card-header d-flex justify-content-between align-items-center">
              <h5 class="mb-0">Refund Details</h5>
              <span class="badge status-badge bg-<?= $refund['status'] === 'processed' ? 'success' : ($refund['status'] === 'cancelled' ? 'danger' : 'warning') ?>">
                <?= strtoupper($refund['status']) ?>
              </span>
            </div>
            <div class="card-body">
              <div class="row">
                <div class="col-md-6">
                  <table class="table table-sm">
                    <tr>
                      <td><strong>Refund Number:</strong></td>
                      <td><?= h($refund['refund_number']) ?></td>
                    </tr>
                    <tr>
                      <td><strong>Date:</strong></td>
                      <td><?= date('M d, Y', strtotime($refund['refund_date'])) ?></td>
                    </tr>
                    <tr>
                      <td><strong>Amount:</strong></td>
                      <td><strong>AED <?= money($refund['refund_amount']) ?></strong></td>
                    </tr>
                    <tr>
                      <td><strong>Method:</strong></td>
                      <td><?= ucfirst(str_replace('_', ' ', $refund['refund_method'])) ?></td>
                    </tr>
                  </table>
                </div>
                <div class="col-md-6">
                  <table class="table table-sm">
                    <tr>
                      <td><strong>Status:</strong></td>
                      <td>
                        <span class="badge bg-<?= $refund['status'] === 'processed' ? 'success' : ($refund['status'] === 'cancelled' ? 'danger' : 'warning') ?>">
                          <?= strtoupper($refund['status']) ?>
                        </span>
                      </td>
                    </tr>
                    <?php if ($refund['refund_reference']): ?>
                    <tr>
                      <td><strong>Reference:</strong></td>
                      <td><?= h($refund['refund_reference']) ?></td>
                    </tr>
                    <?php endif; ?>
                    <?php if ($refund['processed_at']): ?>
                    <tr>
                      <td><strong>Processed:</strong></td>
                      <td><?= date('M d, Y H:i', strtotime($refund['processed_at'])) ?></td>
                    </tr>
                    <?php endif; ?>
                    <tr>
                      <td><strong>Created:</strong></td>
                      <td><?= date('M d, Y H:i', strtotime($refund['created_at'])) ?></td>
                    </tr>
                  </table>
                </div>
              </div>
              
              <?php if ($refund['notes']): ?>
              <div class="mt-3">
                <strong>Notes:</strong>
                <p class="mt-1"><?= h($refund['notes']) ?></p>
              </div>
              <?php endif; ?>
            </div>
          </div>

          <!-- Credit Note Information -->
          <div class="card mb-4">
            <div class="card-header">
              <h5 class="mb-0">Related Credit Note</h5>
            </div>
            <div class="card-body">
              <div class="row">
                <div class="col-md-6">
                  <p><strong>Credit Note:</strong> 
                    <a href="credit_note_view.php?id=<?= $refund['credit_note_id'] ?>">
                      <?= h($refund['credit_note_number']) ?>
                    </a>
                  </p>
                  <p><strong>Date:</strong> <?= date('M d, Y', strtotime($refund['credit_note_date'])) ?></p>
                  <p><strong>Total Amount:</strong> AED <?= money($refund['credit_note_total']) ?></p>
                </div>
                <div class="col-md-6">
                  <p><strong>Reason:</strong> <?= ucfirst($refund['reason']) ?></p>
                  <?php if ($refund['reason_description']): ?>
                  <p><strong>Description:</strong> <?= h($refund['reason_description']) ?></p>
                  <?php endif; ?>
                </div>
              </div>
              
              <?php if ($refund['credit_note_notes']): ?>
              <div class="mt-3">
                <strong>Credit Note Notes:</strong>
                <p class="mt-1"><?= h($refund['credit_note_notes']) ?></p>
              </div>
              <?php endif; ?>
            </div>
          </div>
        </div>

        <div class="col-md-4">
          <!-- Client Information -->
          <div class="card mb-4">
            <div class="card-header">
              <h5 class="mb-0">Client Information</h5>
            </div>
            <div class="card-body">
              <p><strong><?= h($refund['client_name']) ?></strong></p>
              <?php if ($refund['client_email']): ?>
              <p><i class="bi bi-envelope"></i> <?= h($refund['client_email']) ?></p>
              <?php endif; ?>
              <?php if ($refund['client_phone']): ?>
              <p><i class="bi bi-telephone"></i> <?= h($refund['client_phone']) ?></p>
              <?php endif; ?>
              <?php if ($refund['client_address']): ?>
              <p><i class="bi bi-geo-alt"></i> 
                <?= h($refund['client_address']) ?><br>
                <?= h($refund['client_city']) ?><?= $refund['client_state'] ? ', ' . h($refund['client_state']) : '' ?> <?= h($refund['client_zip']) ?><br>
                <?= h($refund['client_country']) ?>
              </p>
              <?php endif; ?>
            </div>
          </div>

          <!-- Refund Summary -->
          <div class="card mb-4">
            <div class="card-header">
              <h5 class="mb-0">Refund Summary</h5>
            </div>
            <div class="card-body totals-section">
              <div class="row mb-2">
                <div class="col-6">Credit Note Total:</div>
                <div class="col-6 text-end">AED <?= money($refund['credit_note_total']) ?></div>
              </div>
              <div class="row mb-2">
                <div class="col-6">Refund Amount:</div>
                <div class="col-6 text-end"><strong>AED <?= money($refund['refund_amount']) ?></strong></div>
              </div>
              <hr>
              <div class="row">
                <div class="col-6">Remaining Credit:</div>
                <div class="col-6 text-end">AED <?= money($refund['credit_note_total'] - $refund['refund_amount']) ?></div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- Process Refund Modal -->
<div class="modal fade" id="processModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="POST">
        <div class="modal-header">
          <h5 class="modal-title">Process Refund</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <p>Are you sure you want to process this refund? This action will:</p>
          <ul>
            <li>Mark the refund as processed</li>
            <li>Update the refund status</li>
            <li>Record the processing timestamp</li>
          </ul>
          <p><strong>Refund Amount:</strong> AED <?= money($refund['refund_amount']) ?></p>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" name="process_refund" class="btn btn-success">Process Refund</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Cancel Refund Modal -->
<div class="modal fade" id="cancelModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="POST">
        <div class="modal-header">
          <h5 class="modal-title">Cancel Refund</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <p>Are you sure you want to cancel this refund? This action will:</p>
          <ul>
            <li>Mark the refund as cancelled</li>
            <li>Prevent further processing</li>
          </ul>
          <p class="text-danger"><strong>Warning:</strong> This action cannot be undone.</p>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" name="cancel_refund" class="btn btn-danger">Cancel Refund</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
