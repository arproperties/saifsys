<?php
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../includes/branding.php';
require_once __DIR__ . '/../../includes/company_helper.php';
require_once __DIR__ . '/../../includes/module_access.php';
require_once __DIR__ . '/../../includes/rbac_department.php';
require_once __DIR__ . '/../../includes/permissions.php';

require_login();
require_module_access($conn, MODULE_INVENTORY);
require_department_access(MODULE_INVENTORY, DEPT_INVENTORY, $conn);
require_permission('inventory_requests.view', MODULE_INVENTORY, $conn);

$brand = getBrandSettings($conn);
$companyId = current_company_id($conn) ?: 0;

$stFilter = trim($_GET['status'] ?? '');
$modFilter = trim($_GET['source_module'] ?? '');

$sql = "
    SELECT r.*, u.username AS requested_username
    FROM inv_request_headers r
    LEFT JOIN user u ON u.id = r.requested_by
    WHERE r.company_id = ?
";
$params = [$companyId];
if ($stFilter !== '') {
    $sql .= " AND r.status = ? ";
    $params[] = $stFilter;
}
if ($modFilter !== '') {
    $sql .= " AND r.source_module = ? ";
    $params[] = $modFilter;
}
$sql .= " ORDER BY r.id DESC LIMIT 200";

$rows = [];
if ($companyId) {
    try {
        $stmt = $conn->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
    }
}

$pageTitle = 'Material requests';
require_once __DIR__ . '/includes/inv_layout_header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
  <div class="page-header-label">Material requests</div>
  <?php if ($companyId && has_permission('inventory_requests.create', MODULE_INVENTORY, $conn)): ?>
    <a class="btn btn-primary" href="request_create.php">New request</a>
  <?php endif; ?>
</div>

<div class="card p-3 mb-3">
  <form class="row g-2 align-items-end" method="GET">
    <div class="col-md-2">
      <label class="form-label">Status</label>
      <select class="form-select" name="status">
        <option value="">All</option>
        <?php foreach (['pending','completed','rejected'] as $s): ?>
          <option value="<?= h($s) ?>" <?= $stFilter === $s ? 'selected' : '' ?>><?= h($s) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-2">
      <label class="form-label">Module</label>
      <input class="form-control" name="source_module" value="<?= h($modFilter) ?>" placeholder="e.g. inventory">
    </div>
    <div class="col-md-2">
      <button class="btn btn-primary">Filter</button>
      <a class="btn btn-outline-secondary" href="requests.php">Reset</a>
    </div>
  </form>
</div>

<div class="card p-3">
  <div class="table-responsive">
    <table class="table table-sm">
      <thead>
        <tr>
          <th>Request</th>
          <th>Module</th>
          <th>Status</th>
          <th>From loc</th>
          <th>Issue doc</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($rows as $r): ?>
          <tr>
            <td class="fw-semibold"><?= h($r['request_no']) ?></td>
            <td><?= h($r['source_module']) ?></td>
            <td><span class="badge bg-light text-dark border"><?= h($r['status']) ?></span></td>
            <td><?= (int)$r['location_from_id'] ?></td>
            <td><?= !empty($r['issue_doc_id']) ? (int)$r['issue_doc_id'] : '—' ?></td>
            <td><a class="btn btn-sm btn-outline-primary" href="request_view.php?id=<?= (int)$r['id'] ?>">Open</a></td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?>
          <tr><td colspan="6" class="text-muted">No requests.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php require_once __DIR__ . '/includes/inv_layout_footer.php'; ?>
