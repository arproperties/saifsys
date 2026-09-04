<?php
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../includes/branding.php';
require_once __DIR__ . '/../../includes/company_helper.php';
require_once __DIR__ . '/../../includes/module_access.php';
require_once __DIR__ . '/../../includes/rbac_department.php';
require_once __DIR__ . '/../../includes/permissions.php';
require_once __DIR__ . '/../../includes/inventory/inv_requests.php';
require_once __DIR__ . '/../../includes/inventory/inv_helpers.php';
require_once __DIR__ . '/../../includes/inventory/inv_request_create_helpers.php';

require_login();
require_module_access($conn, MODULE_INVENTORY);
require_department_access(MODULE_INVENTORY, DEPT_INVENTORY, $conn);
require_permission('inventory_requests.view', MODULE_INVENTORY, $conn);

$brand = getBrandSettings($conn);
$companyId = current_company_id($conn) ?: 0;
$requestId = (int)($_GET['id'] ?? 0);
$message = $messageType = '';

if (!$requestId || !$companyId) {
    header('Location: requests.php');
    exit;
}

$hStmt = $conn->prepare("SELECT r.*, u.username AS requested_username, a.username AS approved_username
    FROM inv_request_headers r
    LEFT JOIN user u ON u.id = r.requested_by
    LEFT JOIN user a ON a.id = r.approved_by
    WHERE r.id = ? AND r.company_id = ?");
$hStmt->execute([$requestId, $companyId]);
$req = $hStmt->fetch(PDO::FETCH_ASSOC);
if (!$req) {
    header('Location: requests.php');
    exit;
}

$lStmt = $conn->prepare("
    SELECT l.*, i.item_code, i.name AS item_name
    FROM inv_request_lines l
    JOIN inv_items i ON i.id = l.item_id AND i.company_id = l.company_id
    WHERE l.header_id = ? AND l.company_id = ?
    ORDER BY l.sort_order ASC, l.id ASC
");
$lStmt->execute([$requestId, $companyId]);
$lines = $lStmt->fetchAll(PDO::FETCH_ASSOC);

$locations = [];
if ($companyId) {
    $locStmt = $conn->prepare("SELECT id, code, name FROM inv_locations WHERE company_id = ? AND is_active = 1 ORDER BY name");
    $locStmt->execute([$companyId]);
    $locations = $locStmt->fetchAll(PDO::FETCH_ASSOC);
}

$isOwnerAdmin = inv_is_inventory_owner_or_admin($conn);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $_POST['action'] ?? '';

    if ($action === 'approve_issue') {
        require_permission('inventory_requests.approve', MODULE_INVENTORY, $conn);
        if (($req['status'] ?? '') !== 'pending') {
            $message = 'Request is not pending.';
            $messageType = 'warning';
        } else {
            $approved = [];
            foreach ($lines as $ln) {
                $lid = (int)$ln['id'];
                $key = 'appr_' . $lid;
                if (isset($_POST[$key]) && $_POST[$key] !== '') {
                    $approved[$lid] = (float)$_POST[$key];
                }
            }
            $allowNeg = $isOwnerAdmin && !empty($_POST['allow_negative']);
            $apprLoc = (int)($_POST['location_from_id'] ?? 0);
            $res = inv_request_approve_and_issue(
                $conn,
                $requestId,
                $companyId,
                current_user_id() ?: 0,
                $approved,
                $allowNeg,
                $apprLoc > 0 ? $apprLoc : null
            );
            if (!empty($res['success'])) {
                header('Location: request_view.php?id=' . $requestId);
                exit;
            }
            $message = $res['error'] ?? 'Approval failed.';
            $messageType = 'warning';
        }
    } elseif ($action === 'reject') {
        require_permission('inventory_requests.reject', MODULE_INVENTORY, $conn);
        if (($req['status'] ?? '') !== 'pending') {
            $message = 'Request is not pending.';
            $messageType = 'warning';
        } else {
            $reason = trim($_POST['rejection_reason'] ?? '');
            $res = inv_request_reject($conn, $requestId, $companyId, current_user_id() ?: 0, $reason);
            if (!empty($res['success'])) {
                header('Location: request_view.php?id=' . $requestId);
                exit;
            }
            $message = $res['error'] ?? 'Reject failed.';
            $messageType = 'warning';
        }
    }

    $hStmt->execute([$requestId, $companyId]);
    $req = $hStmt->fetch(PDO::FETCH_ASSOC);
    $lStmt->execute([$requestId, $companyId]);
    $lines = $lStmt->fetchAll(PDO::FETCH_ASSOC);
}

$locLabel = '';
if (!empty($req['location_from_id'])) {
    $ls = $conn->prepare("SELECT code, name FROM inv_locations WHERE id = ? AND company_id = ? LIMIT 1");
    $ls->execute([(int)$req['location_from_id'], $companyId]);
    $lr = $ls->fetch(PDO::FETCH_ASSOC);
    if ($lr) {
        $locLabel = $lr['code'] . ' — ' . $lr['name'];
    }
}

$ctxLabels = inv_request_resolve_context_labels($conn, $companyId, [
    'context_building_id' => !empty($req['context_building_id']) ? (int)$req['context_building_id'] : null,
    'context_unit_id' => !empty($req['context_unit_id']) ? (int)$req['context_unit_id'] : null,
    'context_project_id' => !empty($req['context_project_id']) ? (int)$req['context_project_id'] : null,
    'context_booking_id' => !empty($req['context_booking_id']) ? (int)$req['context_booking_id'] : null,
    'context_work_order_id' => !empty($req['context_work_order_id']) ? (int)$req['context_work_order_id'] : null,
    'context_housekeeping_id' => !empty($req['context_housekeeping_id']) ? (int)$req['context_housekeeping_id'] : null,
    'context_cleaning_job_id' => !empty($req['context_cleaning_job_id']) ? (int)$req['context_cleaning_job_id'] : null,
]);
$ctxTitles = inv_material_request_context_field_titles();

$canApprove = has_permission('inventory_requests.approve', MODULE_INVENTORY, $conn) && ($req['status'] ?? '') === 'pending';
$canReject = has_permission('inventory_requests.reject', MODULE_INVENTORY, $conn) && ($req['status'] ?? '') === 'pending';

$pageTitle = 'Request ' . ($req['request_no'] ?? '');
require_once __DIR__ . '/includes/inv_layout_header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
  <div>
    <div class="page-header-label"><?= h($req['request_no']) ?></div>
    <div class="text-muted small"><?= h($req['source_module']) ?> · <?= h($req['request_date'] ?? '') ?></div>
  </div>
  <div class="text-end">
    <span class="badge bg-<?= ($req['status'] ?? '') === 'completed' ? 'success' : (($req['status'] ?? '') === 'rejected' ? 'danger' : 'secondary') ?>"><?= h($req['status']) ?></span>
    <div class="mt-2"><a class="btn btn-sm btn-outline-secondary" href="requests.php">All requests</a></div>
  </div>
</div>

<?php if ($message): ?>
  <div class="alert alert-warning"><?= h($message) ?></div>
<?php endif; ?>

<div class="card p-3 mb-3">
  <div class="row">
    <div class="col-md-6">
      <div class="small text-muted">Issue from location</div>
      <div class="fw-semibold">
        <?php if ($locLabel !== ''): ?>
          <?= h($locLabel) ?>
        <?php elseif (!empty($req['location_from_id'])): ?>
          <?= h((string)(int)$req['location_from_id']) ?>
        <?php else: ?>
          — <span class="text-muted small fw-normal">(set at approval)</span>
        <?php endif; ?>
      </div>
    </div>
    <div class="col-md-6">
      <div class="small text-muted">Requested by</div>
      <div><?= h($req['requested_username'] ?? '—') ?></div>
    </div>
  </div>
  <?php if (!empty($req['notes'])): ?>
    <div class="mt-2"><span class="text-muted small">Notes</span><div><?= nl2br(h($req['notes'])) ?></div></div>
  <?php endif; ?>
  <div class="mt-2">
    <div class="small text-muted mb-1">Operational context</div>
    <div class="row small">
      <?php
      $ctxShown = false;
      foreach ($ctxTitles as $ck => $ctitle) {
          if (empty($ctxLabels[$ck])) {
              continue;
          }
          $ctxShown = true;
          ?>
          <div class="col-md-6 mb-1"><span class="text-muted"><?= h($ctitle) ?>:</span> <?= h((string)$ctxLabels[$ck]) ?></div>
          <?php
      }
      if (!$ctxShown) {
          echo '<div class="col-12 text-muted">—</div>';
      }
      ?>
    </div>
  </div>
  <?php if (!empty($req['issue_doc_id'])): ?>
    <div class="mt-2">
      <a class="btn btn-sm btn-primary" href="document_edit.php?id=<?= (int)$req['issue_doc_id'] ?>">Open issue document #<?= (int)$req['issue_doc_id'] ?></a>
    </div>
  <?php endif; ?>
  <?php if (($req['status'] ?? '') === 'rejected' && !empty($req['rejection_reason'])): ?>
    <div class="alert alert-light border mt-3 mb-0"><strong>Rejection:</strong> <?= nl2br(h($req['rejection_reason'])) ?></div>
  <?php endif; ?>
</div>

<div class="card p-3 mb-3">
  <div class="fw-semibold mb-2">Lines</div>
  <div class="table-responsive">
    <table class="table table-sm">
      <thead>
        <tr>
          <th>Item</th>
          <th>Requested</th>
          <th>Approved</th>
          <th>Lot / serial</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($lines as $ln): ?>
          <tr>
            <td><?= h($ln['item_code'] . ' — ' . $ln['item_name']) ?></td>
            <td><?= h((string)$ln['requested_qty']) ?></td>
            <td><?= isset($ln['approved_qty']) && $ln['approved_qty'] !== null && $ln['approved_qty'] !== '' ? h((string)$ln['approved_qty']) : '—' ?></td>
            <td class="small"><?= h(trim(($ln['lot_number'] ?? '') . ' ' . ($ln['serial_number'] ?? ''))) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php if ($canApprove || $canReject): ?>
<div class="card p-3 border-primary">
  <?php if ($canApprove): ?>
    <form method="POST" class="mb-3">
      <?php csrf_field(); ?>
      <input type="hidden" name="action" value="approve_issue">
      <div class="fw-semibold mb-2">Approve &amp; issue (single step)</div>
      <p class="small text-muted">Choose the warehouse to issue from, then adjust approved quantities. If anything fails, nothing is saved and this request stays pending.</p>
      <div class="mb-3">
        <label class="form-label">Issue from location <span class="text-danger">*</span></label>
        <select class="form-select" name="location_from_id" required>
          <option value="">— Select warehouse / store —</option>
          <?php
          $selLoc = (int)($req['location_from_id'] ?? 0);
          foreach ($locations as $l):
              $lid = (int)$l['id'];
          ?>
            <option value="<?= $lid ?>" <?= $selLoc === $lid ? 'selected' : '' ?>><?= h($l['code'] . ' — ' . $l['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="table-responsive mb-2">
        <table class="table table-sm">
          <thead>
            <tr><th>Item</th><th>Requested</th><th>Approved qty</th></tr>
          </thead>
          <tbody>
            <?php foreach ($lines as $ln): ?>
              <tr>
                <td><?= h($ln['item_code']) ?></td>
                <td><?= h((string)$ln['requested_qty']) ?></td>
                <td style="max-width:140px">
                  <input class="form-control form-control-sm" type="number" step="any" min="0"
                    max="<?= h((string)$ln['requested_qty']) ?>"
                    name="appr_<?= (int)$ln['id'] ?>"
                    value="<?= h((string)$ln['requested_qty']) ?>">
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php if ($isOwnerAdmin): ?>
        <div class="form-check mb-2">
          <input class="form-check-input" type="checkbox" name="allow_negative" value="1" id="allowNeg">
          <label class="form-check-label small" for="allowNeg">Allow negative stock (Owner/Admin emergency override)</label>
        </div>
      <?php endif; ?>
      <button type="submit" class="btn btn-success">Approve &amp; issue</button>
    </form>
  <?php endif; ?>

  <?php if ($canReject): ?>
    <form method="POST">
      <?php csrf_field(); ?>
      <input type="hidden" name="action" value="reject">
      <div class="fw-semibold mb-2">Reject</div>
      <textarea class="form-control mb-2" name="rejection_reason" rows="2" placeholder="Reason (optional)"></textarea>
      <button type="submit" class="btn btn-outline-danger">Reject request</button>
    </form>
  <?php endif; ?>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/includes/inv_layout_footer.php'; ?>
