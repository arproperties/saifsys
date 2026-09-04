<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/../includes/work_order_adjustment_service.php';
require_role(['Owner', 'Admin', 'Account'], $conn);

if (!function_exists('h')) {
    function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
}
if (!function_exists('money')) {
    function money($n) { return number_format((float)$n, 2); }
}

$statusFilter = trim($_GET['status'] ?? 'pending');
$msg = $_GET['msg'] ?? '';

$where = '1=1';
$params = [];
if ($statusFilter !== '' && $statusFilter !== 'all') {
    $where .= ' AND r.status = :st';
    $params[':st'] = $statusFilter;
}

$sql = "
    SELECT r.*,
           mo.client_name, mo.service_date, mo.status AS order_status,
           i.invoice_no,
           COALESCE(NULLIF(u1.fullname, ''), u1.username) AS requested_by_name,
           COALESCE(NULLIF(u2.fullname, ''), u2.username) AS reviewed_by_name
    FROM sm_adjustment_requests r
    INNER JOIN make_order mo ON mo.id = r.order_id
    LEFT JOIN invoices i ON i.id = r.invoice_id
    LEFT JOIN user u1 ON u1.id = r.requested_by
    LEFT JOIN user u2 ON u2.id = r.reviewed_by
    WHERE $where
    ORDER BY r.requested_at DESC
    LIMIT 200
";

$rows = [];
if (sm_adj_table_exists($conn)) {
    $st = $conn->prepare($sql);
    $st->execute($params);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
}

