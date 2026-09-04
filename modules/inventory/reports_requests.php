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

$byStatus = [];
$byModule = [];
$recent = [];
if ($companyId) {
    try {
        $st = $conn->prepare("SELECT status, COUNT(*) AS c FROM inv_request_headers WHERE company_id = ? GROUP BY status");
        $st->execute([$companyId]);
        $byStatus = $st->fetchAll(PDO::FETCH_ASSOC);

        $sm = $conn->prepare("SELECT source_module, COUNT(*) AS c FROM inv_request_headers WHERE company_id = ? GROUP BY source_module ORDER BY c DESC");
        $sm->execute([$companyId]);
        $byModule = $sm->fetchAll(PDO::FETCH_ASSOC);

        $rq = $conn->prepare("
            SELECT r.id, r.request_no, r.status, r.source_module, r.request_date, r.issue_doc_id, r.created_at
            FROM inv_request_headers r
            WHERE r.company_id = ?
            ORDER BY r.id DESC
            LIMIT 150
        ");
        $rq->execute([$companyId]);
        $recent = $rq->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
    }
}

$pageTitle = 'Request reports';
require_once __DIR__ . '/includes/inv_layout_header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
  <div class="page-header-label">Material request reports</div>
  <a class="btn btn-outline-primary" href="requests.php">Open requests</a>
</div>

<?php if (!$companyId): ?>
  <div class="alert alert-warning">Select a company first.</div>
<?php else: ?>

<div class="row g-3 mb-3">
  <div class="col-md-6">
    <div class="card p-3 h-100">
      <div class="fw-semibold mb-2">By status</div>
      <table class="table table-sm mb-0">
        <?php foreach ($byStatus as $row): ?>
          <tr><td><?= h($row['status']) ?></td><td class="text-end"><?= (int)$row['c'] ?></td></tr>
        <?php endforeach; ?>
        <?php if (!$byStatus): ?>
          <tr><td colspan="2" class="text-muted">No data (run phase 3 migration if tables are missing).</td></tr>
        <?php endif; ?>
      </table>
    </div>
  </div>
  <div class="col-md-6">
    <div class="card p-3 h-100">
      <div class="fw-semibold mb-2">By source module</div>
      <table class="table table-sm mb-0">
        <?php foreach ($byModule as $row): ?>
          <tr><td><?= h($row['source_module']) ?></td><td class="text-end"><?= (int)$row['c'] ?></td></tr>
        <?php endforeach; ?>
        <?php if (!$byModule): ?>
          <tr><td colspan="2" class="text-muted">No requests yet.</td></tr>
        <?php endif; ?>
      </table>
    </div>
  </div>
</div>

<div class="card p-3">
  <div class="fw-semibold mb-2">Recent requests</div>
  <div class="table-responsive">
    <table class="table table-sm align-middle">
      <thead>
        <tr>
          <th>Request</th>
          <th>Date</th>
          <th>Module</th>
          <th>Status</th>
          <th>Issue doc</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($recent as $r): ?>
          <tr>
            <td class="fw-semibold"><?= h($r['request_no']) ?></td>
            <td><?= h($r['request_date'] ?? '') ?></td>
            <td><?= h($r['source_module']) ?></td>
            <td><span class="badge bg-light text-dark border"><?= h($r['status']) ?></span></td>
            <td><?= !empty($r['issue_doc_id']) ? (int)$r['issue_doc_id'] : '—' ?></td>
            <td><a class="btn btn-sm btn-outline-primary" href="request_view.php?id=<?= (int)$r['id'] ?>">View</a></td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$recent): ?>
          <tr><td colspan="6" class="text-muted">No requests.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php endif; ?>

<?php require_once __DIR__ . '/includes/inv_layout_footer.php'; ?>
