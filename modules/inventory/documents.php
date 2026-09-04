<?php
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../includes/branding.php';
require_once __DIR__ . '/../../includes/company_helper.php';
require_once __DIR__ . '/../../includes/module_access.php';
require_once __DIR__ . '/../../includes/rbac_department.php';
require_once __DIR__ . '/../../includes/permissions.php';
require_once __DIR__ . '/../../includes/inventory/inv_posting.php';
require_once __DIR__ . '/../../includes/inventory/inv_helpers.php';

require_login();
require_module_access($conn, MODULE_INVENTORY);
require_department_access(MODULE_INVENTORY, DEPT_INVENTORY, $conn);
require_permission('inventory_docs.view', MODULE_INVENTORY, $conn);

$brand = getBrandSettings($conn);
$companyId = current_company_id($conn) ?: 0;
$message = $messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create_doc') {
    csrf_verify();
    require_permission('inventory_docs.create', MODULE_INVENTORY, $conn);

    $docType = $_POST['doc_type'] ?? 'receipt';
    $docDate = $_POST['doc_date'] ?? date('Y-m-d');
    $fromLoc = (int)($_POST['location_from_id'] ?? 0);
    $toLoc   = (int)($_POST['location_to_id'] ?? 0);
    $notes   = trim($_POST['notes'] ?? '');

    $docTypeNorm = strtolower(trim($docType));
    if ($docTypeNorm === 'issue' && !inv_is_inventory_owner_or_admin($conn)) {
        $message = 'Manual issue documents are restricted to Owner/Admin. Use a material request for consumption.';
        $messageType = 'warning';
    } else {
    $hdr = [
        'company_id' => $companyId,
        'doc_type' => $docType,
        'doc_date' => $docDate,
        'status' => 'draft',
        'location_from_id' => $fromLoc ?: null,
        'location_to_id' => $toLoc ?: null,
        'notes' => $notes ?: null,
        'created_by' => current_user_id(),
        'is_emergency_issue' => ($docTypeNorm === 'issue' && inv_is_inventory_owner_or_admin($conn)) ? 1 : 0,
    ];

    try {
        $docId = inv_create_doc($conn, $hdr);
        header('Location: document_edit.php?id=' . $docId);
        exit;
    } catch (Throwable $e) {
        $message = 'Could not create document: ' . $e->getMessage();
        $messageType = 'warning';
    }
    }
}

$locations = [];
if ($companyId) {
    $stmt = $conn->prepare("SELECT id, code, name FROM inv_locations WHERE company_id = ? AND is_active = 1 ORDER BY name");
    $stmt->execute([$companyId]);
    $locations = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

$docs = [];
if ($companyId) {
    $stmt = $conn->prepare("
        SELECT id, doc_no, doc_type, doc_date, status, notes
        FROM inv_doc_headers
        WHERE company_id = ?
        ORDER BY id DESC
        LIMIT 100
    ");
    $stmt->execute([$companyId]);
    $docs = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

$pageTitle = 'Documents';
$canEmergencyIssue = inv_is_inventory_owner_or_admin($conn);
require_once __DIR__ . '/includes/inv_layout_header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
  <div class="page-header-label">Documents</div>
</div>
<?php if (!$canEmergencyIssue): ?>
  <p class="small text-muted">Consumption issues use <a href="requests.php">Material requests</a> (approve → issue). Manual <strong>Issue</strong> documents are Owner/Admin only.</p>
<?php endif; ?>

<?php if ($message): ?>
  <div class="alert alert-<?= $messageType === 'success' ? 'success' : ($messageType === 'warning' ? 'warning' : 'info') ?>"><?= h($message) ?></div>
<?php endif; ?>

<div class="card p-3 mb-3">
  <div class="fw-semibold mb-2">Create document</div>
  <form method="POST" class="row g-2">
    <?php csrf_field(); ?>
    <input type="hidden" name="action" value="create_doc">
    <div class="col-md-3">
      <label class="form-label">Type</label>
      <select class="form-select" name="doc_type">
        <?php
        $docTypes = [
            'receipt' => 'Receipt',
            'opening_balance' => 'Opening balance',
            'transfer' => 'Transfer',
            'adjustment' => 'Adjustment',
            'wastage' => 'Wastage',
            'return' => 'Return',
            'sale' => 'Sale',
            'stock_take' => 'Stock take',
        ];
        if ($canEmergencyIssue) {
            $docTypes = array_merge(['issue' => 'Issue (emergency — Owner/Admin)'], $docTypes);
        }
        foreach ($docTypes as $t => $label): ?>
          <option value="<?= h($t) ?>"><?= h($label) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-3">
      <label class="form-label">Date</label>
      <input class="form-control" type="date" name="doc_date" value="<?= h(date('Y-m-d')) ?>">
    </div>
    <div class="col-md-3">
      <label class="form-label">From location</label>
      <select class="form-select" name="location_from_id">
        <option value="">—</option>
        <?php foreach ($locations as $l): ?>
          <option value="<?= (int)$l['id'] ?>"><?= h($l['code'] . ' — ' . $l['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-3">
      <label class="form-label">To location</label>
      <select class="form-select" name="location_to_id">
        <option value="">—</option>
        <?php foreach ($locations as $l): ?>
          <option value="<?= (int)$l['id'] ?>"><?= h($l['code'] . ' — ' . $l['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-12">
      <label class="form-label">Notes</label>
      <input class="form-control" name="notes">
    </div>
    <div class="col-12">
      <button class="btn btn-primary">Create draft</button>
    </div>
  </form>
</div>

<div class="card p-3">
  <div class="fw-semibold mb-2">Recent documents</div>
  <div class="table-responsive">
    <table class="table table-sm align-middle">
      <thead>
        <tr>
          <th>ID</th>
          <th>Doc #</th>
          <th>Type</th>
          <th>Date</th>
          <th>Status</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($docs as $d): ?>
          <tr>
            <td><?= (int)$d['id'] ?></td>
            <td class="fw-semibold"><?= h($d['doc_no']) ?></td>
            <td><span class="badge bg-light text-dark border"><?= h($d['doc_type']) ?></span></td>
            <td><?= h($d['doc_date']) ?></td>
            <td>
              <?php $st = $d['status'] ?? 'draft'; ?>
              <span class="badge bg-<?= $st === 'posted' ? 'success' : ($st === 'void' ? 'danger' : 'secondary') ?>"><?= h($st) ?></span>
            </td>
            <td class="text-end"><a class="btn btn-sm btn-outline-primary" href="document_edit.php?id=<?= (int)$d['id'] ?>">Open</a></td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$docs): ?>
          <tr><td colspan="6" class="text-muted">No documents yet.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php require_once __DIR__ . '/includes/inv_layout_footer.php'; ?>

