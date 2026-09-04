<?php
// hr/leave_balances.php


require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/../includes/audit_bridge.php';
if (session_status() !== PHP_SESSION_ACTIVE) session_start();

// roles
$roles = function_exists('current_user_roles') ? current_user_roles() : [];
$can_manage = !empty(array_intersect($roles, ['Owner','Admin','HR']));
require_role(['Owner','Admin','HR']); // view restricted to HR/admins

// inputs
$year = (int)($_GET['year'] ?? date('Y'));
$emp_id = (int)($_GET['employee_id'] ?? 0);

// helpers
function employees_for_select(PDO $conn): array {
  return $conn->query("SELECT id, CONCAT(full_name,' (',employee_code,')') label
                       FROM employees ORDER BY full_name")->fetchAll(PDO::FETCH_ASSOC);
}
function leave_types_kv(PDO $conn): array {
  return $conn->query("SELECT id, name FROM leave_types ORDER BY name")->fetchAll(PDO::FETCH_KEY_PAIR);
}
function recalc_row(PDO $conn, int $id): void {
  $u = $conn->prepare("UPDATE leave_balances
                       SET closing=(opening+accrued+carried)-(taken+0)
                       WHERE id=?");
  $u->execute([$id]);
}

// actions
$msg=''; $err='';
if ($_SERVER['REQUEST_METHOD']==='POST' && $can_manage) {

  // Create missing (all employees × types) for year
  if (isset($_POST['create_missing'])) {
    try {
      $conn->beginTransaction();

      // employees × types not present
      $missing = $conn->prepare("
        INSERT INTO leave_balances (employee_id, leave_type_id, year, opening, accrued, taken, carried, closing)
        SELECT e.id, lt.id, :yr,
               COALESCE(lt.annual_quota_days,0) AS opening, 0, 0, 0,
               COALESCE(lt.annual_quota_days,0)
        FROM employees e
        JOIN leave_types lt
        LEFT JOIN leave_balances lb
          ON lb.employee_id=e.id AND lb.leave_type_id=lt.id AND lb.year=:yr
        WHERE lb.id IS NULL
      ");
      $missing->execute([':yr'=>$year]);
      $createdCount = (int)$missing->rowCount();

      $conn->commit();
      $msg = 'Missing balances created for '.$year.'.';
      $uid = $_SESSION['user']['id'] ?? null;
      audit_bridge_hr_ops(
        'leave_balances_created',
        'leave_balances',
        $year,
        'Created missing leave balances for year ' . $year
          . ($createdCount > 0 ? (' (' . $createdCount . ' row(s))') : ''),
        function_exists('current_company_id') ? (current_company_id($conn) ?: null) : null,
        ['year' => $year, 'created_count' => $createdCount],
        'Leave balances ' . $year,
        $uid ? (int)$uid : null
      );
    } catch(Exception $e) {
      $conn->rollBack(); $err = $e->getMessage();
    }
  }

  // Adjust a field
  if (isset($_POST['adjust'])) {
    $id = (int)$_POST['id'];
    $field = $_POST['field'] ?? '';
    $delta = (float)($_POST['delta'] ?? 0);
    $allowed = ['opening','accrued','taken','carried'];
    if ($id && in_array($field,$allowed,true)) {
      $prev = $conn->prepare("
        SELECT lb.*, e.full_name, e.employee_code, e.company_id, lt.name AS type_name
        FROM leave_balances lb
        JOIN employees e ON e.id = lb.employee_id
        JOIN leave_types lt ON lt.id = lb.leave_type_id
        WHERE lb.id = ? LIMIT 1
      ");
      $prev->execute([$id]);
      $prevRow = $prev->fetch(PDO::FETCH_ASSOC);

      $u = $conn->prepare("UPDATE leave_balances SET $field = $field + :d WHERE id=:id");
      $u->execute([':d'=>$delta, ':id'=>$id]);
      recalc_row($conn,$id);
      $msg = 'Balance updated.';

      if ($prevRow) {
        $empLabel = trim(($prevRow['full_name'] ?? '') . ' (' . ($prevRow['employee_code'] ?? '') . ')');
        $uid = $_SESSION['user']['id'] ?? null;
        audit_bridge_hr_ops(
          'leave_balance_adjusted',
          'leave_balances',
          $id,
          'Adjusted leave balance for ' . $empLabel
            . ' — ' . ($prevRow['type_name'] ?? 'leave') . ' ' . $field
            . ' by ' . ($delta >= 0 ? '+' : '') . $delta
            . ' (year ' . ($prevRow['year'] ?? '') . ')',
          isset($prevRow['company_id']) ? (int)$prevRow['company_id'] : null,
          [
            'employee_id' => (int)($prevRow['employee_id'] ?? 0),
            'leave_type' => $prevRow['type_name'] ?? null,
            'year' => $prevRow['year'] ?? null,
            'field' => $field,
            'delta' => $delta,
          ],
          'Balance #' . $id . ' — ' . $empLabel,
          $uid ? (int)$uid : null
        );
      }
    }
  }

  // Recalc a single row
  if (isset($_POST['recalc'])) {
    $id = (int)$_POST['id'];
    if ($id) {
      $prev = $conn->prepare("
        SELECT lb.*, e.full_name, e.employee_code, e.company_id, lt.name AS type_name
        FROM leave_balances lb
        JOIN employees e ON e.id = lb.employee_id
        JOIN leave_types lt ON lt.id = lb.leave_type_id
        WHERE lb.id = ? LIMIT 1
      ");
      $prev->execute([$id]);
      $prevRow = $prev->fetch(PDO::FETCH_ASSOC);
      recalc_row($conn,$id);
      $msg='Closing recalculated.';
      if ($prevRow) {
        $empLabel = trim(($prevRow['full_name'] ?? '') . ' (' . ($prevRow['employee_code'] ?? '') . ')');
        $uid = $_SESSION['user']['id'] ?? null;
        audit_bridge_hr_ops(
          'leave_balance_recalculated',
          'leave_balances',
          $id,
          'Recalculated leave balance closing for ' . $empLabel
            . ' — ' . ($prevRow['type_name'] ?? 'leave')
            . ' (year ' . ($prevRow['year'] ?? '') . ')',
          isset($prevRow['company_id']) ? (int)$prevRow['company_id'] : null,
          [
            'employee_id' => (int)($prevRow['employee_id'] ?? 0),
            'leave_type' => $prevRow['type_name'] ?? null,
            'year' => $prevRow['year'] ?? null,
          ],
          'Balance #' . $id . ' — ' . $empLabel,
          $uid ? (int)$uid : null
        );
      }
    }
  }
}

// lookups
$employees = employees_for_select($conn);
$types_kv  = leave_types_kv($conn);

// rows
$where = "lb.year = :yr";
$params = [':yr'=>$year];
if ($emp_id) { $where .= " AND lb.employee_id = :emp"; $params[':emp']=$emp_id; }

$sql = "
SELECT lb.*, e.full_name, e.employee_code, lt.name AS type_name
FROM leave_balances lb
JOIN employees e ON e.id=lb.employee_id
JOIN leave_types lt ON lt.id=lb.leave_type_id
WHERE $where
ORDER BY e.full_name, lt.name
";
$stmt = $conn->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$pageTitle = 'Leave Balances';
require_once __DIR__ . '/includes/hr_layout_header.php';

echo hr_ui_page_header(
    'Leave Balances',
    'View and adjust employee leave balances by year.',
    [
        ['label' => 'HR', 'href' => $hrBase . '/dashboard'],
        ['label' => 'Leave Balances'],
    ]
);
?>

  <?php if ($err): ?><div class="alert alert-danger"><?= htmlspecialchars($err) ?></div><?php endif; ?>
  <?php if ($msg): ?><div class="alert alert-success"><?= htmlspecialchars($msg) ?></div><?php endif; ?>

  <div class="hr-filter-bar mb-3">
    <form class="row g-2" method="get">
    <div class="col-auto">
      <label class="form-label">Year</label>
      <input type="number" class="form-control" name="year" value="<?= (int)$year ?>" style="width:120px">
    </div>
    <div class="col-auto">
      <label class="form-label">Employee</label>
      <select class="form-select" name="employee_id" style="min-width:260px">
        <option value="0">All</option>
        <?php foreach ($employees as $e): ?>
          <option value="<?= (int)$e['id'] ?>" <?= $emp_id==$e['id']?'selected':'' ?>>
            <?= htmlspecialchars($e['label']) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-auto align-self-end">
      <button class="btn btn-primary">Apply</button>
    </div>
    <?php if ($can_manage): ?>
    <div class="col-auto align-self-end">
      <form method="post" class="d-inline">
        <?php csrf_field(); ?>
        <input type="hidden" name="create_missing" value="1">
        <button class="btn btn-outline-secondary"
                formaction="leave_balances.php?year=<?= (int)$year ?>&employee_id=<?= (int)$emp_id ?>">
          Create missing for <?= (int)$year ?>
        </button>
      </form>
    </div>
    <?php endif; ?>
    </form>
  </div>

  <div class="hr-settings-card">
    <div class="hr-table-shell border-0 shadow-none rounded-0">
      <table class="table table-striped align-middle mb-0">
        <thead class="table-light">
          <tr>
            <th>Employee</th>
            <th>Type</th>
            <th class="text-end">Opening</th>
            <th class="text-end">Accrued</th>
            <th class="text-end">Taken</th>
            <th class="text-end">Carried</th>
            <th class="text-end">Closing</th>
            <?php if ($can_manage): ?><th class="text-end">Actions</th><?php endif; ?>
          </tr>
        </thead>
        <tbody>
          <?php if (!$rows): ?>
            <tr><td colspan="8" class="text-center text-muted py-4">No balances found.</td></tr>
          <?php else: foreach ($rows as $r): ?>
            <tr>
              <td class="fw-semibold">
                <?= htmlspecialchars($r['full_name']) ?> <span class="text-muted"> (<?= htmlspecialchars($r['employee_code']) ?>)</span>
              </td>
              <td><?= htmlspecialchars($r['type_name']) ?></td>
              <td class="text-end"><?= number_format((float)$r['opening'],2) ?></td>
              <td class="text-end"><?= number_format((float)$r['accrued'],2) ?></td>
              <td class="text-end"><?= number_format((float)$r['taken'],2) ?></td>
              <td class="text-end"><?= number_format((float)$r['carried'],2) ?></td>
              <td class="text-end fw-semibold"><?= number_format((float)$r['closing'],2) ?></td>
              <?php if ($can_manage): ?>
              <td class="text-end">
                <form method="post" class="d-inline">
                  <?php csrf_field(); ?>
                  <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                  <input type="hidden" name="adjust" value="1">
                  <select name="field" class="form-select form-select-sm d-inline-block" style="width:120px">
                    <option value="opening">Opening</option>
                    <option value="accrued">Accrued</option>
                    <option value="taken">Taken</option>
                    <option value="carried">Carried</option>
                  </select>
                  <input name="delta" type="number" step="0.5" class="form-control form-control-sm d-inline-block" style="width:100px" placeholder="+/-">
                  <button class="btn btn-sm btn-outline-primary">Adjust</button>
                </form>
                <form method="post" class="d-inline">
                  <?php csrf_field(); ?>
                  <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                  <input type="hidden" name="recalc" value="1">
                  <button class="btn btn-sm btn-outline-secondary">Recalc</button>
                </form>
              </td>
              <?php endif; ?>
            </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>

<?php require_once __DIR__ . '/includes/hr_layout_footer.php'; ?>
