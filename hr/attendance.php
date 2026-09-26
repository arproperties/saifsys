<?php


require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/../includes/audit_bridge.php';
require_once __DIR__ . '/../includes/hr_attendance_attachments.php';
require_once __DIR__ . '/includes/hr_company_scope.php';
require_once __DIR__ . '/includes/hr_employee_lifecycle.php';
require_role(['Owner','Admin','HR'], $conn);

if (session_status() !== PHP_SESSION_ACTIVE) session_start();

$me_id = $_SESSION['user']['id'] ?? null;

/* ---------- Helpers ---------- */
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function safe_time($t){ return $t !== '' ? $t : null; }
function calc_hours($in, $out){
    if (!$in || !$out) return null;
    // both "HH:MM" or "HH:MM:SS" are fine
    $a = strtotime("1970-01-01 $in UTC");
    $b = strtotime("1970-01-01 $out UTC");
    if ($a===false || $b===false || $b <= $a) return null;
    $diff = ($b - $a) / 3600.0;
    return round($diff, 2);
}

$attStatuses = hr_attendance_statuses();
$attStatusKeys = hr_attendance_status_keys();
$attAccept = '.' . implode(',.', hr_attendance_attach_allowed_extensions());

/* ---------- Quick Actions (POST) ---------- */
$flash_err = $flash_ok = '';
$flash_warn = [];

