<?php
require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/../includes/db_connect.php';
require_once __DIR__.'/../includes/ar_helpers.php';
require_role(['Owner','Admin','Account'], $conn);

$msg = $err = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  try {
    if (isset($_POST['create_refund'])) {
      $credit_note_id = (int)($_POST['credit_note_id'] ?? 0);
      $refund_amount = (float)($_POST['refund_amount'] ?? 0);
      $refund_method = $_POST['refund_method'] ?? 'cash';
      $refund_reference = trim($_POST['refund_reference'] ?? '');
      $notes = trim($_POST['notes'] ?? '');
      
      if ($credit_note_id <= 0) {
        throw new Exception('Please select a credit note');
      }
      
      if ($refund_amount <= 0) {
        throw new Exception('Refund amount must be greater than zero');
      }
      
      // Get credit note details
      $stmt = $conn->prepare("SELECT * FROM credit_notes WHERE id = ? AND status = 'issued'");
      $stmt->execute([$credit_note_id]);
      $credit_note = $stmt->fetch(PDO::FETCH_ASSOC);
      
      if (!$credit_note) {
        throw new Exception('Credit note not found or not issued');
      }
      
      // Check if refund amount doesn't exceed credit note total
      if ($refund_amount > $credit_note['total_amount']) {
        throw new Exception('Refund amount cannot exceed credit note total');
      }
      
      // Generate refund number
      $year = date('Y');
      $maxQ = $conn->prepare("SELECT MAX(CAST(SUBSTRING_INDEX(refund_number,'-',-1) AS UNSIGNED)) FROM refunds WHERE refund_number LIKE ?");
      $maxQ->execute(["REF-$year-%"]);
      $next = (int)($maxQ->fetchColumn() ?: 0) + 1;
      $refund_number = sprintf("REF-%s-%05d", $year, $next);
      
      $conn->beginTransaction();
      
      // Insert refund
      $ins = $conn->prepare("
        INSERT INTO refunds
          (credit_note_id, refund_number, refund_date, refund_amount, refund_method, 
           refund_reference, status, notes, created_by)
        VALUES (?, ?, ?, ?, ?, ?, 'pending', ?, ?)
      ");
      
      $ins->execute([
        $credit_note_id, $refund_number, date('Y-m-d'), $refund_amount, $refund_method,
        $refund_reference, $notes, $_SESSION['user_id'] ?? null
      ]);
      
      $refund_id = $conn->lastInsertId();
      
      // Post to GL
      $glAccounts = [];
      $glQ = $conn->prepare("SELECT id, account_no, name FROM chart_of_accounts WHERE account_no IN ('CN-003', 'CN-001')");
      $glQ->execute();
      while ($row = $glQ->fetch(PDO::FETCH_ASSOC)) {
        $glAccounts[$row['account_no']] = $row;
      }
      
      // Post refund to GL
      $glIns = $conn->prepare("
        INSERT INTO credit_note_gl_postings
          (credit_note_id, gl_account_id, debit_amount, credit_amount, description, created_by)
        VALUES (?, ?, ?, ?, ?, ?)
      ");
      
      // Debit Refunds Payable (Liability)
      if (isset($glAccounts['CN-003'])) {
        $glIns->execute([
          $credit_note_id, $glAccounts['CN-003']['id'], $refund_amount, 0,
          "Refund $refund_number - Cash Payment", $_SESSION['user_id'] ?? null
        ]);
      }
      
      // Credit Credit Notes Receivable (Asset)
      if (isset($glAccounts['CN-001'])) {
        $glIns->execute([
          $credit_note_id, $glAccounts['CN-001']['id'], 0, $refund_amount,
          "Refund $refund_number - Cash Payment", $_SESSION['user_id'] ?? null
        ]);
      }
      
      $conn->commit();
      $msg = "Refund created successfully.";
      
    } elseif (isset($_POST['process_refund'])) {
      $refund_id = (int)($_POST['refund_id'] ?? 0);
      
      if ($refund_id <= 0) {
        throw new Exception('Invalid refund ID');
      }
      
      $conn->beginTransaction();
      
      // Update refund status
      $conn->prepare("UPDATE refunds SET status = 'processed', processed_at = NOW(), processed_by = ? WHERE id = ?")
           ->execute([$_SESSION['user_id'] ?? null, $refund_id]);
      
      $conn->commit();
      $msg = "Refund processed successfully.";
      
    } elseif (isset($_POST['cancel_refund'])) {
      $refund_id = (int)($_POST['refund_id'] ?? 0);
      
      if ($refund_id <= 0) {
        throw new Exception('Invalid refund ID');
      }
      
      $conn->beginTransaction();
      
      // Update refund status
      $conn->prepare("UPDATE refunds SET status = 'cancelled' WHERE id = ?")
           ->execute([$refund_id]);
      
      $conn->commit();
      $msg = "Refund cancelled successfully.";
    }
    
  } catch (Exception $e) {
    if (isset($conn)) $conn->rollback();
    $err = "Error: " . $e->getMessage();
  }
}

// Get refunds with filters
$search = $_GET['search'] ?? '';
$status_filter = $_GET['status'] ?? '';
$date_from = $_GET['date_from'] ?? '';
$date_to = $_GET['date_to'] ?? '';

$where_conditions = [];
$params = [];

if (!empty($search)) {
  $where_conditions[] = "(r.refund_number LIKE ? OR cn.credit_note_number LIKE ? OR c.client_name LIKE ?)";
  $search_param = "%$search%";
  $params[] = $search_param;
  $params[] = $search_param;
  $params[] = $search_param;
}

if (!empty($status_filter)) {
  $where_conditions[] = "r.status = ?";
  $params[] = $status_filter;
}

if (!empty($date_from)) {
  $where_conditions[] = "r.refund_date >= ?";
  $params[] = $date_from;
}

if (!empty($date_to)) {
  $where_conditions[] = "r.refund_date <= ?";
  $params[] = $date_to;
}

$where_clause = !empty($where_conditions) ? 'WHERE ' . implode(' AND ', $where_conditions) : '';

$sql = "
  SELECT r.*, cn.credit_note_number, cn.total_amount as credit_note_total,
         c.client_name, c.client_email
  FROM refunds r
  LEFT JOIN credit_notes cn ON r.credit_note_id = cn.id
  LEFT JOIN client c ON cn.client_id = c.id
  $where_clause
  ORDER BY r.created_at DESC
  LIMIT 50
";

$stmt = $conn->prepare($sql);
$stmt->execute($params);
$refunds = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get credit notes for dropdown
$credit_notes = [];
$cnQ = $conn->prepare("
  SELECT cn.id, cn.credit_note_number, cn.total_amount, c.client_name
  FROM credit_notes cn
  LEFT JOIN client c ON cn.client_id = c.id
  WHERE cn.status = 'issued'
  ORDER BY cn.credit_note_date DESC
");
$cnQ->execute();
while ($row = $cnQ->fetch(PDO::FETCH_ASSOC)) {
  $credit_notes[] = $row;
}

function h($str) { return htmlspecialchars($str, ENT_QUOTES, 'UTF-8'); }
function money($amount) { return number_format($amount, 2); }
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Refunds Management | BMSystem</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
</head>
<body class="bg-light">
<div class="container my-4">
  <div class="row">
    <div class="col-12">
      <div class="d-flex justify-content-between align-items-center mb-4">
        <h2><i class="bi bi-cash-coin"></i> Refunds Management</h2>
        <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#createRefundModal">
          <i class="bi bi-plus"></i> Create Refund
        </button>
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

      <!-- Filters -->
      <div class="card mb-4">
        <div class="card-body">
          <form method="GET" class="row g-3">
            <div class="col-md-3">
              <label for="search" class="form-label">Search</label>
              <input type="text" class="form-control" id="search" name="search" 
                     value="<?= h($search) ?>" placeholder="Refund number, credit note, client">
            </div>
            <div class="col-md-2">
              <label for="status" class="form-label">Status</label>
              <select class="form-select" id="status" name="status">
                <option value="">All Status</option>
                <option value="pending" <?= $status_filter === 'pending' ? 'selected' : '' ?>>Pending</option>
                <option value="processed" <?= $status_filter === 'processed' ? 'selected' : '' ?>>Processed</option>
                <option value="cancelled" <?= $status_filter === 'cancelled' ? 'selected' : '' ?>>Cancelled</option>
              </select>
            </div>
            <div class="col-md-2">
              <label for="date_from" class="form-label">From Date</label>
              <input type="date" class="form-control" id="date_from" name="date_from" value="<?= h($date_from) ?>">
            </div>
            <div class="col-md-2">
              <label for="date_to" class="form-label">To Date</label>
              <input type="date" class="form-control" id="date_to" name="date_to" value="<?= h($date_to) ?>">
            </div>
            <div class="col-md-3">
              <label class="form-label">&nbsp;</label>
              <div class="d-flex gap-2">
                <button type="submit" class="btn btn-outline-primary">Filter</button>
                <a href="refunds.php" class="btn btn-outline-secondary">Clear</a>
              </div>
            </div>
          </form>
        </div>
      </div>

      <!-- Refunds Table -->
      <div class="card">
        <div class="card-body">
          <div class="table-responsive">
            <table class="table table-striped">
              <thead>
                <tr>
                  <th>Refund #</th>
                  <th>Credit Note</th>
                  <th>Client</th>
                  <th class="text-end">Amount</th>
                  <th>Method</th>
                  <th>Status</th>
                  <th>Date</th>
                  <th>Actions</th>
                </tr>
              </thead>
              <tbody>
                <?php if (empty($refunds)): ?>
                <tr>
                  <td colspan="8" class="text-center text-muted">No refunds found</td>
                </tr>
                <?php else: ?>
                <?php foreach ($refunds as $refund): ?>
                <tr>
                  <td><?= h($refund['refund_number']) ?></td>
                  <td>
                    <a href="credit_note_view.php?id=<?= $refund['credit_note_id'] ?>">
                      <?= h($refund['credit_note_number']) ?>
                    </a>
                  </td>
                  <td><?= h($refund['client_name']) ?></td>
                  <td class="text-end">AED <?= money($refund['refund_amount']) ?></td>
                  <td><?= ucfirst(str_replace('_', ' ', $refund['refund_method'])) ?></td>
                  <td>
                    <span class="badge bg-<?= $refund['status'] === 'processed' ? 'success' : ($refund['status'] === 'cancelled' ? 'danger' : 'warning') ?>">
                      <?= strtoupper($refund['status']) ?>
                    </span>
                  </td>
                  <td><?= date('M d, Y', strtotime($refund['refund_date'])) ?></td>
                  <td>
                    <div class="btn-group btn-group-sm">
                      <?php if ($refund['status'] === 'pending'): ?>
                      <form method="POST" style="display: inline;">
                        <input type="hidden" name="refund_id" value="<?= $refund['id'] ?>">
                        <button type="submit" name="process_refund" class="btn btn-sm btn-success" 
                                onclick="return confirm('Process this refund?')">
                          <i class="bi bi-check"></i> Process
                        </button>
                      </form>
                      <form method="POST" style="display: inline;">
                        <input type="hidden" name="refund_id" value="<?= $refund['id'] ?>">
                        <button type="submit" name="cancel_refund" class="btn btn-sm btn-danger" 
                                onclick="return confirm('Cancel this refund?')">
                          <i class="bi bi-x"></i> Cancel
                        </button>
                      </form>
                      <?php endif; ?>
                      <a href="refund_view.php?id=<?= $refund['id'] ?>" class="btn btn-sm btn-outline-primary">
                        <i class="bi bi-eye"></i> View
                      </a>
                    </div>
                  </td>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- Create Refund Modal -->
<div class="modal fade" id="createRefundModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="POST">
        <div class="modal-header">
          <h5 class="modal-title">Create Refund</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="mb-3">
            <label for="credit_note_id" class="form-label">Credit Note <span class="text-danger">*</span></label>
            <select class="form-select" id="credit_note_id" name="credit_note_id" required>
              <option value="">Select Credit Note</option>
              <?php foreach ($credit_notes as $cn): ?>
              <option value="<?= $cn['id'] ?>" data-amount="<?= $cn['total_amount'] ?>">
                <?= h($cn['credit_note_number']) ?> - <?= h($cn['client_name']) ?> (AED <?= money($cn['total_amount']) ?>)
              </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="mb-3">
            <label for="refund_amount" class="form-label">Refund Amount <span class="text-danger">*</span></label>
            <input type="number" class="form-control" id="refund_amount" name="refund_amount" 
                   step="0.01" min="0" required>
          </div>
          <div class="mb-3">
            <label for="refund_method" class="form-label">Refund Method <span class="text-danger">*</span></label>
            <select class="form-select" id="refund_method" name="refund_method" required>
              <option value="cash">Cash</option>
              <option value="check">Check</option>
              <option value="bank_transfer">Bank Transfer</option>
              <option value="other">Other</option>
            </select>
          </div>
          <div class="mb-3">
            <label for="refund_reference" class="form-label">Reference</label>
            <input type="text" class="form-control" id="refund_reference" name="refund_reference" 
                   placeholder="Check number, transaction ID, etc.">
          </div>
          <div class="mb-3">
            <label for="notes" class="form-label">Notes</label>
            <textarea class="form-control" id="notes" name="notes" rows="3" 
                      placeholder="Additional notes about this refund"></textarea>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" name="create_refund" class="btn btn-primary">Create Refund</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
// Auto-fill refund amount when credit note is selected
document.getElementById('credit_note_id').addEventListener('change', function() {
  const selectedOption = this.options[this.selectedIndex];
  const amount = selectedOption.getAttribute('data-amount');
  if (amount) {
    document.getElementById('refund_amount').value = amount;
    document.getElementById('refund_amount').max = amount;
  }
});
</script>
</body>
</html>