$pendingCount = 0;
if (sm_adj_table_exists($conn)) {
    $pendingCount = (int)$conn->query("SELECT COUNT(*) FROM sm_adjustment_requests WHERE status = 'pending'")->fetchColumn();
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Adjustment Requests</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
</head>
<body class="bg-light">
<div class="container my-4">
  <div class="d-flex align-items-center mb-3">
    <div>
      <h3 class="mb-0">Service Adjustment Requests</h3>
      <div class="text-muted">Review ops requests for locked / finalized work orders</div>
    </div>
    <div class="ms-auto d-flex gap-2">
      <?php if ($pendingCount > 0): ?>
        <span class="badge bg-warning text-dark align-self-center"><?= $pendingCount ?> pending</span>
      <?php endif; ?>
      <a href="../account" class="btn btn-outline-secondary btn-sm">Back to Accounts</a>
    </div>
  </div>

  <?php if ($msg): ?>
    <div class="alert alert-success"><?= h($msg) ?></div>
  <?php endif; ?>

  <?php if (!sm_adj_table_exists($conn)): ?>
    <div class="alert alert-warning">
      Phase 3 tables not installed. Run <code>tools/sm_apply_phase3_schema.php</code> as Owner/Admin.
    </div>
  <?php else: ?>

  <ul class="nav nav-pills mb-3">
    <?php foreach (['pending' => 'Pending', 'approved' => 'Approved', 'rejected' => 'Rejected', 'all' => 'All'] as $k => $label): ?>
      <li class="nav-item">
        <a class="nav-link <?= ($statusFilter === $k || ($k === 'pending' && $statusFilter === '')) ? 'active' : '' ?>"
           href="?status=<?= h($k) ?>"><?= h($label) ?></a>
      </li>
    <?php endforeach; ?>
  </ul>

  <div class="card shadow-sm border-0">
    <div class="table-responsive">
      <table class="table table-hover mb-0 align-middle">
        <thead class="table-light">
          <tr>
            <th>#</th>
            <th>WO</th>
            <th>Client</th>
            <th>Type</th>
            <th>Current → Requested</th>
            <th>Delta</th>
            <th>Reason</th>
            <th>Requested</th>
            <th>Status</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
        <?php if (!$rows): ?>
          <tr><td colspan="10" class="text-center text-muted py-4">No requests found.</td></tr>
        <?php else: foreach ($rows as $r): ?>
          <tr>
            <td><?= (int)$r['id'] ?></td>
            <td>
              <a href="../operation/order_edit.php?id=<?= (int)$r['order_id'] ?>">#<?= (int)$r['order_id'] ?></a>
              <?php if ($r['invoice_no']): ?>
                <br><small class="text-muted"><?= h($r['invoice_no']) ?></small>
              <?php endif; ?>
            </td>
            <td><?= h($r['client_name']) ?></td>
            <td><span class="badge bg-secondary"><?= h(str_replace('_', ' ', $r['request_type'])) ?></span></td>
            <td>
              AED <?= money($r['current_grand']) ?>
              <?php if ($r['requested_grand'] !== null): ?>
                → <strong>AED <?= money($r['requested_grand']) ?></strong>
              <?php endif; ?>
            </td>
            <td>
              <?php if ($r['delta_grand'] !== null): ?>
                <span class="<?= (float)$r['delta_grand'] < 0 ? 'text-danger' : 'text-success' ?>">
                  <?= (float)$r['delta_grand'] >= 0 ? '+' : '' ?><?= money($r['delta_grand']) ?>
                </span>
              <?php else: ?>—<?php endif; ?>
            </td>
            <td>
              <div><?= h($r['reason']) ?></div>
              <?php if ($r['notes']): ?><small class="text-muted"><?= h($r['notes']) ?></small><?php endif; ?>
            </td>
            <td>
              <small><?= h($r['requested_by_name'] ?? '—') ?></small><br>
              <small class="text-muted"><?= h($r['requested_at']) ?></small>
            </td>
            <td>
              <?php
                $badge = ['pending' => 'warning', 'approved' => 'success', 'rejected' => 'danger', 'cancelled' => 'secondary'];
                $cls = $badge[$r['status']] ?? 'light';
              ?>
              <span class="badge bg-<?= $cls ?>"><?= h($r['status']) ?></span>
              <?php if ($r['resolution_type']): ?>
                <br><small><?= h($r['resolution_type']) ?></small>
              <?php endif; ?>
            </td>
            <td class="text-end">
              <?php if ($r['status'] === 'pending'): ?>
                <button type="button" class="btn btn-sm btn-success btn-adj-approve" data-id="<?= (int)$r['id'] ?>">Approve</button>
                <button type="button" class="btn btn-sm btn-outline-danger btn-adj-reject" data-id="<?= (int)$r['id'] ?>">Reject</button>
              <?php elseif ($r['credit_note_id']): ?>
                <a class="btn btn-sm btn-outline-warning" href="credit_note_view.php?id=<?= (int)$r['credit_note_id'] ?>">CN</a>
              <?php elseif ($r['adjustment_invoice_id']): ?>
                <a class="btn btn-sm btn-outline-primary" href="invoice_view.php?id=<?= (int)$r['adjustment_invoice_id'] ?>">Invoice</a>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
  <?php endif; ?>
</div>

<div class="modal fade" id="reviewModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="reviewModalTitle">Review request</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <input type="hidden" id="review-request-id">
        <input type="hidden" id="review-action">
        <label class="form-label">Notes</label>
        <textarea class="form-control" id="review-notes" rows="3" placeholder="Optional for approve; required for reject"></textarea>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="button" class="btn btn-primary" id="review-submit">Confirm</button>
      </div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
const csrf = <?= json_encode(csrf_token()) ?>;
const modal = new bootstrap.Modal(document.getElementById('reviewModal'));

function openReview(id, action) {
  document.getElementById('review-request-id').value = id;
  document.getElementById('review-action').value = action;
  document.getElementById('review-notes').value = '';
  document.getElementById('reviewModalTitle').textContent = action === 'approve' ? 'Approve adjustment' : 'Reject adjustment';
  modal.show();
}

document.querySelectorAll('.btn-adj-approve').forEach(b => b.addEventListener('click', () => openReview(b.dataset.id, 'approve')));
document.querySelectorAll('.btn-adj-reject').forEach(b => b.addEventListener('click', () => openReview(b.dataset.id, 'reject')));

document.getElementById('review-submit').addEventListener('click', async () => {
  const fd = new FormData();
  fd.append('_csrf', csrf);
  fd.append('request_id', document.getElementById('review-request-id').value);
  fd.append('action', document.getElementById('review-action').value);
  fd.append('review_notes', document.getElementById('review-notes').value);
  const res = await fetch('ajax_adjustment_request.php', { method: 'POST', body: fd });
  const data = await res.json();
  if (data.success) {
    window.location = 'adjustment_requests.php?status=pending&msg=' + encodeURIComponent(data.message);
  } else {
    alert(data.message || 'Failed');
  }
});
</script>
</body>
</html>
