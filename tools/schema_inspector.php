<?php
// tools/schema_inspector.php
// Minimal, read-only DB schema dashboard.

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db_connect.php';

$dbName = $conn->query("SELECT DATABASE()")->fetchColumn();

$wishTables = [
  // Core HR
  'employees','employee_documents','employee_status_history',
  'departments','locations',
  // RBAC
  'roles','permissions','user_roles','role_permissions',
  // Leave & time
  'leave_types','leave_requests','attendances','shifts',
  // Payroll
  'payruns','payrun_items',
  // Ops-related helpers
  'cars','driver_assignments',
  // Auditing / notifications if added
  'notifications','audit_log',
  // Legacy / bridge tables that matter
  'workers','driver','make_order','order_workers','user'
];

// Only keep tables that exist
$existing = [];
$stmt = $conn->prepare("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = ?");
$stmt->execute([$dbName]);
$existingNames = array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'TABLE_NAME');
foreach ($wishTables as $t) {
  if (in_array($t, $existingNames, true)) $existing[] = $t;
}

// Helpers
function rows($conn, $dbName, $table) {
  $q = $conn->prepare("SELECT TABLE_ROWS FROM information_schema.TABLES WHERE TABLE_SCHEMA=? AND TABLE_NAME=?");
  $q->execute([$dbName, $table]);
  return (int)($q->fetchColumn() ?: 0);
}
function cols($conn, $dbName, $table) {
  $q = $conn->prepare("SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, COLUMN_KEY, EXTRA
                       FROM information_schema.COLUMNS
                       WHERE TABLE_SCHEMA=? AND TABLE_NAME=?
                       ORDER BY ORDINAL_POSITION");
  $q->execute([$dbName, $table]);
  return $q->fetchAll(PDO::FETCH_ASSOC);
}
function fks($conn, $dbName, $table) {
  $q = $conn->prepare("SELECT
      kcu.CONSTRAINT_NAME,
      kcu.COLUMN_NAME,
      kcu.REFERENCED_TABLE_NAME,
      kcu.REFERENCED_COLUMN_NAME
    FROM information_schema.KEY_COLUMN_USAGE kcu
    JOIN information_schema.TABLE_CONSTRAINTS tc
      ON tc.CONSTRAINT_NAME = kcu.CONSTRAINT_NAME
     AND tc.TABLE_SCHEMA = kcu.TABLE_SCHEMA
     AND tc.TABLE_NAME = kcu.TABLE_NAME
   WHERE kcu.TABLE_SCHEMA=? AND kcu.TABLE_NAME=? AND tc.CONSTRAINT_TYPE='FOREIGN KEY'
   ORDER BY kcu.CONSTRAINT_NAME, kcu.ORDINAL_POSITION");
  $q->execute([$dbName, $table]);
  return $q->fetchAll(PDO::FETCH_ASSOC);
}

$checks = [];

// Sanity checks (run only if the tables exist)
if (in_array('employees',$existing) && in_array('employee_documents',$existing)) {
  // Orphan documents
  $orph = $conn->query("SELECT COUNT(*) FROM employee_documents d
                        LEFT JOIN employees e ON e.id=d.employee_id
                        WHERE e.id IS NULL")->fetchColumn();
  $checks[] = ['Employee Documents without a matching Employee', (int)$orph, $orph ? 'warn' : 'ok'];
}
if (in_array('employees',$existing)) {
  // Duplicate employee_code
  $dup = $conn->query("SELECT COUNT(*) FROM (
                         SELECT employee_code, COUNT(*) c
                         FROM employees
                         WHERE employee_code IS NOT NULL AND employee_code<>''
                         GROUP BY employee_code HAVING c>1
                       ) x")->fetchColumn();
  $checks[] = ['Duplicate employee_code entries', (int)$dup, $dup ? 'warn' : 'ok'];
}
if (in_array('order_workers',$existing) && in_array('make_order',$existing)) {
  // Orphan order_workers
  $orphOw = $conn->query("SELECT COUNT(*) FROM order_workers ow
                          LEFT JOIN make_order mo ON mo.id = ow.order_id
                          WHERE mo.id IS NULL")->fetchColumn();
  $checks[] = ['order_workers rows without a matching make_order', (int)$orphOw, $orphOw ? 'warn' : 'ok'];
}

?><!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <title>Schema Inspector</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <style>
    body{background:#f7f9fb}
    .card{border-radius:16px}
    .badge-ok{background:#198754}
    .badge-warn{background:#dc3545}
    code{font-size: .85rem}
    .mono{font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono","Courier New", monospace}
  </style>
</head>
<body class="p-4">
  <div class="container">
    <div class="d-flex align-items-center mb-4">
      <h3 class="me-3 mb-0">DB Schema Inspector</h3>
      <span class="text-muted">Database: <code><?= htmlspecialchars($dbName) ?></code></span>
    </div>

    <div class="row g-3 mb-4">
      <?php foreach ($existing as $t): ?>
        <div class="col-lg-4 col-md-6">
          <div class="card shadow-sm h-100">
            <div class="card-body">
              <div class="d-flex justify-content-between align-items-center mb-2">
                <h6 class="mb-0"><?= htmlspecialchars($t) ?></h6>
                <span class="badge bg-secondary"><?= rows($conn,$dbName,$t) ?> rows</span>
              </div>
              <div class="small text-muted mb-2">Columns</div>
              <div class="mono mb-3" style="max-height:140px; overflow:auto;">
                <table class="table table-sm table-borderless mb-0">
                  <tbody>
                  <?php foreach (cols($conn,$dbName,$t) as $c): ?>
                    <tr>
                      <td><code><?= htmlspecialchars($c['COLUMN_NAME']) ?></code></td>
                      <td class="text-muted"><code><?= htmlspecialchars($c['COLUMN_TYPE']) ?></code></td>
                      <td>
                        <?php if ($c['COLUMN_KEY']==='PRI'): ?>
                          <span class="badge bg-dark">PK</span>
                        <?php elseif ($c['COLUMN_KEY']==='MUL'): ?>
                          <span class="badge bg-info">IDX</span>
                        <?php endif; ?>
                        <?php if (stripos($c['EXTRA'],'auto_increment')!==false): ?>
                          <span class="badge bg-secondary">AI</span>
                        <?php endif; ?>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                  </tbody>
                </table>
              </div>

              <?php $fk = fks($conn,$dbName,$t); if ($fk): ?>
                <div class="small text-muted mb-1">Foreign Keys</div>
                <ul class="small mono mb-0">
                  <?php foreach ($fk as $k): ?>
                    <li><code><?= htmlspecialchars($k['COLUMN_NAME']) ?></code> → <code><?= htmlspecialchars($k['REFERENCED_TABLE_NAME']) ?>(<?= htmlspecialchars($k['REFERENCED_COLUMN_NAME']) ?>)</code></li>
                  <?php endforeach; ?>
                </ul>
              <?php else: ?>
                <div class="text-muted small fst-italic">No foreign keys</div>
              <?php endif; ?>
            </div>
          </div>
        </div>
      <?php endforeach; ?>
    </div>

    <div class="card shadow-sm mb-4">
      <div class="card-body">
        <h6 class="mb-3">Sanity checks</h6>
        <?php if (!$checks): ?>
          <div class="text-muted">No checks available.</div>
        <?php else: ?>
          <ul class="mb-0">
            <?php foreach ($checks as [$label,$val,$state]): ?>
              <li>
                <?= htmlspecialchars($label) ?>:
                <?php if ($state==='ok'): ?>
                  <span class="badge badge-ok">OK</span>
                <?php else: ?>
                  <span class="badge badge-warn"><?= (int)$val ?></span>
                <?php endif; ?>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
      </div>
    </div>

    <div class="small text-muted">
      Tip: for a raw dump you can also run
      <code>SHOW CREATE TABLE &lt;table&gt;</code> and
      <code>SELECT * FROM information_schema.KEY_COLUMN_USAGE ...</code>
    </div>
  </div>
</body>
</html>
