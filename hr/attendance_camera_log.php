<?php
/**
 * Camera log: every match the camera PC sent, with its photo, whether it
 * changed the day or not. Read from the daily CSV files the camera API writes
 * (uploads/attendance_camera/log/<date>.csv), kept HR_CAMERA_LOG_DAYS days.
 *
 * Filters: dates, time of day, employee, camera, and what the match did.
 * ?export=csv downloads what the filters show. Owner, Admin and HR see the
 * log; the photos are staff photos, so the HR role only (as on Attendance).
 *
 * HR can tick matches and use their photos: "Reference" (the cameras also
 * recognise the person from it) or "Different person" (the cameras stop
 * matching faces like it to them). A match marked "Different person" is taken
 * off the log: its photo moves to the person's "Different person" faces, and
 * its row goes. When the match is the day's check-in or last check-out, a popup
 * asks whether to change or remove that time too (Pending days only).
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/includes/hr_attendance_cameras.php';
require_role(['Owner', 'Admin', 'HR'], $conn);
$canSeePhotos = has_role('HR', $conn);

if (!function_exists('h')) {
    function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
}

// One photo: ?photo=<file>&day=<Y-m-d>
if (isset($_GET['photo'])) {
    $path = !$canSeePhotos ? null : hr_camera_log_photo_path((string)($_GET['day'] ?? ''), (string)$_GET['photo']);
    if ($path === null || !is_file($path)) {
        http_response_code(404);
        exit;
    }
    header('Content-Type: image/jpeg');
    header('Content-Length: ' . filesize($path));
    header('Cache-Control: private, max-age=86400');
    header('X-Content-Type-Options: nosniff');
    readfile($path);
    exit;
}

// HR used the ticked photos: as references, or "Different person".
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    require_once __DIR__ . '/../includes/audit_bridge.php';
    csrf_verify();
    $action = in_array($_POST['action'] ?? '', ['reference', 'reject'], true) ? $_POST['action'] : '';
    $userId = function_exists('current_user_id') ? (current_user_id() ?: null) : null;
    $back = (string)($_POST['back'] ?? '');
    $backUrl = 'attendance_camera_log.php' . ($back !== '' && preg_match('/^[A-Za-z0-9_=&%:.+-]*$/', $back) ? '?' . $back : '');
    $picks = array_slice((array)($_POST['pick'] ?? []), 0, 50);

    // "Different person" on a match that is the day's check-in or check-out:
    // HR chose to keep, change or remove that time too (the popup). All of it
    // is checked first; if anything is wrong nothing changes.
    $attNote = '';
    if ($action === 'reject' && $canSeePhotos) {
        $attDone = hr_camera_log_fix_attendance($conn, $picks, (array)($_POST['att'] ?? []), $userId);
        if ($attDone['error'] !== null) {
            $_SESSION['cam_log_flash'] = ['danger', $attDone['error'] . ' Nothing was changed.'];
            header('Location: ' . $backUrl);
            exit;
        }
        $attNote = $attDone['note'];
    }

    $done = $failed = 0;
    foreach ($picks as $pick) {
        [$day, $photo] = array_pad(explode('|', (string)$pick, 2), 2, '');
        $row = ($canSeePhotos && $action !== '') ? hr_camera_log_find($day, $photo) : null;
        $path = $row ? hr_camera_log_photo_path($day, $photo) : null;
        if (!$row || $path === null || !is_file($path)) {
            $failed++;
            continue;
        }
        $id = (int)$row['employee_id'];
        $label = $row['employee_name'] . ' (' . $row['employee_code'] . ')';
        $when = $day . ' ' . $row['time'];
        if ($action === 'reference') {
            $score = $row['score'] !== '' ? (float)$row['score'] : HR_CAMERA_BEST_USE_MIN;
            $ok = hr_camera_learned_add($id, (string)file_get_contents($path), $score, true);
            $auditAction = 'attendance_camera_reference_added';
            $summary = 'Camera log photo of ' . $when . ' used as a reference face for ' . $label;
        } else {
            // The photo moves to their "Different person" faces, and the match leaves the log.
            $ok = hr_camera_reject($id, $path);
            if ($ok) {
                hr_camera_log_remove($day, $photo);
            }
            $auditAction = 'attendance_camera_face_rejected';
            $summary = 'Camera log photo of ' . $when . ' marked "Different person" for ' . $label;
        }
        if ($ok) {
            $done++;
            audit_bridge_hr_ops($auditAction, 'employees', $id, $summary, null,
                ['employee_id' => $id, 'from' => 'camera log', 'day' => $day, 'photo' => $photo, 'score' => $row['score']], $label, $userId);
        } else {
            $failed++;
        }
    }
    $_SESSION['cam_log_flash'] = $done > 0
        ? ['success', $done . ' photo' . ($done === 1 ? '' : 's') . ($action === 'reference' ? ' added as reference' : ' marked "Different person" and taken off the log')
            . '. The camera PC picks ' . ($done === 1 ? 'it' : 'them') . ' up within 15 seconds.' . ($failed ? ' ' . $failed . ' could not be used.' : '') . $attNote]
        : ['danger', 'Nothing was changed. Tick at least one photo first.'];
    header('Location: ' . $backUrl);
    exit;
}
$logFlash = $_SESSION['cam_log_flash'] ?? null;
unset($_SESSION['cam_log_flash']);

$today = (new DateTimeImmutable('now', new DateTimeZone('Asia/Dubai')))->format('Y-m-d');
$isDate = static fn($v) => is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v);
$isTime = static fn($v) => is_string($v) && preg_match('/^\d{2}:\d{2}$/', $v);
$from = $isDate($_GET['date_from'] ?? null) ? $_GET['date_from'] : $today;
$to   = $isDate($_GET['date_to'] ?? null) ? $_GET['date_to'] : $from;
if ($to < $from) {
    [$from, $to] = [$to, $from];
}
// At most 31 days at a time: a month of matches is plenty to look through.
if ((strtotime($to) - strtotime($from)) / 86400 > 30) {
    $from = date('Y-m-d', strtotime($to . ' -30 days'));
}
$timeFrom = $isTime($_GET['time_from'] ?? null) ? $_GET['time_from'] : '';
$timeTo   = $isTime($_GET['time_to'] ?? null) ? $_GET['time_to'] : '';
$empId    = (int)($_GET['employee_id'] ?? 0);
$camId    = (int)($_GET['camera_id'] ?? 0);
$what     = in_array($_GET['what'] ?? '', ['recorded', 'not_recorded'], true) ? $_GET['what'] : '';

$all = hr_camera_log_read($from, $to);

// The employees and cameras to choose from: everyone in this period's log.
$employees = $cameraNames = [];
foreach ($all as $r) {
    $employees[(int)$r['employee_id']] = $r['employee_name'] . ' (' . $r['employee_code'] . ')';
    if ($r['camera_id'] !== '') {
        $cameraNames[(int)$r['camera_id']] = $r['camera'] !== '' ? $r['camera'] : 'Camera #' . $r['camera_id'];
    }
}
foreach (hr_cameras_all($conn) as $c) {
    $cameraNames[(int)$c['id']] = $c['name'];
}
asort($employees);
asort($cameraNames);

$isRecorded = static fn(array $r) => strpos($r['result'], 'Check-') === 0;
$rows = array_values(array_filter($all, static function ($r) use ($timeFrom, $timeTo, $empId, $camId, $what, $isRecorded) {
    $t = substr($r['time'], 0, 5);
    return ($timeFrom === '' || $t >= $timeFrom)
        && ($timeTo === '' || $t <= $timeTo)
        && ($empId === 0 || (int)$r['employee_id'] === $empId)
        && ($camId === 0 || (int)$r['camera_id'] === $camId)
        && ($what === '' || ($what === 'recorded') === $isRecorded($r));
}));

if (($_GET['export'] ?? '') === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="camera-log-' . $from . ($to !== $from ? '-to-' . $to : '') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Date', 'Time', 'Employee code', 'Employee', 'Camera', 'Match', 'Result']);
    foreach ($rows as $r) {
        fputcsv($out, [$r['date'], $r['time'], $r['employee_code'], $r['employee_name'], $r['camera'], $r['score'], $r['result']]);
    }
    fclose($out);
    exit;
}

$shown = array_slice($rows, 0, 500);
// Which shown matches are the day's check-in / check-out, and every sighting of
// the period (not only the filtered ones): the popup suggests the next time.
$attRoles = $canSeePhotos ? hr_camera_log_attendance_roles($conn, $shown) : [];
$sightings = $canSeePhotos ? hr_camera_log_sightings($all) : [];
$attTimes = [];
if ($attRoles) {
    $st = $conn->prepare('SELECT check_in, check_out FROM attendance WHERE id = ?');
    foreach ($attRoles as $ro) {
        if (!isset($attTimes[$ro['att_id']])) {
            $st->execute([$ro['att_id']]);
            $t = $st->fetch(PDO::FETCH_ASSOC) ?: [];
            $attTimes[$ro['att_id']] = ['in' => $t['check_in'] ? substr((string)$t['check_in'], 0, 5) : '', 'out' => $t['check_out'] ? substr((string)$t['check_out'], 0, 5) : ''];
        }
    }
}
$query = $_GET;
unset($query['export']);

$pageTitle = 'Camera log';
require_once __DIR__ . '/includes/hr_layout_header.php';

echo hr_ui_page_header(
    'Camera log',
    'Every face the cameras matched, with its photo — also the ones that did not change the day. Kept ' . HR_CAMERA_LOG_DAYS . ' days.',
    [
        ['label' => 'HR', 'href' => $hrBase . '/dashboard'],
        ['label' => 'Attendance', 'href' => $hrBase . '/attendance'],
        ['label' => 'Camera log'],
    ],
    '<a class="btn btn-outline-secondary" href="attendance_cameras.php"><i class="bi bi-camera-video me-1"></i>Cameras</a>'
    . '<a class="btn btn-outline-success" href="?' . h(http_build_query($query + ['export' => 'csv'])) . '"><i class="bi bi-filetype-csv me-1"></i>Download CSV</a>'
);
?>

<form method="get" class="card card-body mb-3">
  <div class="row g-2 align-items-end">
    <div class="col-6 col-md-2">
      <label class="form-label small mb-1" for="fFrom">From</label>
      <input type="date" class="form-control form-control-sm" id="fFrom" name="date_from" value="<?= h($from) ?>" max="<?= h($today) ?>">
    </div>
    <div class="col-6 col-md-2">
      <label class="form-label small mb-1" for="fTo">To</label>
      <input type="date" class="form-control form-control-sm" id="fTo" name="date_to" value="<?= h($to) ?>" max="<?= h($today) ?>">
    </div>
    <div class="col-6 col-md-1">
      <label class="form-label small mb-1" for="fTf">Time from</label>
      <input type="time" class="form-control form-control-sm" id="fTf" name="time_from" value="<?= h($timeFrom) ?>">
    </div>
    <div class="col-6 col-md-1">
      <label class="form-label small mb-1" for="fTt">Time to</label>
      <input type="time" class="form-control form-control-sm" id="fTt" name="time_to" value="<?= h($timeTo) ?>">
    </div>
    <div class="col-12 col-md-2">
      <label class="form-label small mb-1" for="fEmp">Employee</label>
      <select class="form-select form-select-sm" id="fEmp" name="employee_id">
        <option value="0">All employees</option>
        <?php foreach ($employees as $id => $label): ?>
          <option value="<?= (int)$id ?>" <?= $id === $empId ? 'selected' : '' ?>><?= h($label) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-6 col-md-2">
      <label class="form-label small mb-1" for="fCam">Camera</label>
      <select class="form-select form-select-sm" id="fCam" name="camera_id">
        <option value="0">All cameras</option>
        <?php foreach ($cameraNames as $id => $label): ?>
          <option value="<?= (int)$id ?>" <?= $id === $camId ? 'selected' : '' ?>><?= h($label) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-6 col-md-2">
      <label class="form-label small mb-1" for="fWhat">Result</label>
      <select class="form-select form-select-sm" id="fWhat" name="what">
        <option value="">All matches</option>
        <option value="recorded" <?= $what === 'recorded' ? 'selected' : '' ?>>Recorded a check-in / out</option>
        <option value="not_recorded" <?= $what === 'not_recorded' ? 'selected' : '' ?>>No change / not recorded</option>
      </select>
    </div>
  </div>
  <div class="d-flex gap-2 mt-2">
    <button class="btn btn-sm btn-primary"><i class="bi bi-funnel me-1"></i>Show</button>
    <a class="btn btn-sm btn-outline-secondary" href="attendance_camera_log.php">Today</a>
  </div>
</form>

<?php if ($logFlash): ?><div class="alert alert-<?= h($logFlash[0]) ?>"><?= h($logFlash[1]) ?></div><?php endif; ?>

<form method="post" id="camLogPick">
<?php csrf_field(); ?>
<input type="hidden" name="back" value="<?= h(http_build_query($query)) ?>">
<div class="card">
  <div class="card-header bg-white small text-muted d-flex flex-wrap align-items-center gap-2">
    <span>
      <?= count($rows) ?> match<?= count($rows) === 1 ? '' : 'es' ?>
      <?= count($rows) > count($shown) ? '· showing the newest ' . count($shown) . ' (narrow the filters, or download the CSV for all)' : '' ?>
    </span>
    <?php if ($canSeePhotos): ?>
      <span class="ms-auto">With the ticked photos (<span id="camLogPicked">0</span>):</span>
      <button name="action" value="reference" class="btn btn-sm btn-outline-success" disabled
              onclick="return confirm('Use the ticked photos as reference photos? The cameras will also recognise each person from them.');">
        <i class="bi bi-check2-circle me-1"></i>Reference</button>
      <button name="action" value="reject" class="btn btn-sm btn-outline-danger" disabled onclick="return camLogReject();">
        <i class="bi bi-person-x me-1"></i>Different person</button>
    <?php endif; ?>
  </div>
  <div class="table-responsive">
    <table class="table table-sm table-hover align-middle mb-0">
      <thead class="table-light">
        <tr><?php if ($canSeePhotos): ?><th style="width:32px"><input type="checkbox" class="form-check-input" id="camLogAll" title="Tick all shown"></th><?php endif; ?><th style="width:72px">Photo</th><th>Date</th><th>Time</th><th>Employee</th><th>Camera</th><th class="text-end">Match</th><th>Result</th></tr>
      </thead>
      <tbody>
        <?php if (!$shown): ?>
          <tr><td colspan="8" class="text-center text-muted py-4">No camera matches for these filters.</td></tr>
        <?php endif; ?>
        <?php foreach ($shown as $r):
          $url = ($canSeePhotos && $r['photo'] !== '') ? '?' . http_build_query(['photo' => $r['photo'], 'day' => $r['date']]) : '';
          $rec = $isRecorded($r);
          $err = strpos($r['result'], 'Error') === 0;
        ?>
          <tr>
            <?php if ($canSeePhotos): ?>
              <td><?php if ($url !== ''):
                $ro = $attRoles[$r['date'] . '|' . $r['photo']] ?? null; ?>
                <input type="checkbox" class="form-check-input cam-log-tick" name="pick[]" value="<?= h($r['date'] . '|' . $r['photo']) ?>" aria-label="Tick this photo"
                  <?php if ($ro): ?>data-role="<?= h($ro['role']) ?>" data-att="<?= (int)$ro['att_id'] ?>" data-status="<?= h($ro['status']) ?>"
                  data-time="<?= h($ro['time']) ?>" data-day="<?= h((int)$r['employee_id'] . '|' . $r['date']) ?>"
                  data-in="<?= h($attTimes[$ro['att_id']]['in']) ?>" data-out="<?= h($attTimes[$ro['att_id']]['out']) ?>"
                  data-who="<?= h($r['employee_name'] . ' (' . $r['employee_code'] . ') · ' . date('j M Y', strtotime($r['date']))) ?>"<?php endif; ?>>
              <?php endif; ?></td>
            <?php endif; ?>
            <td>
              <?php if ($url !== ''): ?>
                <img src="<?= h($url) ?>" alt="Match photo" loading="lazy" class="rounded border cam-log-pic" role="button"
                     data-big="<?= h($url) ?>" data-caption="<?= h($r['employee_name'] . ' · ' . date('j M Y', strtotime($r['date'])) . ' ' . $r['time'] . ' · ' . $r['camera'] . ($r['score'] !== '' ? ' · match ' . $r['score'] : '')) ?>"
                     style="width:56px;height:56px;object-fit:cover">
              <?php else: ?>
                <span class="text-muted small">—</span>
              <?php endif; ?>
            </td>
            <td class="text-nowrap"><?= h(date('j M Y', strtotime($r['date']))) ?></td>
            <td class="text-nowrap"><?= h($r['time']) ?></td>
            <td><?= h($r['employee_name']) ?> <span class="text-muted small"><?= h($r['employee_code']) ?></span></td>
            <td><?= h($r['camera'] !== '' ? $r['camera'] : '—') ?></td>
            <td class="text-end"><?= h($r['score'] !== '' ? $r['score'] : '—') ?></td>
            <td><span class="badge text-bg-<?= $rec ? 'success' : ($err ? 'danger' : 'light border') ?> text-wrap text-start"><?= h($r['result']) ?></span></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
</form>

<div class="modal fade" id="camLogModal" tabindex="-1" aria-labelledby="camLogCaption" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title fs-6" id="camLogCaption"></h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body text-center"><img id="camLogBig" src="" alt="Match photo" class="img-fluid rounded"></div>
    </div>
  </div>
</div>
<!-- "Different person" on the day's check-in / check-out: change that time as well? -->
<div class="modal fade" id="camLogAtt" tabindex="-1" aria-labelledby="camLogAttTitle" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title fs-6" id="camLogAttTitle"><i class="bi bi-person-x me-1"></i>Different person</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body" id="camLogAttBody"></div>
      <div class="modal-footer">
        <span class="small text-muted me-auto">The ticked matches are taken off the log.</span>
        <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="button" class="btn btn-sm btn-danger" id="camLogAttGo">Confirm</button>
      </div>
    </div>
  </div>
</div>
<script>
// Every camera sighting per person per day, for the time the popup suggests.
var camLogSightings = <?= json_encode($sightings, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
function camLogReject() {
  var form = document.getElementById('camLogPick');
  var ticked = Array.prototype.slice.call(document.querySelectorAll('.cam-log-tick:checked'));
  var pickedKeys = {};
  ticked.forEach(function (t) { pickedKeys[t.value] = true; });
  var items = ticked.filter(function (t) { return t.dataset.role; });
  if (!items.length) {
    return confirm('Different person? The cameras will stop matching faces like these to the employee shown on each row, and the matches are taken off the log.');
  }
  var esc = function (s) { var d = document.createElement('div'); d.textContent = s; return d.innerHTML; };
  var html = '';
  items.forEach(function (t, i) {
    var role = t.dataset.role, name = role === 'in' ? 'check-in' : 'last check-out';
    html += '<div class="border rounded p-2 mb-2"><div class="fw-semibold">' + esc(t.dataset.who) + '</div>' +
      '<div class="mb-2">' + esc(t.dataset.time) + ' is marked as the <strong>' + name.toUpperCase() + '</strong> time.</div>';
    if (t.dataset.status !== 'pending') {
      html += '<div class="small text-muted">This day is ' + esc(t.dataset.status) + ': change it on the Attendance page.</div></div>';
      return;
    }
    // Suggest the next sighting for a check-in, the one before for a check-out — not a ticked one, and not the other end of the day.
    var left = (camLogSightings[t.dataset.day] || []).filter(function (s) {
      return !pickedKeys[s.key] && (role === 'in' ? (!t.dataset.out || s.time < t.dataset.out) : (!t.dataset.in || s.time > t.dataset.in));
    });
    var hint = left.length ? (role === 'in' ? left[0].time : left[left.length - 1].time) : '';
    var f = 'att[' + t.dataset.att + '][' + role + ']';
    html += '<div class="small mb-1">Do you want to change the ' + name + ' as well?</div>' +
      '<div class="form-check"><input class="form-check-input" type="radio" name="' + f + '" value="change" id="ca' + i + 'c"' + (hint ? ' checked' : '') + '>' +
      '<label class="form-check-label" for="ca' + i + 'c">Change ' + name + ' to</label> ' +
      '<input type="time" class="form-control form-control-sm d-inline-block ms-1" style="width:120px" name="' + f.slice(0, -1) + '_time]" value="' + esc(hint) + '">' +
      (hint ? ' <span class="small text-muted">' + (role === 'in' ? 'next' : 'previous') + ' camera sighting</span>' : '') + '</div>' +
      '<div class="form-check"><input class="form-check-input" type="radio" name="' + f + '" value="remove" id="ca' + i + 'r">' +
      '<label class="form-check-label" for="ca' + i + 'r">Remove the ' + name + '</label></div>' +
      '<div class="form-check"><input class="form-check-input" type="radio" name="' + f + '" value="keep" id="ca' + i + 'k"' + (hint ? '' : ' checked') + '>' +
      '<label class="form-check-label" for="ca' + i + 'k">Keep ' + esc(t.dataset.time) + '</label>' +
      (hint ? '' : ' <span class="small text-muted">(no other camera sighting that day)</span>') + '</div></div>';
  });
  document.getElementById('camLogAttBody').innerHTML = html;
  bootstrap.Modal.getOrCreateInstance(document.getElementById('camLogAtt')).show();
  return false;
}
document.getElementById('camLogAttGo') && document.getElementById('camLogAttGo').addEventListener('click', function () {
  var form = document.getElementById('camLogPick');
  form.querySelectorAll('.cam-log-att-sent').forEach(function (n) { n.remove(); });
  var add = function (name, value) {
    var i = document.createElement('input');
    i.type = 'hidden'; i.name = name; i.value = value; i.className = 'cam-log-att-sent';
    form.appendChild(i);
  };
  add('action', 'reject');
  document.querySelectorAll('#camLogAttBody input').forEach(function (inp) {
    if ((inp.type === 'radio' && inp.checked) || inp.type === 'time') add(inp.name, inp.value);
  });
  form.submit();
});
(function () {
  var ticks = document.querySelectorAll('.cam-log-tick'), all = document.getElementById('camLogAll');
  function refresh() {
    var n = document.querySelectorAll('.cam-log-tick:checked').length;
    var c = document.getElementById('camLogPicked');
    if (c) c.textContent = n;
    document.querySelectorAll('#camLogPick button[name=action]').forEach(function (b) { b.disabled = n === 0; });
    if (all) all.checked = n > 0 && n === ticks.length;
  }
  ticks.forEach(function (t) { t.addEventListener('change', refresh); });
  if (all) all.addEventListener('change', function () { ticks.forEach(function (t) { t.checked = all.checked; }); refresh(); });
}());
document.querySelectorAll('.cam-log-pic').forEach(function (pic) {
  pic.addEventListener('click', function () {
    document.getElementById('camLogBig').src = pic.dataset.big;
    document.getElementById('camLogCaption').textContent = pic.dataset.caption;
    bootstrap.Modal.getOrCreateInstance(document.getElementById('camLogModal')).show();
  });
});
</script>

<?php require_once __DIR__ . '/includes/hr_layout_footer.php'; ?>
