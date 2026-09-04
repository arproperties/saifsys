<?php


require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/../includes/audit_bridge.php';
require_role(['Owner','Admin','HR'], $conn);

if (session_status() !== PHP_SESSION_ACTIVE) session_start();

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if (!$id) { header('Location: attendance.php'); exit; }

$me_id = $_SESSION['user']['id'] ?? null;

$flash_err = $flash_ok = '';

/* Load row */
$stmt = $conn->prepare("
SELECT a.*, e.full_name, e.employee_code
  FROM attendance a
  JOIN employees e ON e.id=a.employee_id
 WHERE a.id=?
");
$stmt->execute([$id]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$row) { header('Location: attendance.php'); exit; }

/* Update */
if ($_SERVER['REQUEST_METHOD']==='POST') {
    csrf_verify();
    $work_date = $_POST['work_date'] ?? $row['work_date'];
    $check_in  = $_POST['check_in']  !== '' ? $_POST['check_in']  : null;
    $check_out = $_POST['check_out'] !== '' ? $_POST['check_out'] : null;
    $status    = $_POST['status'] ?? $row['status'];
    $notes     = trim($_POST['notes'] ?? '');

    // recalc hours
    $hours = null;
    if ($check_in && $check_out) {
        $a = strtotime("1970-01-01 $check_in UTC");
        $b = strtotime("1970-01-01 $check_out UTC");
        if ($a!==false && $b!==false && $b > $a) {
            $hours = round(($b-$a)/3600, 2);
        }
    }

    try {
        $u = $conn->prepare("
            UPDATE attendance
               SET work_date=?, check_in=?, check_out=?, hours=?, status=?, notes=?, updated_by=?, updated_at=NOW()
             WHERE id=?
        ");
        $u->execute([$work_date,$check_in,$check_out,$hours,$status,$notes,$me_id,$id]);
        $flash_ok = 'Saved.';
        $empLabel = trim(($row['full_name'] ?? '') . ' (' . ($row['employee_code'] ?? '') . ')');
        $cidSt = $conn->prepare("SELECT company_id FROM employees WHERE id = ? LIMIT 1");
        $cidSt->execute([(int)$row['employee_id']]);
        $empCompanyId = (int)($cidSt->fetchColumn() ?: 0);
        audit_bridge_hr_ops(
            'attendance_updated',
            'attendance',
            $id,
            'Updated attendance for ' . $empLabel . ' on ' . $work_date . ' to ' . $status,
            $empCompanyId > 0 ? $empCompanyId : null,
            [
                'employee_id' => (int)$row['employee_id'],
                'work_date' => $work_date,
                'status' => $status,
                'check_in' => $check_in,
                'check_out' => $check_out,
                'hours' => $hours,
                'from_status' => $row['status'] ?? null,
            ],
            $empLabel . ' @ ' . $work_date,
            $me_id ? (int)$me_id : null
        );
        // reload fresh
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $flash_err = 'DB error: '.$e->getMessage();
    }
}

$statuses = ['approved'=>'Approved','absent'=>'Absent','half'=>'Half Day','on_leave'=>'On Leave'];

$pageTitle = 'Edit Attendance';
require_once __DIR__ . '/includes/hr_layout_header.php';

echo hr_ui_page_header(
    'Edit Attendance',
    h($row['full_name']) . ' (' . h($row['employee_code']) . ')',
    [
        ['label' => 'HR', 'href' => $hrBase . '/dashboard'],
        ['label' => 'Attendance', 'href' => 'attendance.php'],
        ['label' => 'Edit #' . (int)$row['id']],
    ],
    '<a class="btn btn-outline-secondary" href="attendance.php">Back</a>'
);
?>

  <?php if ($flash_err): ?><div class="alert alert-danger"><?= h($flash_err) ?></div><?php endif; ?>
  <?php if ($flash_ok):  ?><div class="alert alert-success"><?= h($flash_ok)  ?></div><?php endif; ?>

  <div class="hr-settings-card">
    <div class="card-body">
      <form method="post" class="row g-3">
        <?php csrf_field(); ?>
        <div class="col-md-3">
          <label class="form-label">Date</label>
          <input type="date" name="work_date" class="form-control" value="<?= h($row['work_date']) ?>" required>
        </div>
        <div class="col-md-3">
          <label class="form-label">Check-in</label>
          <input type="time" name="check_in" class="form-control" value="<?= h($row['check_in']) ?>">
        </div>
        <div class="col-md-3">
          <label class="form-label">Check-out</label>
          <input type="time" name="check_out" class="form-control" value="<?= h($row['check_out']) ?>">
        </div>
        <div class="col-md-3">
          <label class="form-label">Status</label>
          <select name="status" class="form-select">
            <?php foreach ($statuses as $k=>$v): ?>
              <option value="<?= h($k) ?>" <?= $row['status']===$k?'selected':''; ?>><?= h($v) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-12">
          <label class="form-label">Notes</label>
          <input type="text" name="notes" class="form-control" value="<?= h($row['notes']) ?>">
        </div>
        <div class="col-12 d-flex justify-content-end">
          <button class="btn btn-primary px-4">Save</button>
        </div>
      </form>
    </div>
  </div>

<?php require_once __DIR__ . '/includes/hr_layout_footer.php'; ?>
