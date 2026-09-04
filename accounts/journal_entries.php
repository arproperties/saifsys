<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/../includes/sm_journal_service.php';
require_role(['Owner', 'Admin', 'Account'], $conn);

if (!function_exists('h')) {
    function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
}

$status = trim($_GET['status'] ?? '');
$msg = $_GET['msg'] ?? '';

$where = ['1=1'];
$params = [];
if ($status !== '' && $status !== 'all') {
    $where[] = 'j.status = ?';
    $params[] = $status;
}

$rows = [];
if (sm_jv_table_exists($conn)) {
    $sql = "
        SELECT j.*, COALESCE(NULLIF(u.fullname, ''), u.username) AS created_by_name
        FROM sm_manual_journals j
        LEFT JOIN user u ON u.id = j.created_by
        WHERE " . implode(' AND ', $where) . "
        ORDER BY j.journal_date DESC, j.id DESC
        LIMIT 200
    ";
    $st = $conn->prepare($sql);
    $st->execute($params);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Journal Entries</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
</head>
<body class="bg-light">
<div class="container-fluid my-4">
  <div class="d-flex align-items-center mb-3">
    <div>
      <h3 class="mb-0">Manual Journal Entries</h3>
      <div class="text-muted">Draft, review, and post adjusting entries to the GL</div>
    </div>
    <div class="ms-auto d-flex gap-2">
      <a href="journal_entry_add.php" class="btn btn-primary btn-sm"><i class="bi bi-plus-circle me-1"></i>New Journal</a>
      <a href="../account.php" class="btn btn-outline-secondary btn-sm">Back to Accounts</a>
    </div>
  </div>

  <?php if ($msg): ?><div class="alert alert-success"><?= h($msg) ?></div><?php endif; ?>

  <?php if (!sm_jv_table_exists($conn)): ?>
    <div class="alert alert-warning">Phase 7 tables not installed. Run <code>php tools/sm_apply_phase7_schema.php</code></div>
  <?php else: ?>

  <div class="card mb-3">
    <div class="card-body">
      <form class="row g-2" method="get">
        <div class="col-md-3">
          <select class="form-select" name="status">
            <option value="all">All statuses</option>
            <?php foreach (['draft', 'posted', 'void'] as $s): ?>
              <option value="<?= $s ?>" <?= $status === $s ? 'selected' : '' ?>><?= ucfirst($s) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-2"><button class="btn btn-outline-primary">Filter</button></div>
      </form>
    </div>
  </div>

  <div class="card">
    <div class="table-responsive">
      <table class="table table-hover mb-0">
        <thead><tr>
          <th>#</th><th>Date</th><th>Memo</th><th>Status</th><th>Created by</th><th class="text-end">Actions</th>
        </tr></thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
          <tr>
            <td><?= (int)$r['id'] ?></td>
            <td><?= h($r['journal_date']) ?></td>
            <td><?= h($r['memo'] ?: '—') ?></td>
            <td><span class="badge bg-<?= $r['status'] === 'posted' ? 'success' : ($r['status'] === 'void' ? 'secondary' : 'warning') ?>"><?= h($r['status']) ?></span></td>
            <td><?= h($r['created_by_name'] ?: '—') ?></td>
            <td class="text-end">
              <a class="btn btn-sm btn-outline-primary" href="journal_entry_view.php?id=<?= (int)$r['id'] ?>">Open</a>
            </td>
          </tr>
        <?php endforeach; if (!$rows): ?>
          <tr><td colspan="6" class="text-center text-muted py-4">No journal entries yet.</td></tr>
        <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
  <?php endif; ?>
</div>
</body>
</html>
