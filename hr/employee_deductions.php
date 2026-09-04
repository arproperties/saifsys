<?php
// hr/employee_deductions.php


require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/../includes/audit_bridge.php';
require_role(['Owner','Admin','HR'], $conn);
if (session_status() !== PHP_SESSION_ACTIVE) session_start();

function flash($k){ if(!empty($_SESSION[$k])){ $m=$_SESSION[$k]; unset($_SESSION[$k]); return $m; } return ''; }

// Create / Delete
if ($_SERVER['REQUEST_METHOD']==='POST') {
    csrf_verify();
  $uid = $_SESSION['user']['id'] ?? null;

  if (isset($_POST['create'])) {
    $emp_id = (int)($_POST['employee_id'] ?? 0);
    $tx_date= $_POST['tx_date'] ?: date('Y-m-d');
    $amount = (float)($_POST['amount'] ?? 0);
    $dtype  = $_POST['dtype'] ?? 'other';
    $notes  = trim($_POST['notes'] ?? '');
    if ($emp_id && $amount>0 && in_array($dtype, ['fine','charge_duty','uniform','other'], true)) {
      $stmt = $conn->prepare("INSERT INTO employee_deductions (employee_id, tx_date, amount, dtype, notes, created_by)
                              VALUES (?,?,?,?,?,?)");
      $stmt->execute([$emp_id, $tx_date, $amount, $dtype, $notes, $uid]);
      $dedId = (int)$conn->lastInsertId();
      $_SESSION['ok'] = 'Deduction added.';
      $empMeta = $conn->prepare("SELECT full_name, employee_code, company_id FROM employees WHERE id=? LIMIT 1");
      $empMeta->execute([$emp_id]);
      $empMeta = $empMeta->fetch(PDO::FETCH_ASSOC) ?: [];
      $empLabel = trim(($empMeta['full_name'] ?? '') . ' (' . ($empMeta['employee_code'] ?? ('#' . $emp_id)) . ')');
      audit_bridge_hr_ops(
        'deduction_created',
        'employee_deductions',
        $dedId > 0 ? $dedId : $emp_id,
        'Created ' . $dtype . ' deduction for ' . $empLabel
          . ' — AED ' . number_format($amount, 2) . ' on ' . $tx_date,
        isset($empMeta['company_id']) ? (int)$empMeta['company_id'] : null,
        [
          'employee_id' => $emp_id,
          'tx_date' => $tx_date,
          'amount' => $amount,
          'dtype' => $dtype,
          'notes' => $notes !== '' ? $notes : null,
        ],
        'Deduction #' . ($dedId > 0 ? $dedId : '?') . ' — ' . $empLabel,
        $uid ? (int)$uid : null
      );
    } else {
      $_SESSION['err'] = 'Employee, positive amount, and valid type are required.';
    }
    header('Location: employee_deductions.php'); exit;
  }

  if (isset($_POST['delete']) && isset($_POST['id'])) {
      csrf_verify();
    $id = (int)$_POST['id'];
    $prev = $conn->prepare("
      SELECT d.*, e.full_name, e.employee_code, e.company_id
      FROM employee_deductions d
      JOIN employees e ON e.id = d.employee_id
      WHERE d.id = ? LIMIT 1
    ");
    $prev->execute([$id]);
    $prevRow = $prev->fetch(PDO::FETCH_ASSOC);
    $conn->prepare("DELETE FROM employee_deductions WHERE id=?")->execute([$id]);
    $_SESSION['ok'] = "Deduction #{$id} deleted.";
    if ($prevRow) {
      $empLabel = trim(($prevRow['full_name'] ?? '') . ' (' . ($prevRow['employee_code'] ?? '') . ')');
      audit_bridge_hr_ops(
        'deduction_deleted',
        'employee_deductions',
        $id,
        'Deleted ' . ($prevRow['dtype'] ?? 'deduction') . ' for ' . $empLabel
          . ' — AED ' . number_format((float)($prevRow['amount'] ?? 0), 2),
        isset($prevRow['company_id']) ? (int)$prevRow['company_id'] : null,
        [
          'employee_id' => (int)($prevRow['employee_id'] ?? 0),
          'tx_date' => $prevRow['tx_date'] ?? null,
          'amount' => $prevRow['amount'] ?? null,
          'dtype' => $prevRow['dtype'] ?? null,
        ],
        'Deduction #' . $id . ' — ' . $empLabel,
        $uid ? (int)$uid : null
      );
    }
    header('Location: employee_deductions.php'); exit;
  }
}

// Filters
$emp_filter = (int)($_GET['employee_id'] ?? 0);
$from = $_GET['from'] ?? '';
$to   = $_GET['to'] ?? '';
$dtype= $_GET['dtype'] ?? '';

$where=[]; $p=[];
if ($emp_filter){ $where[]='d.employee_id=?'; $p[]=$emp_filter; }
if ($from){ $where[]='d.tx_date>=?'; $p[]=$from; }
if ($to){ $where[]='d.tx_date<=?'; $p[]=$to; }
if ($dtype!==''){ $where[]='d.dtype=?'; $p[]=$dtype; }
$SQLWHERE = $where ? ('WHERE '.implode(' AND ',$where)) : '';

// Employees
$employees = $conn->query("SELECT id, CONCAT(full_name,' (',employee_code,')') label FROM employees ORDER BY full_name")
                  ->fetchAll(PDO::FETCH_ASSOC);

// Rows
$stmt = $conn->prepare("
  SELECT d.*, e.full_name, e.employee_code
  FROM employee_deductions d
  JOIN employees e ON e.id=d.employee_id
  $SQLWHERE
  ORDER BY d.tx_date DESC, d.id DESC
");
$stmt->execute($p);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Totals
$tot = $conn->prepare("SELECT SUM(amount) AS s FROM employee_deductions d $SQLWHERE");
$tot->execute($p);
$sum = (float)($tot->fetchColumn() ?: 0);

$pageTitle = 'Employee Deductions';
$pageStyles = '.num{text-align:right;font-variant-numeric:tabular-nums}';
require_once __DIR__ . '/includes/hr_layout_header.php';

echo hr_ui_page_header(
    'Employee Deductions',
    'Record fines, charges, and other payroll deductions.',
    [
        ['label' => 'HR', 'href' => $hrBase . '/dashboard'],
        ['label' => 'Deductions'],
    ]
);
?>

  <?php if($m=flash('err')): ?><div class="alert alert-danger"><?= htmlspecialchars($m) ?></div><?php endif; ?>
  <?php if($m=flash('ok')):  ?><div class="alert alert-success"><?= htmlspecialchars($m) ?></div><?php endif; ?>

  <div class="hr-settings-card mb-4">
    <div class="settings-header">New Deduction</div>
    <div class="card-body">
      <form method="post" class="row g-3">
      <?php csrf_field(); ?>
        <input type="hidden" name="create" value="1">
        <div class="col-md-4">
          <label class="form-label">Employee *</label>
          <select name="employee_id" class="form-select" required>
            <option value="">-- Select --</option>
            <?php foreach($employees as $e): ?>
              <option value="<?= (int)$e['id'] ?>"><?= htmlspecialchars($e['label']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-2">
          <label class="form-label">Date</label>
          <input type="date" name="tx_date" value="<?= date('Y-m-d') ?>" class="form-control">
        </div>
        <div class="col-md-2">
          <label class="form-label">Amount *</label>
          <input type="number" step="0.01" name="amount" class="form-control" required>
        </div>
        <div class="col-md-2">
          <label class="form-label">Type *</label>
          <select name="dtype" class="form-select" required>
            <option value="fine">Fine</option>
            <option value="charge_duty">Charge duty</option>
            <option value="uniform">Uniform</option>
            <option value="other" selected>Other</option>
          </select>
        </div>
        <div class="col-md-12">
          <label class="form-label">Notes</label>
          <input name="notes" class="form-control">
        </div>
        <div class="col-12">
          <button class="btn btn-success">Add</button>
        </div>
      </form>
    </div>
  </div>

  <div class="hr-settings-card mb-3">
    <div class="settings-header">Filter</div>
    <div class="card-body">
      <form class="row g-3">
        <div class="col-md-4">
          <label class="form-label">Employee</label>
          <select name="employee_id" class="form-select">
            <option value="0">All</option>
            <?php foreach($employees as $e): ?>
              <option value="<?= (int)$e['id'] ?>" <?= $emp_filter==$e['id']?'selected':'' ?>>
                <?= htmlspecialchars($e['label']) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-2">
          <label class="form-label">From</label>
          <input type="date" name="from" value="<?= htmlspecialchars($from) ?>" class="form-control">
        </div>
        <div class="col-md-2">
          <label class="form-label">To</label>
          <input type="date" name="to" value="<?= htmlspecialchars($to) ?>" class="form-control">
        </div>
        <div class="col-md-2">
          <label class="form-label">Type</label>
          <select name="dtype" class="form-select">
            <option value="">All</option>
            <?php foreach(['fine','charge_duty','uniform','other'] as $t): ?>
              <option value="<?= $t ?>" <?= $dtype===$t?'selected':'' ?>><?= ucwords(str_replace('_',' ',$t)) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-2 d-flex align-items-end">
          <button class="btn btn-primary w-100">Apply</button>
        </div>
      </form>
    </div>
  </div>

  <div class="hr-settings-card">
    <div class="settings-header d-flex align-items-center">
      <span>Entries</span>
      <div class="ms-auto small">Total amount: <strong><?= number_format($sum,2) ?></strong></div>
    </div>
    <div class="hr-table-shell border-0 shadow-none rounded-0">
      <table class="table table-hover align-middle mb-0">
        <thead class="table-light">
          <tr>
            <th>#</th><th>Date</th><th>Employee</th><th class="num">Amount</th><th>Type</th><th>Notes</th><th class="text-end">Actions</th>
          </tr>
        </thead>
        <tbody>
        <?php if(!$rows): ?>
          <tr><td colspan="7" class="text-center text-muted py-4">No records.</td></tr>
        <?php else: foreach($rows as $r): ?>
          <tr>
            <td><?= (int)$r['id'] ?></td>
            <td><?= htmlspecialchars($r['tx_date']) ?></td>
            <td><?= htmlspecialchars($r['full_name']).' ('.htmlspecialchars($r['employee_code']).')' ?></td>
            <td class="num"><?= number_format((float)$r['amount'],2) ?></td>
            <td><span class="badge text-bg-secondary"><?= htmlspecialchars($r['dtype']) ?></span></td>
            <td><?= htmlspecialchars($r['notes'] ?? '') ?></td>
            <td class="text-end">
              <form method="post" class="d-inline" onsubmit="return confirm('Delete this record?')">
            <?php csrf_field(); ?>
                <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                <input type="hidden" name="delete" value="1">
                <button class="btn btn-sm btn-outline-danger">Delete</button>
              </form>
            </td>
          </tr>
        <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>

<?php require_once __DIR__ . '/includes/hr_layout_footer.php'; ?>
