<?php


require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/../includes/audit_bridge.php';
require_once __DIR__ . '/includes/hr_company_scope.php';
require_once __DIR__ . '/includes/hr_employee_lifecycle.php';
require_role(['Owner','Admin','HR'], $conn);

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
$uid = $_SESSION['user']['id'] ?? null;

/* ---------------- Helpers ---------------- */

function ensure_dir(string $dir): void {
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
}

function h($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

/** round a seconds span to N-minute steps, returns hours (decimal) */
function round_seconds_to_hours(int $seconds, int $stepMinutes = 15): float {
    if ($seconds < 0) $seconds = 0;
    $step = max(1, $stepMinutes) * 60;
    $rounded = (int)round($seconds / $step) * $step;
    return round($rounded / 3600, 2);
}

function compute_hours(?string $start, ?string $end, ?float $manual, int $roundTo): float {
    if ($manual !== null && $manual >= 0) {
        return round($manual, 2);
    }
    if (!$start || !$end) return 0.0;

    // allow crossing midnight (e.g., 22:00 -> 02:00)
    $s = strtotime($start);
    $e = strtotime($end);
    if ($e === false || $s === false) return 0.0;
    if ($e < $s) $e += 86400;

    $seconds = $e - $s;
    return round_seconds_to_hours($seconds, $roundTo);
}

function is_weekend(string $ymd): bool {
    // Mon=1 ... Sun=7 ; weekend = Sat(6), Sun(7)
    $dow = (int)date('N', strtotime($ymd));
    return ($dow >= 6);
}

function is_holiday(PDO $conn, int $employee_id, string $ymd): bool {
    // get employee location
    $q = $conn->prepare("SELECT location_id FROM employees WHERE id=?");
    $q->execute([$employee_id]);
    $loc = $q->fetchColumn();

    // holiday for that date and (same location or NULL/global)
    if ($loc) {
        $h = $conn->prepare("SELECT 1 FROM holidays WHERE holiday_date=? AND (location_id IS NULL OR location_id=?) LIMIT 1");
        $h->execute([$ymd, $loc]);
    } else {
        $h = $conn->prepare("SELECT 1 FROM holidays WHERE holiday_date=? LIMIT 1");
        $h->execute([$ymd]);
    }
    return (bool)$h->fetchColumn();
}

/* ------------- Create / Approve / Reject ------------- */

$msg=''; $err='';

if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['create'])) {
    csrf_verify();
    try {
        $employee_id = (int)($_POST['employee_id'] ?? 0);
        $ot_date     = trim($_POST['ot_date'] ?? '');
        $start_time  = trim($_POST['start_time'] ?? '');
        $end_time    = trim($_POST['end_time'] ?? '');
        $manual      = $_POST['manual_hours'] ?? '';
        $manual_hours = ($manual === '' ? null : (float)$manual);
        $rule_id     = (int)($_POST['rule_id'] ?? 0);
        $hourly_rate = $_POST['hourly_rate'] !== '' ? (float)$_POST['hourly_rate'] : null;
        $reason      = trim($_POST['reason'] ?? '');

        if (!$employee_id || !$ot_date) throw new Exception('Employee and Date are required.');

        // load rule (policy)
        $rule = null;
        if ($rule_id) {
            $r = $conn->prepare("SELECT id,name,weekday_rate,weekend_rate,holiday_rate,round_to_minutes,base_hourly,active
                                 FROM overtime_rules WHERE id=?");
            $r->execute([$rule_id]);
            $rule = $r->fetch(PDO::FETCH_ASSOC);
            if (!$rule || (int)$rule['active'] !== 1) $rule = null;
        }
        $roundTo = (int)($rule['round_to_minutes'] ?? 15);
        if ($roundTo <= 0) $roundTo = 15;

        // compute hours
        $pay_hours = compute_hours($start_time ?: null, $end_time ?: null, $manual_hours, $roundTo);

        // multiplier
        if ($rule) {
            if (is_holiday($conn, $employee_id, $ot_date)) {
                $pay_multiplier = (float)$rule['holiday_rate'];
            } elseif (is_weekend($ot_date)) {
                $pay_multiplier = (float)$rule['weekend_rate'];
            } else {
                $pay_multiplier = (float)$rule['weekday_rate'];
            }
        } else {
            $pay_multiplier = 1.00;
        }

        // pay rate
        $pay_rate = $hourly_rate ?? (isset($rule['base_hourly']) ? (float)$rule['base_hourly'] : 0.0);

        // amount
        $pay_amount = round($pay_hours * $pay_rate * $pay_multiplier, 2);

        // attachment
        $attach_path = null;
        if (!empty($_FILES['attachment']['name'])) {
            $dir = realpath(__DIR__ . '/..') . '/uploads/overtime_attachments';
            ensure_dir($dir);
            if (!is_writable($dir)) throw new Exception('Upload failed: folder not writable.');
            $ext = pathinfo($_FILES['attachment']['name'], PATHINFO_EXTENSION);
            $fname = 'ot_' . time() . '_' . mt_rand(1000,9999) . ($ext ? '.'.$ext : '');
            if (!move_uploaded_file($_FILES['attachment']['tmp_name'], $dir.'/'.$fname)) {
                throw new Exception('Upload failed.');
            }
            $attach_path = 'uploads/overtime_attachments/' . $fname;
        }

        // insert
        $ins = $conn->prepare("
          INSERT INTO overtime_entries
            (employee_id, ot_date, start_time, end_time, manual_hours, reason,
             status, approver_id, decided_at, attachment_path, notes,
             rule_id, pay_hours, pay_multiplier, pay_rate, pay_amount,
             created_at, updated_at, created_by, updated_by)
          VALUES
            (?,?,?,?,?,?, 'pending', NULL, NULL, ?, NULL,
             ?,?,?,?,?, NOW(), NOW(), ?, ?)
        ");
        $ins->execute([
            $employee_id, $ot_date, ($start_time?:null), ($end_time?:null), $manual_hours, $reason,
            $attach_path,
            ($rule['id'] ?? null), $pay_hours, $pay_multiplier, $pay_rate, $pay_amount,
            $uid, $uid
        ]);
        $otId = (int)$conn->lastInsertId();
        $msg = 'Overtime submitted.';

        $empMeta = $conn->prepare("SELECT full_name, employee_code, company_id FROM employees WHERE id=? LIMIT 1");
        $empMeta->execute([$employee_id]);
        $empMeta = $empMeta->fetch(PDO::FETCH_ASSOC) ?: [];
        $empLabel = trim(($empMeta['full_name'] ?? '') . ' (' . ($empMeta['employee_code'] ?? ('#' . $employee_id)) . ')');
        audit_bridge_hr_ops(
            'overtime_created',
            'overtime_entries',
            $otId > 0 ? $otId : $employee_id,
            'Created overtime for ' . $empLabel . ' on ' . $ot_date
                . ' (' . number_format((float)$pay_hours, 2) . 'h, AED ' . number_format((float)$pay_amount, 2) . ')',
            isset($empMeta['company_id']) ? (int)$empMeta['company_id'] : null,
            [
                'employee_id' => $employee_id,
                'ot_date' => $ot_date,
                'pay_hours' => $pay_hours,
                'pay_amount' => $pay_amount,
                'pay_rate' => $pay_rate,
                'pay_multiplier' => $pay_multiplier,
                'reason' => $reason !== '' ? $reason : null,
            ],
            'OT #' . ($otId > 0 ? $otId : '?') . ' — ' . $empLabel,
            $uid ? (int)$uid : null
        );
    } catch (Exception $e) {
        $err = $e->getMessage();
    }
}

if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['change_status'])) {
    csrf_verify();
    $id   = (int)($_POST['id'] ?? 0);
    $new  = $_POST['new_status'] ?? '';
    if ($id && in_array($new, ['approved','rejected'], true)) {
        $prev = $conn->prepare("
            SELECT ot.*, e.full_name, e.employee_code, e.company_id
            FROM overtime_entries ot
            JOIN employees e ON e.id = ot.employee_id
            WHERE ot.id = ? LIMIT 1
        ");
        $prev->execute([$id]);
        $prevRow = $prev->fetch(PDO::FETCH_ASSOC);

        $u = $conn->prepare("UPDATE overtime_entries
                             SET status=?, approver_id=?, decided_at=NOW(), updated_at=NOW(), updated_by=?
                             WHERE id=?");
        $u->execute([$new, $uid, $uid, $id]);
        $msg = "Request #{$id} {$new}.";

        if ($prevRow) {
            $empLabel = trim(($prevRow['full_name'] ?? '') . ' (' . ($prevRow['employee_code'] ?? '') . ')');
            audit_bridge_hr_ops(
                $new === 'approved' ? 'overtime_approved' : 'overtime_rejected',
                'overtime_entries',
                $id,
                ucfirst($new) . ' overtime #' . $id . ' for ' . $empLabel
                    . ' on ' . ($prevRow['ot_date'] ?? '')
                    . ' (' . number_format((float)($prevRow['pay_hours'] ?? 0), 2) . 'h)',
                isset($prevRow['company_id']) ? (int)$prevRow['company_id'] : null,
                [
                    'status' => $new,
                    'employee_id' => (int)($prevRow['employee_id'] ?? 0),
                    'ot_date' => $prevRow['ot_date'] ?? null,
                    'pay_hours' => $prevRow['pay_hours'] ?? null,
                    'pay_amount' => $prevRow['pay_amount'] ?? null,
                    'from_status' => $prevRow['status'] ?? null,
                ],
                'OT #' . $id . ' — ' . $empLabel,
                $uid ? (int)$uid : null
            );
        }
    }
}

/* ---------------- Lookups ---------------- */

$rules = $conn->query("
  SELECT id, name, weekday_rate, weekend_rate, holiday_rate, round_to_minutes, base_hourly
  FROM overtime_rules
  WHERE active=1
  ORDER BY id
")->fetchAll(PDO::FETCH_ASSOC);

/* ---------------- Filters & listing ---------------- */

$emp_filter = (int)($_GET['employee_id'] ?? 0);
$from = $_GET['from'] ?? '';
$to   = $_GET['to'] ?? '';
$status = $_GET['status'] ?? '';
$companies = hr_active_companies($conn);
$selectedCompanyId = hr_selected_company_id($conn, $companies);

$employeeSql = "
  SELECT e.id, CONCAT(e.full_name,' (',e.employee_code,')') AS label, c.name AS company_name
  FROM employees e
  LEFT JOIN companies c ON c.id = e.company_id
";
$employeeWhere = ["e.status IN (" . hr_employee_status_in_sql(hr_employee_current_statuses()) . ")"];
$employeeParams = hr_employee_current_statuses();
if ($selectedCompanyId > 0) {
  $employeeWhere[] = "e.company_id = ?";
  $employeeParams[] = $selectedCompanyId;
}
$employeeSql .= " WHERE " . implode(' AND ', $employeeWhere);
$employeeSql .= " ORDER BY e.full_name";
$employeeStmt = $conn->prepare($employeeSql);
$employeeStmt->execute($employeeParams);
$employees = $employeeStmt->fetchAll(PDO::FETCH_ASSOC);

$where = []; $prm = [];
if ($emp_filter) { $where[] = "ot.employee_id=?"; $prm[] = $emp_filter; }
hr_add_company_where($where, $prm, $selectedCompanyId, 'e.company_id');
if ($from) { $where[] = "ot.ot_date>=?"; $prm[] = $from; }
if ($to)   { $where[] = "ot.ot_date<=?"; $prm[] = $to; }
if ($status !== '') { $where[] = "ot.status=?"; $prm[] = $status; }
$SQLWHERE = $where ? ('WHERE '.implode(' AND ', $where)) : '';

$rowsStmt = $conn->prepare("
  SELECT ot.*, e.employee_code, e.full_name, c.name AS company_name
  FROM overtime_entries ot
  JOIN employees e ON e.id=ot.employee_id
  LEFT JOIN companies c ON c.id = e.company_id
  $SQLWHERE
  ORDER BY ot.ot_date DESC, ot.id DESC
");
$rowsStmt->execute($prm);
$rows = $rowsStmt->fetchAll(PDO::FETCH_ASSOC);

// totals (approved only) respecting same filters
$totPrm = $prm;
$totWhere = $SQLWHERE ? ($SQLWHERE.' AND ot.status="approved"') : 'WHERE ot.status="approved"';
$totStmt = $conn->prepare("
  SELECT COALESCE(SUM(ot.pay_hours),0) AS tot_hours,
         COALESCE(SUM(ot.pay_amount),0) AS tot_amount
  FROM overtime_entries ot
  JOIN employees e ON e.id=ot.employee_id
  $totWhere
");
$totStmt->execute($totPrm);
$tot = $totStmt->fetch(PDO::FETCH_ASSOC);

// Page settings for shared layout
$pageTitle = 'Overtime';
?>
<?php require_once __DIR__ . '/includes/hr_layout_header.php'; ?>

<?php
echo hr_ui_page_header(
    'Overtime',
    'Track overtime hours, approvals, and pay calculations.',
    [
        ['label' => 'HR', 'href' => $hrBase . '/dashboard'],
        ['label' => 'Overtime'],
    ],
    '<a href="../operation.php" class="btn btn-outline-secondary">Back</a>'
);
?>

  <?php if ($err): ?><div class="alert alert-danger"><?= h($err) ?></div><?php endif; ?>
  <?php if ($msg): ?><div class="alert alert-success"><?= h($msg) ?></div><?php endif; ?>

  <div class="hr-filter-bar mb-3">
      <form class="row g-3">
        <div class="col-md-3">
          <label class="form-label">Employee</label>
          <select name="employee_id" class="form-select">
            <option value="">All</option>
            <?php foreach ($employees as $e): ?>
              <option value="<?= (int)$e['id'] ?>" <?= $emp_filter==(int)$e['id']?'selected':''; ?>>
                <?= h($e['label']) ?><?= !empty($e['company_name']) ? ' - ' . h($e['company_name']) : '' ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-3">
          <label class="form-label">Company</label>
          <select name="company_id" class="form-select">
            <option value="0">All companies</option>
            <?php foreach ($companies as $company): ?>
              <option value="<?= (int)$company['id'] ?>" <?= $selectedCompanyId === (int)$company['id'] ? 'selected' : '' ?>>
                <?= h($company['name']) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-2">
          <label class="form-label">From</label>
          <input type="date" name="from" class="form-control" value="<?= h($from) ?>">
        </div>
        <div class="col-md-2">
          <label class="form-label">To</label>
          <input type="date" name="to" class="form-control" value="<?= h($to) ?>">
        </div>
        <div class="col-md-2">
          <label class="form-label">Status</label>
          <select name="status" class="form-select">
            <option value="">All</option>
            <?php foreach (['pending','approved','rejected'] as $s): ?>
              <option value="<?= $s ?>" <?= $status===$s?'selected':''; ?>><?= ucfirst($s) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-3 d-flex align-items-end">
          <button class="btn btn-primary w-100">Apply</button>
        </div>
      </form>
  </div>

  <div class="mb-2">
    <button class="btn btn-success" data-bs-toggle="modal" data-bs-target="#otModal">+ Add Overtime</button>
  </div>

  <div class="hr-settings-card">
    <div class="settings-header">Overtime entries</div>
    <div class="card-body p-0">
    <div class="hr-table-shell border-0 shadow-none rounded-0">
      <table class="table table-hover align-middle mb-0">
        <thead class="table-light">
          <tr>
            <th>#</th><th>Date</th><th>Employee</th><th>Company</th><th>Time / Hours</th><th>Reason</th>
            <th>Status</th><th>Pay (hrs × rate × mult)</th><th>File</th><th class="text-end">Actions</th>
          </tr>
        </thead>
        <tbody>
        <?php if (!$rows): ?>
          <tr><td colspan="10" class="text-center text-muted py-4">No entries.</td></tr>
        <?php else: foreach ($rows as $r): ?>
          <tr>
            <td><?= (int)$r['id'] ?></td>
            <td><?= h($r['ot_date']) ?></td>
            <td><?= h($r['full_name']).' ('.h($r['employee_code']).')' ?></td>
            <td><?= h($r['company_name'] ?: '—') ?></td>
            <td>
              <?php
                $times = [];
                if ($r['start_time']) $times[] = h(substr($r['start_time'],0,5));
                if ($r['end_time'])   $times[] = '→ '.h(substr($r['end_time'],0,5));
                echo $times ? implode(' ', $times).' · ' : '';
                echo number_format((float)$r['pay_hours'],2).' h';
              ?>
            </td>
            <td><?= h($r['reason']) ?></td>
            <td>
              <?php $cls = ['pending'=>'warning','approved'=>'success','rejected'=>'danger'][$r['status']] ?? 'secondary'; ?>
              <span class="badge text-bg-<?= $cls ?>"><?= h($r['status']) ?></span>
            </td>
            <td><?= number_format((float)$r['pay_hours'],2).' × '.number_format((float)$r['pay_rate'],2).' × '.number_format((float)$r['pay_multiplier'],2).' = <strong>'.number_format((float)$r['pay_amount'],2).'</strong>' ?></td>
            <td>
              <?php if (!empty($r['attachment_path'])): ?>
                <a href="../<?= h($r['attachment_path']) ?>" target="_blank">File</a>
              <?php else: ?>—<?php endif; ?>
            </td>
            <td class="text-end">
              <?php if ($r['status']==='pending'): ?>
                <form method="post" class="d-inline">
                  <?php csrf_field(); ?>
                  <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                  <input type="hidden" name="change_status" value="1">
                  <input type="hidden" name="new_status" value="approved">
                  <button class="btn btn-sm btn-success">Approve</button>
                </form>
                <form method="post" class="d-inline">
                  <?php csrf_field(); ?>
                  <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                  <input type="hidden" name="change_status" value="1">
                  <input type="hidden" name="new_status" value="rejected">
                  <button class="btn btn-sm btn-outline-danger">Reject</button>
                </form>
              <?php else: ?>
                <span class="text-muted">—</span>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
    </div>
  </div>

  <div class="alert alert-info mt-3">
    <strong>Approved totals (in current filter):</strong>
    Hours: <?= number_format((float)$tot['tot_hours'],2) ?> &nbsp; | &nbsp;
    Amount: <?= number_format((float)$tot['tot_amount'],2) ?>
  </div>
</div>

<!-- Add Overtime Modal -->
<div class="modal fade" id="otModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg">
    <form class="modal-content" method="post" enctype="multipart/form-data">
      <?php csrf_field(); ?>
      <input type="hidden" name="create" value="1">
      <div class="modal-header">
        <h5 class="modal-title">Add Overtime</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div class="row g-3">
          <div class="col-md-6">
            <label class="form-label">Employee *</label>
            <select name="employee_id" class="form-select" required>
              <option value="">-- Select --</option>
              <?php foreach ($employees as $e): ?>
                <option value="<?= (int)$e['id'] ?>"><?= h($e['label']) ?><?= !empty($e['company_name']) ? ' - ' . h($e['company_name']) : '' ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-3">
            <label class="form-label">Date *</label>
            <input type="date" name="ot_date" class="form-control" required>
          </div>
          <div class="col-md-3">
            <label class="form-label">Policy</label>
            <select name="rule_id" class="form-select">
              <option value="">— none —</option>
              <?php foreach ($rules as $r): ?>
                <option value="<?= (int)$r['id'] ?>">
                  <?= h($r['name']) ?> (Wkdy <?= (float)$r['weekday_rate'] ?> · Wknd <?= (float)$r['weekend_rate'] ?> · Hol <?= (float)$r['holiday_rate'] ?>)
                </option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="col-md-3">
            <label class="form-label">Start</label>
            <input type="time" name="start_time" class="form-control">
          </div>
          <div class="col-md-3">
            <label class="form-label">End</label>
            <input type="time" name="end_time" class="form-control">
          </div>
          <div class="col-md-3">
            <label class="form-label">Pay hours (optional)</label>
            <input type="number" step="0.01" min="0" name="manual_hours" class="form-control">
          </div>
          <div class="col-md-3">
            <label class="form-label">Hourly rate</label>
            <input type="number" step="0.01" min="0" name="hourly_rate" class="form-control" placeholder="use rule base if blank">
          </div>

          <div class="col-12">
            <label class="form-label">Reason</label>
            <input type="text" name="reason" class="form-control">
          </div>
          <div class="col-12">
            <label class="form-label">Attachment</label>
            <input type="file" name="attachment" class="form-control" accept=".jpg,.jpeg,.png,.pdf">
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-success">Save</button>
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
      </div>
    </form>
  </div>
</div>

<?php require_once __DIR__ . '/includes/hr_layout_footer.php'; ?>