// A POST over post_max_size arrives with $_POST and $_FILES both empty, so CSRF
// would fail first and blame the wrong thing. Check the size before anything.
if (hr_attendance_post_too_large()) {
    $flash_err = 'The upload was larger than the server allows (' . h(hr_attendance_post_max_label()) . '). Nothing was changed — attach a smaller file.';
} elseif ($_SERVER['REQUEST_METHOD']==='POST') {
    csrf_verify();

    // Quick add (single day)
    if (isset($_POST['action']) && $_POST['action']==='quick_add') {
        $employee_id = (int)($_POST['employee_id'] ?? 0);
        $work_date   = $_POST['work_date'] ?? '';
        $check_in    = safe_time($_POST['check_in'] ?? '');
        $check_out   = safe_time($_POST['check_out'] ?? '');
        $status      = $_POST['status'] ?? 'approved';
        $notes       = trim($_POST['notes'] ?? '');

        if (!in_array($status, $attStatusKeys, true) || !hr_attendance_status_supported($conn, $status)) {
            $status = hr_attendance_status_supported($conn, 'pending') ? 'pending' : 'approved';
        }

        if (!$employee_id || !$work_date) {
            $flash_err = 'Employee and Date are required.';
        } else {
            $hours = calc_hours($check_in, $check_out);
            try {
                $empMeta = $conn->prepare("SELECT full_name, employee_code, company_id FROM employees WHERE id=? LIMIT 1");
                $empMeta->execute([$employee_id]);
                $empMeta = $empMeta->fetch(PDO::FETCH_ASSOC) ?: [];
                $empLabel = trim(($empMeta['full_name'] ?? '') . ' (' . ($empMeta['employee_code'] ?? ('#' . $employee_id)) . ')');
                $empCompanyId = isset($empMeta['company_id']) ? (int)$empMeta['company_id'] : null;

                // Upsert-like: try insert; if duplicate (unique employee_id+date), update
                $ins = $conn->prepare("
                    INSERT INTO attendance (employee_id, work_date, check_in, check_out, hours, status, source, notes, created_by, updated_by)
                    VALUES (?,?,?,?,?,?, 'admin', ?, ?, ?)
                ");
                $ins->execute([$employee_id,$work_date,$check_in,$check_out,$hours,$status,$notes,$me_id,$me_id]);
                $attId = (int)$conn->lastInsertId();
                $flash_ok = 'Attendance added.';
                audit_bridge_hr_ops(
                    'attendance_recorded',
                    'attendance',
                    $attId > 0 ? $attId : $employee_id,
                    'Recorded attendance for ' . $empLabel . ' on ' . $work_date . ' as ' . $status,
                    $empCompanyId,
                    [
                        'employee_id' => $employee_id,
                        'work_date' => $work_date,
                        'status' => $status,
                        'check_in' => $check_in,
                        'check_out' => $check_out,
                        'hours' => $hours,
                    ],
                    $empLabel . ' @ ' . $work_date,
                    $me_id ? (int)$me_id : null
                );
            } catch (PDOException $e) {
                if (strpos($e->getMessage(), 'Duplicate') !== false) {
                    $upd = $conn->prepare("
                        UPDATE attendance
                           SET check_in=?, check_out=?, hours=?, status=?, notes=?, updated_by=?, updated_at=NOW()
                         WHERE employee_id=? AND work_date=?
                    ");
                    $upd->execute([$check_in,$check_out,$hours,$status,$notes,$me_id,$employee_id,$work_date]);
                    $flash_ok = 'Existing attendance updated.';
                    $idSt = $conn->prepare("SELECT id FROM attendance WHERE employee_id=? AND work_date=? LIMIT 1");
                    $idSt->execute([$employee_id, $work_date]);
                    $attId = (int)$idSt->fetchColumn();
                    audit_bridge_hr_ops(
                        'attendance_updated',
                        'attendance',
                        $attId > 0 ? $attId : $employee_id,
                        'Updated attendance for ' . $empLabel . ' on ' . $work_date . ' to ' . $status,
                        $empCompanyId,
                        [
                            'employee_id' => $employee_id,
                            'work_date' => $work_date,
                            'status' => $status,
                            'check_in' => $check_in,
                            'check_out' => $check_out,
                            'hours' => $hours,
                        ],
                        $empLabel . ' @ ' . $work_date,
                        $me_id ? (int)$me_id : null
                    );
                } else {
                    $flash_err = 'DB error: '.$e->getMessage();
                }
            }
        }
    }

    // Quick status change — reason and at least one supporting file are required.
    if (isset($_POST['action']) && $_POST['action']==='status' && isset($_POST['id'])) {
        $id   = (int)$_POST['id'];
        $new  = $_POST['to'] ?? '';
        $note = trim((string)($_POST['note'] ?? ''));
        $files = $_FILES['attachments'] ?? null;

        if (!in_array($new, $attStatusKeys, true)) {
            $flash_err = 'Invalid status.';
        } elseif (!hr_attendance_status_supported($conn, $new)) {
            $flash_err = 'This database cannot store the ' . hr_attendance_status_label($new)
                . ' status yet — run migrations/hr_attendance_excused_absent.sql first.';
        } elseif ($note === '') {
            $flash_err = 'A reason is required for every status change.';
        } elseif (!hr_attendance_files_chosen($files)) {
            $flash_err = 'A supporting document is required for every status change.';
        } else {
            $prev = $conn->prepare("
                SELECT a.*, e.full_name, e.employee_code, e.company_id
                FROM attendance a
                JOIN employees e ON e.id = a.employee_id
                WHERE a.id = ? LIMIT 1
            ");
            $prev->execute([$id]);
            $prevRow = $prev->fetch(PDO::FETCH_ASSOC);

            if (!$prevRow) {
                $flash_err = 'That attendance row no longer exists.';
            } else {
                $note = mb_substr($note, 0, HR_ATTENDANCE_NOTE_MAX);
                $u = $conn->prepare("UPDATE attendance SET status=?, notes=?, updated_by=?, updated_at=NOW() WHERE id=?");
                $u->execute([$new,$note,$me_id,$id]);

                $changeId = hr_attendance_log_status_change(
                    $conn,
                    $id,
                    $prevRow['status'] ?? null,
                    $new,
                    $note,
                    $prevRow['notes'] ?? null,
                    $me_id ? (int)$me_id : null
                );

                $uploadErrors = [];
                $savedFiles = hr_attendance_attachments_save(
                    $conn, $id, $changeId, $me_id ? (int)$me_id : null, (array)$files, $uploadErrors
                );

                $flash_ok = 'Marked as ' . hr_attendance_status_label($new)
                    . ($savedFiles > 0 ? ' with ' . $savedFiles . ' attachment' . ($savedFiles === 1 ? '' : 's') . '.' : '.');
                if ($savedFiles === 0) {
                    $flash_warn[] = 'The status was changed but no file could be stored — attach the document again from Edit.';
                }
                foreach ($uploadErrors as $ue) { $flash_warn[] = $ue; }

                $empLabel = trim(($prevRow['full_name'] ?? '') . ' (' . ($prevRow['employee_code'] ?? '') . ')');
                audit_bridge_hr_ops(
                    'attendance_status_changed',
                    'attendance',
                    $id,
                    'Changed attendance status for ' . $empLabel . ' on ' . ($prevRow['work_date'] ?? '')
                        . ' from ' . ($prevRow['status'] ?? '') . ' to ' . $new . ' — ' . $note,
                    isset($prevRow['company_id']) ? (int)$prevRow['company_id'] : null,
                    [
                        'from_status' => $prevRow['status'] ?? null,
                        'to_status' => $new,
                        'work_date' => $prevRow['work_date'] ?? null,
                        'employee_id' => (int)($prevRow['employee_id'] ?? 0),
                        'note' => $note,
                        'attachments' => $savedFiles,
                    ],
                    $empLabel . ' @ ' . ($prevRow['work_date'] ?? ''),
                    $me_id ? (int)$me_id : null
                );
            }
        }
    }

    // Delete
    if (isset($_POST['action']) && $_POST['action']==='delete' && isset($_POST['id'])) {
        $id = (int)$_POST['id'];
        $prev = $conn->prepare("
            SELECT a.*, e.full_name, e.employee_code, e.company_id
            FROM attendance a
            JOIN employees e ON e.id = a.employee_id
            WHERE a.id = ? LIMIT 1
        ");
        $prev->execute([$id]);
        $prevRow = $prev->fetch(PDO::FETCH_ASSOC);
        // Files first: the rows go with the FK cascade, the files on disk do not.
        hr_attendance_attachments_purge($conn, $id);
        $conn->prepare("DELETE FROM attendance WHERE id=?")->execute([$id]);
        $flash_ok = 'Attendance row deleted.';
        if ($prevRow) {
            $empLabel = trim(($prevRow['full_name'] ?? '') . ' (' . ($prevRow['employee_code'] ?? '') . ')');
            audit_bridge_hr_ops(
                'attendance_deleted',
                'attendance',
                $id,
                'Deleted attendance for ' . $empLabel . ' on ' . ($prevRow['work_date'] ?? '')
                    . ' (was ' . ($prevRow['status'] ?? '') . ')',
                isset($prevRow['company_id']) ? (int)$prevRow['company_id'] : null,
                [
                    'work_date' => $prevRow['work_date'] ?? null,
                    'status' => $prevRow['status'] ?? null,
                    'employee_id' => (int)($prevRow['employee_id'] ?? 0),
                ],
                $empLabel . ' @ ' . ($prevRow['work_date'] ?? ''),
                $me_id ? (int)$me_id : null
            );
        }
    }
}

/* ---------- Filters ---------- */
$df = $_GET['date_from'] ?? date('Y-m-01');
$dt = $_GET['date_to']   ?? date('Y-m-d');
$emp = (int)($_GET['employee_id'] ?? 0);
$st  = $_GET['status'] ?? '';
$companies = hr_active_companies($conn);
$selectedCompanyId = hr_selected_company_id($conn, $companies);

$where = [];
$prms  = [];

if ($df) { $where[] = "a.work_date >= ?"; $prms[] = $df; }
if ($dt) { $where[] = "a.work_date <= ?"; $prms[] = $dt; }
if ($emp){ $where[] = "a.employee_id = ?"; $prms[] = $emp; }
hr_add_company_where($where, $prms, $selectedCompanyId, 'e.company_id');
if ($st !== '' && in_array($st, $attStatusKeys, true)) {
    $where[] = "a.status = ?"; $prms[] = $st;
}
// Who recorded it: HR by hand, the staff member themselves, or an import.
$src = $_GET['source'] ?? '';
if ($src !== '' && in_array($src, ['admin','self','import'], true)) {
    $where[] = "a.source = ?"; $prms[] = $src;
}

$sqlWhere = $where ? ('WHERE '.implode(' AND ', $where)) : '';

/* ---------- Lookups ---------- */
$employeeSql = "
    SELECT e.id, CONCAT(e.full_name,' (',e.employee_code,')') label, c.name AS company_name
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

/* ---------- Query rows ---------- */
$stmt = $conn->prepare("
SELECT a.*, e.full_name, e.employee_code, c.name AS company_name
  FROM attendance a
  JOIN employees e ON e.id=a.employee_id
  LEFT JOIN companies c ON c.id = e.company_id
  $sqlWhere
 ORDER BY a.work_date DESC, e.full_name ASC
 LIMIT 500
");
$stmt->execute($prms);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

// One query for every row's files rather than one per row.
$attachmentsByRow = hr_attendance_attachments_map($conn, array_column($rows, 'id'));

$missingStatuses = hr_attendance_missing_statuses($conn);
$excusedReady = hr_attendance_status_supported($conn, 'excused_absent');

// Page settings for shared layout
$pageTitle = 'Attendance';
$pageStyles = hr_attendance_status_css();
?>
<?php require_once __DIR__ . '/includes/hr_layout_header.php'; ?>

<?php
echo hr_ui_page_header(
    'Attendance',
    'Daily attendance records, quick entry, and approvals.',
    [
        ['label' => 'HR', 'href' => $hrBase . '/dashboard'],
        ['label' => 'Attendance'],
    ],
    '<a class="btn btn-outline-secondary" href="../operation.php">Back</a>'
    . '<a class="btn btn-outline-primary" href="attendance_bulk.php">Bulk Apply</a>'
    . '<a class="btn btn-outline-primary" href="attendance_summary.php">Monthly Summary</a>'
);
?>

  <?php if ($flash_err): ?><div class="alert alert-danger"><?= h($flash_err) ?></div><?php endif; ?>
  <?php if ($flash_ok):  ?><div class="alert alert-success"><?= h($flash_ok)  ?></div><?php endif; ?>
  <?php if ($flash_warn): ?>
    <div class="alert alert-warning">
      <ul class="mb-0 small"><?php foreach ($flash_warn as $w): ?><li><?= h($w) ?></li><?php endforeach; ?></ul>
    </div>
  <?php endif; ?>
  <?php if ($missingStatuses): ?>
    <div class="alert alert-warning">
      <strong><?= h(implode(' and ', $missingStatuses)) ?>
        <?= count($missingStatuses) === 1 ? 'is' : 'are' ?> not available on this database yet.</strong>
      Run <code>migrations/hr_attendance_excused_absent.sql</code> to add
      <?= count($missingStatuses) === 1 ? 'it' : 'them' ?>.
    </div>
  <?php endif; ?>

  <div class="hr-filter-bar mb-3">
  <form method="get">
        <?php csrf_field(); ?>
    <div class="row g-2">
      <div class="col-md-3">
        <label class="form-label">From</label>
        <input type="date" name="date_from" class="form-control" value="<?= h($df) ?>">
      </div>
      <div class="col-md-3">
        <label class="form-label">To</label>
        <input type="date" name="date_to" class="form-control" value="<?= h($dt) ?>">
      </div>
      <div class="col-md-3">
        <label class="form-label">Employee</label>
        <select name="employee_id" class="form-select">
          <option value="0">— All —</option>
          <?php foreach ($employees as $e): ?>
            <option value="<?= (int)$e['id'] ?>" <?= $emp==(int)$e['id']?'selected':''; ?>>
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
        <label class="form-label">Status</label>
        <select name="status" class="form-select">
          <?php
            $opts = ['' => '— Any —'] + $attStatuses;
            foreach ($opts as $k=>$v) {
              $sel = ($st===$k)?'selected':'';
              echo "<option value=\"".h($k)."\" $sel>".h($v)."</option>";
            }
          ?>
        </select>
      </div>
      <div class="col-md-2">
        <label class="form-label">Recorded by</label>
        <select name="source" class="form-select">
          <?php
            $srcOpts = ['' => '— Any —', 'admin' => 'HR', 'self' => 'Staff (self)', 'import' => 'Import'];
            foreach ($srcOpts as $k => $v) {
              $sel = ($src === $k) ? 'selected' : '';
              echo "<option value=\"".h($k)."\" $sel>".h($v)."</option>";
            }
          ?>
        </select>
      </div>
      <div class="col-md-1 d-flex align-items-end">
        <button class="btn btn-primary w-100">Apply</button>
      </div>
    </div>
  </form>
  </div>

  <div class="hr-settings-card mb-3">
    <div class="settings-header">Quick Add (single day)</div>
    <form class="card-body row g-2" method="post">
        <?php csrf_field(); ?>
      <input type="hidden" name="action" value="quick_add">
      <div class="col-lg-4">
        <label class="form-label">Employee *</label>
        <select name="employee_id" class="form-select" required>
          <option value="">-- choose --</option>
          <?php foreach ($employees as $e): ?>
            <option value="<?= (int)$e['id'] ?>"><?= h($e['label']) ?><?= !empty($e['company_name']) ? ' - ' . h($e['company_name']) : '' ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-lg-2">
        <label class="form-label">Date *</label>
        <input type="date" name="work_date" class="form-control" required value="<?= h($dt) ?>">
      </div>
      <div class="col-lg-2">
        <label class="form-label">In</label>
        <input type="time" name="check_in" class="form-control">
      </div>
      <div class="col-lg-2">
        <label class="form-label">Out</label>
        <input type="time" name="check_out" class="form-control">
      </div>
      <div class="col-lg-2">
        <label class="form-label">Status</label>
        <select name="status" class="form-select">
          <?php foreach ($attStatuses as $k => $v): ?>
            <?php if (!hr_attendance_status_supported($conn, $k)) continue; ?>
            <option value="<?= h($k) ?>"><?= h($v) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-12">
        <input type="text" name="notes" class="form-control" maxlength="255" placeholder="Notes (optional)">
      </div>
      <div class="col-12 d-flex justify-content-end">
        <button class="btn btn-success">Add / Update</button>
      </div>
    </form>
  </div>

  <div class="hr-settings-card">
    <div class="settings-header d-flex justify-content-between align-items-center">
      <span>Results (max 500)</span>
      <span class="small text-muted">Every status change asks for a reason and a document.</span>
    </div>
    <div class="card-body p-0">
    <div class="hr-table-shell border-0 shadow-none rounded-0">
      <table class="table table-hover align-middle mb-0">
        <thead class="table-light">
          <tr>
            <th>#</th>
            <th>Date</th>
            <th>Employee</th>
            <th>Company</th>
            <th>In</th>
            <th>Out</th>
            <th>Hours</th>
            <th>Status</th>
            <th>Source</th>
            <th>Notes</th>
            <th>Files</th>
            <th class="text-end">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if (!$rows): ?>
            <tr><td colspan="12" class="text-center text-muted py-4">No rows.</td></tr>
          <?php else: foreach ($rows as $r): ?>
            <?php
              $rowId    = (int)$r['id'];
              $rowEmp   = $r['full_name'].' ('.$r['employee_code'].')';
              $rowFiles = $attachmentsByRow[$rowId] ?? [];
            ?>
            <tr>
              <td><?= $rowId ?></td>
              <td><?= h($r['work_date']) ?></td>
              <td><?= h($rowEmp) ?></td>
              <td><?= h($r['company_name'] ?: '—') ?></td>
              <td><?= h($r['check_in'] ?: '—') ?></td>
              <td>
                <?= h($r['check_out']?: '—') ?>
                <?php
                  // Break columns only exist once migrations/hr_attendance_break.sql
                  // has run, so read them defensively. A break is recorded only —
                  // it is never taken off the hours beside it.
                  $rowBreakMins  = $r['break_minutes'] ?? null;
                  $rowBreakStart = $r['break_start'] ?? null;
                ?>
                <?php if ($rowBreakStart): ?>
                  <div class="small text-muted">
                    Break <?= $rowBreakMins !== null ? (int)$rowBreakMins . ' min' : 'open' ?>
                    <?php if (!empty($r['break_end'])): ?>
                      (<?= h(substr((string)$rowBreakStart, 0, 5)) ?>–<?= h(substr((string)$r['break_end'], 0, 5)) ?>)
                    <?php else: ?>
                      (from <?= h(substr((string)$rowBreakStart, 0, 5)) ?>)
                    <?php endif; ?>
                  </div>
                <?php endif; ?>
              </td>
              <td><?= $r['hours']!==null ? number_format((float)$r['hours'],2) : '—' ?></td>
              <td>
                <?= hr_attendance_status_badge_html($r['status']) ?>
                <?php if ($r['status'] === 'absent' && strpos((string)($r['notes'] ?? ''), 'Worker Availability absent:') === 0): ?>
                  <span class="badge text-bg-info ms-1">From Operation</span>
                <?php endif; ?>
              </td>
              <td>
                <?php $srcVal = (string)($r['source'] ?? 'admin'); ?>
                <?php if ($srcVal === 'self'): ?>
                  <span class="badge text-bg-primary">Self</span>
                <?php elseif ($srcVal === 'import'): ?>
                  <span class="badge text-bg-secondary">Import</span>
                <?php else: ?>
                  <span class="text-muted small">HR</span>
                <?php endif; ?>
              </td>
              <td><?= h($r['notes'] ?: '') ?></td>
              <td>
                <?php if (!$rowFiles): ?>
                  <span class="text-muted small">—</span>
                <?php else: foreach ($rowFiles as $f): ?>
                  <a class="d-inline-block me-1" target="_blank" rel="noopener"
                     href="<?= h(hr_attendance_attach_href((int)$f['id'])) ?>"
                     title="<?= h($f['file_name']) ?> (<?= h(hr_attendance_attach_size((int)$f['file_size'])) ?>)">
                    <i class="bi <?= h(hr_attendance_attach_icon((string)$f['file_name'])) ?>"></i>
                  </a>
                <?php endforeach; endif; ?>
              </td>
              <td class="text-end">
                <a class="btn btn-sm btn-outline-primary" href="attendance_edit.php?id=<?= $rowId ?>">Edit</a>

                <button type="button" class="btn btn-sm btn-success"
                        data-att-change
                        data-att-id="<?= $rowId ?>"
                        data-att-to="approved"
                        data-att-emp="<?= h($rowEmp) ?>"
                        data-att-date="<?= h($r['work_date']) ?>">Approve</button>

                <div class="btn-group">
                  <button class="btn btn-sm btn-outline-secondary dropdown-toggle" data-bs-toggle="dropdown">More</button>
                  <ul class="dropdown-menu dropdown-menu-end">
                    <li class="px-3 py-1">
                      <button type="button" class="btn btn-sm btn-outline-danger w-100"
                              data-att-change data-att-id="<?= $rowId ?>" data-att-to="absent"
                              data-att-emp="<?= h($rowEmp) ?>" data-att-date="<?= h($r['work_date']) ?>">Mark Absent</button>
                    </li>
                    <li class="px-3 py-1">
                      <button type="button" class="btn btn-sm btn-outline-warning w-100"
                              data-att-change data-att-id="<?= $rowId ?>" data-att-to="half"
                              data-att-emp="<?= h($rowEmp) ?>" data-att-date="<?= h($r['work_date']) ?>">Half Day</button>
                    </li>
                    <li class="px-3 py-1">
                      <button type="button" class="btn btn-sm btn-outline-info w-100"
                              data-att-change data-att-id="<?= $rowId ?>" data-att-to="on_leave"
                              data-att-emp="<?= h($rowEmp) ?>" data-att-date="<?= h($r['work_date']) ?>">On Leave</button>
                    </li>
                    <?php if ($excusedReady): ?>
                    <li class="px-3 py-1">
                      <button type="button" class="btn btn-sm btn-outline-hr-excused w-100"
                              data-att-change data-att-id="<?= $rowId ?>" data-att-to="excused_absent"
                              data-att-emp="<?= h($rowEmp) ?>" data-att-date="<?= h($r['work_date']) ?>">Excused Absent</button>
                    </li>
                    <?php endif; ?>
                    <?php if (hr_attendance_status_supported($conn, 'pending') && $r['status'] !== 'pending'): ?>
                    <li class="px-3 py-1">
                      <button type="button" class="btn btn-sm btn-outline-secondary w-100"
                              data-att-change data-att-id="<?= $rowId ?>" data-att-to="pending"
                              data-att-emp="<?= h($rowEmp) ?>" data-att-date="<?= h($r['work_date']) ?>">Back to Pending</button>
                    </li>
                    <?php endif; ?>
                    <li><hr class="dropdown-divider"></li>
                    <li>
                      <form method="post" class="px-3 py-1" onsubmit="return confirm('Delete this row? Its notes and attachments go with it.')">
              <?php csrf_field(); ?>
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="id" value="<?= $rowId ?>">
                        <button class="btn btn-sm btn-outline-danger w-100">Delete</button>
                      </form>
                    </li>
                  </ul>
                </div>

              </td>
            </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
    </div>
  </div>

  <!-- Every status change goes through here: reason + document, both required. -->
  <div class="modal fade" id="attStatusModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
      <form method="post" enctype="multipart/form-data" class="modal-content">
        <?php csrf_field(); ?>
        <input type="hidden" name="action" value="status">
        <input type="hidden" name="id" id="attStatusId" value="">
        <input type="hidden" name="to" id="attStatusTo" value="">
        <div class="modal-header">
          <h5 class="modal-title">Change attendance status</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          <p class="mb-1"><strong id="attStatusEmp"></strong></p>
          <p class="mb-3 text-muted small">Date: <span id="attStatusDate"></span> — new status: <span id="attStatusBadge"></span></p>

          <div class="mb-3">
            <label class="form-label">Reason <span class="text-danger">*</span></label>
            <textarea name="note" id="attStatusNote" class="form-control" rows="2"
                      maxlength="<?= (int)HR_ATTENDANCE_NOTE_MAX ?>" required
                      placeholder="Why is this being changed? e.g. Sick leave, medical certificate attached"></textarea>
            <div class="form-text">Replaces the Notes column. The previous note is kept in the change history.</div>
          </div>

          <div>
            <label class="form-label">Supporting document <span class="text-danger">*</span></label>
            <input type="file" name="attachments[]" id="attStatusFiles" class="form-control" multiple required
                   accept="<?= h($attAccept) ?>">
            <div class="form-text">
              At least one file. PDF, image, Word or Excel — max
              <?= (int)(HR_ATTENDANCE_ATTACH_MAX_BYTES / 1024 / 1024) ?> MB each,
              <?= (int)HR_ATTENDANCE_ATTACH_MAX_FILES ?> files per change.
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary">Save change</button>
        </div>
      </form>
    </div>
  </div>

<?php
$attStatusBadges = [];
foreach ($attStatuses as $k => $v) { $attStatusBadges[$k] = hr_attendance_status_badge_html($k); }
$pageScripts = '<script>'
. 'const ATT_BADGES = ' . json_encode($attStatusBadges, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) . ';'
. <<<'JS'
document.addEventListener('click', function (ev) {
  const btn = ev.target.closest('[data-att-change]');
  if (!btn) return;
  ev.preventDefault();

  document.getElementById('attStatusId').value = btn.dataset.attId || '';
  document.getElementById('attStatusTo').value = btn.dataset.attTo || '';
  document.getElementById('attStatusEmp').textContent = btn.dataset.attEmp || '';
  document.getElementById('attStatusDate').textContent = btn.dataset.attDate || '';
  document.getElementById('attStatusBadge').innerHTML = ATT_BADGES[btn.dataset.attTo] || '';

  const note = document.getElementById('attStatusNote');
  const files = document.getElementById('attStatusFiles');
  note.value = '';
  files.value = '';

  const open = document.querySelector('.dropdown-menu.show');
  if (open && window.bootstrap) {
    const toggle = open.parentElement && open.parentElement.querySelector('[data-bs-toggle="dropdown"]');
    if (toggle) { bootstrap.Dropdown.getOrCreateInstance(toggle).hide(); }
  }

  const modalEl = document.getElementById('attStatusModal');
  bootstrap.Modal.getOrCreateInstance(modalEl).show();
  modalEl.addEventListener('shown.bs.modal', function once() {
    note.focus();
    modalEl.removeEventListener('shown.bs.modal', once);
  });
});
JS
. '</script>';
?>

<?php require_once __DIR__ . '/includes/hr_layout_footer.php'; ?>
