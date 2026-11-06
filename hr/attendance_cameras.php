<?php
/**
 * Attendance cameras — the rooms the camera PC watches.
 *
 * HR adds each camera with its address and verification code, switches it off
 * while it is being moved, and changes the code after a camera is reset. The
 * camera PC reads this list every few minutes (api/attendance_camera.php
 * ?action=cameras), so nothing has to be edited on the PC itself.
 *
 * The verification code is never printed back on the page: the field is left
 * empty and an empty field keeps the code already saved.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/../includes/audit_bridge.php';
require_once __DIR__ . '/includes/hr_attendance_cameras.php';
require_once __DIR__ . '/includes/hr_company_scope.php';
require_once __DIR__ . '/includes/hr_employee_lifecycle.php';
require_role(['Owner', 'Admin', 'HR'], $conn);

if (!function_exists('h')) {
    function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
}

$ready = hr_cameras_ready($conn);
// The Live view shows staff on camera, so the HR role only, like the camera photos.
$canLive = has_role('HR', $conn);
$flash_ok = $flash_err = '';
$userId = function_exists('current_user_id') ? (current_user_id() ?: null) : null;

if ($ready && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    csrf_verify();
    $action = (string)($_POST['_action'] ?? '');
    $id = (int)($_POST['id'] ?? 0);

    if ($action === 'save') {
        $name = trim((string)($_POST['name'] ?? ''));
        $ip   = trim((string)($_POST['ip_address'] ?? ''));
        $code = trim((string)($_POST['verification_code'] ?? ''));
        // Empty = let the camera PC fill it in from the network (e.g. C8c_BH5929142_EZVIZ).
        $device = trim((string)($_POST['device_name'] ?? ''));
        $device = $device !== '' ? mb_substr($device, 0, 100) : null;
        $port = (int)($_POST['rtsp_port'] ?? 554) ?: 554;
        $path = '/' . ltrim(trim((string)($_POST['stream_path'] ?? '')) ?: '/H.264', '/');
        $active = !empty($_POST['is_active']) ? 1 : 0;

        if ($name === '' || $ip === '') {
            $flash_err = 'Room / name and IP address are required.';
        } elseif (!hr_camera_valid_host($ip)) {
            $flash_err = 'The IP address or name can only contain numbers, letters, dots, hyphens and underscores.';
        } elseif ($port < 1 || $port > 65535) {
            $flash_err = 'The port must be between 1 and 65535.';
        } elseif (!preg_match('#^/[A-Za-z0-9._/\-]{0,99}$#', $path)) {
            $flash_err = 'The stream path can only contain letters, numbers, dots, hyphens and slashes.';
        } elseif ($id === 0 && $code === '') {
            $flash_err = 'Enter the verification code from the camera\'s sticker.';
        } else {
            if ($id > 0) {
                $sql = "UPDATE hr_attendance_cameras
                           SET name = ?, ip_address = ?, rtsp_port = ?, stream_path = ?, device_name = ?, is_active = ?, updated_by = ?"
                     . ($code !== '' ? ", verification_code = ?" : "")
                     . " WHERE id = ?";
                $prms = [$name, $ip, $port, $path, $device, $active, $userId];
                if ($code !== '') $prms[] = $code;
                $prms[] = $id;
                $conn->prepare($sql)->execute($prms);
                $flash_ok = 'Saved ' . $name . ($code !== '' ? ' with the new verification code' : '') . '. The camera PC picks it up within 15 seconds.';
            } else {
                $conn->prepare(
                    "INSERT INTO hr_attendance_cameras (name, ip_address, rtsp_port, stream_path, verification_code, device_name, is_active, updated_by)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
                )->execute([$name, $ip, $port, $path, $code, $device, $active, $userId]);
                $id = (int)$conn->lastInsertId();
                $flash_ok = 'Added ' . $name . '. The camera PC picks it up within 15 seconds.';
            }
            // The code itself never goes into the log.
            audit_bridge_hr_ops('attendance_camera_saved', 'hr_attendance_camera', $id,
                'Camera ' . $name . ' (' . $ip . ') saved' . ($code !== '' ? ', verification code changed' : ''),
                null, ['name' => $name, 'ip_address' => $ip, 'rtsp_port' => $port, 'stream_path' => $path, 'device_name' => $device, 'is_active' => $active,
                       'code_changed' => $code !== ''], $name, $userId);
        }
    } elseif ($action === 'frequency') {
        $old = hr_camera_resend_seconds($conn);
        $unit = ($_POST['unit'] ?? '') === 'hour' ? 'hour' : 'minute';
        $new = hr_camera_resend_save($conn, $_POST['every'] ?? '', $unit);
        if ($new === null) {
            $flash_err = 'Enter between 1 minute and 24 hours.';
        } else {
            audit_bridge_hr_ops('attendance_camera_frequency', 'hr_attendance_camera', 0,
                'Camera records the same person every ' . hr_camera_resend_label($new) . ' (was ' . hr_camera_resend_label($old) . ')',
                null, ['resend_seconds' => $new, 'was' => $old], 'Cameras', $userId);
            $flash_ok = 'Saved: the same person is recorded at most every ' . hr_camera_resend_label($new) . '. The camera PC picks it up within 15 seconds.';
        }
    } elseif ($action === 'in_use' && $id > 0) {
        $on = !empty($_POST['in_use']) ? 1 : 0;
        $st = $conn->prepare("SELECT name FROM hr_attendance_cameras WHERE id = ?");
        $st->execute([$id]);
        $name = (string)$st->fetchColumn();
        if ($name !== '') {
            $conn->prepare("UPDATE hr_attendance_cameras SET is_active = ?, updated_by = ? WHERE id = ?")->execute([$on, $userId, $id]);
            audit_bridge_hr_ops('attendance_camera_saved', 'hr_attendance_camera', $id,
                'Camera ' . $name . ($on ? ' in use' : ' not in use'), null, ['is_active' => $on], $name, $userId);
            $flash_ok = $on
                ? $name . ' is in use. The camera PC starts reading it within 15 seconds.'
                : $name . ' is not in use. The camera PC stops reading it within 15 seconds.';
        }
    } elseif ($action === 'delete' && $id > 0) {
        $st = $conn->prepare("SELECT name FROM hr_attendance_cameras WHERE id = ?");
        $st->execute([$id]);
        $name = (string)$st->fetchColumn();
        $conn->prepare("DELETE FROM hr_attendance_cameras WHERE id = ?")->execute([$id]);
        audit_bridge_hr_ops('attendance_camera_deleted', 'hr_attendance_camera', $id, 'Camera ' . $name . ' removed', null, null, $name, $userId);
        $flash_ok = 'Removed ' . $name . '.';
    } elseif ($action === 'people' && hr_camera_people_ready($conn)) {
        // Only the rows that were on screen are changed: a filtered list must
        // not untick everyone it did not show.
        $shown  = array_map('intval', (array)($_POST['shown'] ?? []));
        $ticked = array_flip(array_map('intval', (array)($_POST['camera'] ?? [])));
        $before = array_flip(array_map('intval', $conn->query("SELECT employee_id FROM hr_attendance_camera_people")->fetchAll(PDO::FETCH_COLUMN)));
        $add = $conn->prepare("INSERT IGNORE INTO hr_attendance_camera_people (employee_id, created_by) VALUES (?, ?)");
        $del = $conn->prepare("DELETE FROM hr_attendance_camera_people WHERE employee_id = ?");
        $on = $off = 0;
        foreach ($shown as $empId) {
            if ($empId <= 0) continue;
            if (isset($ticked[$empId]) && !isset($before[$empId])) { $add->execute([$empId, $userId]); $on++; }
            if (!isset($ticked[$empId]) && isset($before[$empId])) { $del->execute([$empId]); $off++; }
        }
        if ($on || $off) {
            audit_bridge_hr_ops('attendance_camera_people', 'hr_attendance_camera', 0,
                'Camera attendance: ' . $on . ' added, ' . $off . ' moved back to the staff app',
                null, ['added' => $on, 'removed' => $off], 'Cameras', $userId);
        }
        $flash_ok = ($on || $off)
            ? 'Saved: ' . $on . ' now recorded by camera, ' . $off . ' back on the staff app.'
            : 'Nothing changed.';
    }
}

$cameras = hr_cameras_all($conn);
$editId = (int)($_GET['edit'] ?? 0);

/* ---------- People: who the cameras record ---------- */
$peopleReady = hr_camera_people_ready($conn);
$companies = hr_active_companies($conn);
$selectedCompanyId = hr_selected_company_id($conn, $companies);
$people = [];
if ($peopleReady) {
    $where = ["e.status IN (" . hr_employee_status_in_sql(hr_employee_current_statuses()) . ")"];
    $prms  = hr_employee_current_statuses();
    hr_add_company_where($where, $prms, $selectedCompanyId, 'e.company_id');
    $st = $conn->prepare("
        SELECT e.id, e.full_name, e.employee_code, c.name AS company_name,
               (p.employee_id IS NOT NULL) AS on_camera
          FROM employees e
          LEFT JOIN companies c ON c.id = e.company_id
          LEFT JOIN hr_attendance_camera_people p ON p.employee_id = e.id
         WHERE " . implode(' AND ', $where) . "
         ORDER BY c.name, e.full_name");
    $st->execute($prms);
    $people = $st->fetchAll(PDO::FETCH_ASSOC);
    // Which face photo the camera uses for each: uploaded here, the profile photo, or none.
    foreach ($people as &$p) {
        $face = hr_camera_face($conn, (int)$p['id']);
        $p['face'] = $face['source'] ?? null;
        $p['face_v'] = $face ? (int)filemtime($face['path']) : 0;
        // Clear camera matches kept beside it: [[name, score], ...], closest first.
        $p['learned'] = array_map(static fn($l) => [$l['name'], $l['score']], hr_camera_learned((int)$p['id']));
        // The closest any camera has seen them: [match, when (Dubai)], or null.
        $best = hr_camera_best((int)$p['id']);
        $p['rejected'] = array_column(hr_camera_rejected((int)$p['id']), 'name');
        $p['extra'] = array_column(hr_camera_extra((int)$p['id']), 'name');
        $p['best'] = $best ? [$best['score'], (new DateTimeImmutable('@' . $best['at']))->setTimezone(new DateTimeZone('Asia/Dubai'))->format('j M Y, g:i A'), $best['used']] : null;
    }
    unset($p);

    // Last detected, for the ticked: saved with every recognition; before that
    // was saved, the latest camera time on their attendance stands in.
    $detected = [];
    try {
        $detected = $conn->query("SELECT p.employee_id, p.last_detected_at AS at, c.name AS camera
                                    FROM hr_attendance_camera_people p LEFT JOIN hr_attendance_cameras c ON c.id = p.last_camera_id
                                   WHERE p.last_detected_at IS NOT NULL")->fetchAll(PDO::FETCH_UNIQUE);
    } catch (PDOException $e) {
        // Columns not added yet: the attendance fallback below covers it.
    }
    $fallback = $conn->query("
        SELECT a.employee_id, MAX(CONCAT(a.work_date, ' ', COALESCE(a.check_out, a.check_in))) AS at
          FROM attendance a JOIN hr_attendance_camera_people p ON p.employee_id = a.employee_id
         WHERE a.notes LIKE 'Camera %' AND a.check_in IS NOT NULL
         GROUP BY a.employee_id")->fetchAll(PDO::FETCH_KEY_PAIR);
    foreach ($people as &$p) {
        $d = $detected[$p['id']] ?? null;
        $p['detected_at'] = $d['at'] ?? ($fallback[$p['id']] ?? null);
        $p['detected_cam'] = $d['camera'] ?? null;
    }
    unset($p);
}
$faceFlash = $_SESSION['cam_face_flash'] ?? null;
unset($_SESSION['cam_face_flash']);
$onCameraCount = count(array_filter($people, static fn($p) => (int)$p['on_camera']));

$pageTitle = 'Cameras';
require_once __DIR__ . '/includes/hr_layout_header.php';

echo hr_ui_page_header(
    'Cameras',
    'The cameras that record attendance by face. The camera PC picks up changes here within 15 seconds.',
    [
        ['label' => 'HR', 'href' => $hrBase . '/dashboard'],
        ['label' => 'Cameras'],
    ],
    '<a class="btn btn-outline-secondary" href="attendance.php?source=camera">Camera attendance</a>'
    . '<a class="btn btn-outline-secondary" href="attendance_camera_log.php"><i class="bi bi-list-ul me-1"></i>Camera log</a>'
);
?>

<?php if (!$ready): ?>
  <div class="alert alert-warning">
    Cameras are not set up on this server yet: run <code>database/fixes/2026_11_02_hr_attendance_cameras.sql</code>.
  </div>
<?php else: ?>

  <?php if ($flash_ok): ?><div class="alert alert-success"><?= h($flash_ok) ?></div><?php endif; ?>
  <?php if ($flash_err): ?><div class="alert alert-danger"><?= h($flash_err) ?></div><?php endif; ?>
  <?php if ($faceFlash): ?><div class="alert alert-<?= $faceFlash[0] === 'ok' ? 'success' : 'danger' ?>"><?= h($faceFlash[1]) ?></div><?php endif; ?>

  <!-- Two tabs: the cameras themselves, and the employees they record. #people in the address opens Employees
       (saving there, and the face photo popup, come back to it). -->
  <ul class="nav nav-tabs mb-3" id="camTabs">
    <li class="nav-item"><a class="nav-link" href="#cameras" data-cam-tab="cameras"><i class="bi bi-camera-video"></i> Cameras</a></li>
    <li class="nav-item"><a class="nav-link" href="#people" data-cam-tab="people"><i class="bi bi-people"></i> Employees
      <span class="badge rounded-pill text-bg-light border"><?= (int)$onCameraCount ?></span></a></li>
  </ul>

  <div id="camTabCameras">

  <div class="hr-settings-card mb-3">
    <div class="card-body p-0">
      <table class="table align-middle mb-0">
        <thead class="table-light">
          <tr>
            <th style="width:80px">In use</th>
            <th>Room / name</th>
            <th>Device</th>
            <th>IP address</th>
            <th>Wi-Fi</th>
            <th>Verification code</th>
            <th>Status</th>
            <th>Last changed</th>
            <th class="text-end">Actions</th>
          </tr>
        </thead>
        <tbody>
        <?php if (!$cameras): ?>
          <tr><td colspan="9" class="text-muted text-center py-4">No cameras yet. Add the first one below.</td></tr>
        <?php endif; ?>
        <?php foreach ($cameras as $c): ?>
          <?php [$stLabel, $stClass, $stDetail] = hr_camera_status($c); ?>
          <tr class="<?= (int)$c['is_active'] ? '' : 'text-muted' ?>">
            <td>
              <!-- Only cameras in use are read by the camera PC; switching saves at once. -->
              <form method="post" class="m-0">
                <?php csrf_field(); ?>
                <input type="hidden" name="_action" value="in_use">
                <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
                <input type="hidden" name="in_use" value="<?= (int)$c['is_active'] ? 0 : 1 ?>">
                <div class="form-check form-switch m-0">
                  <input class="form-check-input" type="checkbox" role="switch" onchange="this.form.submit()"
                         aria-label="In use: <?= h($c['name']) ?>" <?= (int)$c['is_active'] ? 'checked' : '' ?>>
                </div>
              </form>
            </td>
            <td class="fw-semibold"><?= h($c['name']) ?></td>
            <td>
              <?php if (!empty($c['device_name'])): ?>
                <span title="<?= h($c['device_name']) ?>"><?= h(hr_camera_device_label($c['device_name'])) ?></span>
              <?php else: ?>
                <span class="text-muted small">Not reported yet</span>
              <?php endif; ?>
            </td>
            <td>
              <code><?= h($c['ip_address']) ?></code><?= (int)$c['rtsp_port'] !== 554 ? ':' . (int)$c['rtsp_port'] : '' ?>
              <?php if (!empty($c['current_ip']) && $c['current_ip'] !== $c['ip_address']): ?>
                <div class="small text-muted">Now at <?= h($c['current_ip']) ?></div>
              <?php endif; ?>
            </td>
            <td>
              <?php if (!empty($c['network_name'])): ?>
                <?= h($c['network_name']) ?>
                <?php if (!empty($c['network_band'])): ?><div class="small text-muted"><?= h($c['network_band']) ?></div><?php endif; ?>
              <?php else: ?>
                <span class="text-muted small">Not reported yet</span>
              <?php endif; ?>
            </td>
            <td><span class="text-muted">Saved ••••••</span></td>
            <td>
              <span class="badge <?= h($stClass) ?>"><?= h($stLabel) ?></span>
              <div class="small text-muted"><?= h($stDetail) ?></div>
            </td>
            <td class="small text-muted"><?= h(date('j M Y, g:i A', strtotime((string)$c['updated_at']))) ?></td>
            <td class="text-end text-nowrap">
              <?php if ($canLive && (int)$c['is_active']): ?>
                <button type="button" class="btn btn-sm btn-outline-success cam-live-btn" data-id="<?= (int)$c['id'] ?>"
                        aria-expanded="false"><i class="bi bi-play-fill"></i> Live</button>
              <?php endif; ?>
              <a class="btn btn-sm btn-outline-primary" href="attendance_cameras.php?edit=<?= (int)$c['id'] ?>#cameraForm">Edit</a>
              <form method="post" class="d-inline" onsubmit="return confirm('Remove <?= h(addslashes($c['name'])) ?>? The camera PC stops watching it.')">
                <?php csrf_field(); ?>
                <input type="hidden" name="_action" value="delete">
                <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
                <button class="btn btn-sm btn-outline-danger">Remove</button>
              </form>
            </td>
          </tr>
          <?php if ($canLive && (int)$c['is_active']): ?>
          <tr class="d-none" id="camLive<?= (int)$c['id'] ?>">
            <td colspan="9" class="bg-light text-center">
              <img class="cam-live-img img-fluid rounded d-none" style="max-height:420px" alt="Live: <?= h($c['name']) ?>">
              <div class="cam-live-msg small text-muted py-3">Connecting to the camera… this takes a few seconds.</div>
              <div class="cam-live-time small text-muted"></div>
            </td>
          </tr>
          <?php endif; ?>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
  <?php if ($canLive): ?>
  <script>
  // Live: ask for the camera's latest frame every 2 s while the box is open. Each
  // ask also tells the camera PC to keep sending; closing the box stops it.
  (function () {
    // Live stops by itself after 5 minutes (a forgotten tab would keep pictures
    // coming all day) and pauses while the tab is not being looked at.
    var LIVE_MS = 5 * 60 * 1000;
    var timers = {}, started = {};
    function run(id, row) {
      clearInterval(timers[id]);
      timers[id] = setInterval(function () { load(id, row); }, 2000);
      load(id, row);
    }
    function load(id, row) {
      if (Date.now() - started[id] > LIVE_MS) {
        clearInterval(timers[id]);
        timers[id] = 'ended';
        var msg = row.querySelector('.cam-live-msg');
        msg.innerHTML = 'Live stopped after 5 minutes to save internet. Click Continue to keep watching. <button type="button" class="btn btn-sm btn-outline-success ms-1 cam-live-more">Continue</button>';
        msg.classList.remove('d-none');
        row.querySelector('.cam-live-img').classList.add('d-none');
        msg.querySelector('.cam-live-more').addEventListener('click', function () { started[id] = Date.now(); msg.textContent = 'Connecting to the camera… this takes a few seconds.'; run(id, row); });
        return;
      }
      fetch('attendance_camera_live.php?id=' + id + '&t=' + Date.now(), {credentials: 'same-origin', cache: 'no-store'})
        .then(function (r) {
          if (r.status !== 200) return null;
          var at = r.headers.get('X-Frame-Time');
          return r.blob().then(function (b) { return {blob: b, at: at}; });
        })
        .then(function (f) {
          if (!timers[id] || timers[id] === 'ended') return;
          var img = row.querySelector('.cam-live-img'), msg = row.querySelector('.cam-live-msg');
          if (!f) {
            // Still nothing after 20 seconds, or the picture stopped coming: say what to check.
            msg.textContent = (img.classList.contains('d-none') && Date.now() - started[id] < 20000)
              ? 'Connecting to the camera… this takes a few seconds.'
              : 'No picture yet. Click Stop, then Live to try again. If it still does not show, check that the camera program is running on the office computer.';
            msg.classList.remove('d-none');
            return;
          }
          var old = img.src;
          img.src = URL.createObjectURL(f.blob);
          if (old) URL.revokeObjectURL(old);
          img.classList.remove('d-none');
          msg.classList.add('d-none');
          row.querySelector('.cam-live-time').textContent = 'Picture from ' + (f.at || '') + ' · refreshes every 2 seconds';
        })
        .catch(function () {});
    }
    document.querySelectorAll('.cam-live-btn').forEach(function (btn) {
      btn.addEventListener('click', function () {
        var id = btn.dataset.id, row = document.getElementById('camLive' + id);
        if (timers[id]) {
          clearInterval(timers[id]);
          delete timers[id];
          row.classList.add('d-none');
          btn.innerHTML = '<i class="bi bi-play-fill"></i> Live';
          btn.setAttribute('aria-expanded', 'false');
          return;
        }
        row.classList.remove('d-none');
        btn.innerHTML = '<i class="bi bi-stop-fill"></i> Stop';
        btn.setAttribute('aria-expanded', 'true');
        started[id] = Date.now();
        run(id, row);
      });
    });
    // Another tab or a minimised window: no pictures until it is looked at again.
    document.addEventListener('visibilitychange', function () {
      Object.keys(timers).forEach(function (id) {
        if (timers[id] === 'ended') return;
        if (document.hidden) { clearInterval(timers[id]); }
        else { run(id, document.getElementById('camLive' + id)); }
      });
    });
  })();
  </script>
  <?php endif; ?>

  <?php
    $edit = null;
    foreach ($cameras as $c) { if ((int)$c['id'] === $editId) { $edit = $c; break; } }
  ?>
  <div class="hr-settings-card" id="cameraForm">
    <div class="card-body">
      <h2 class="h6 mb-3"><?= $edit ? 'Edit ' . h($edit['name']) : 'Add a camera' ?></h2>
      <form method="post" action="attendance_cameras.php" class="row g-3" autocomplete="off">
        <?php csrf_field(); ?>
        <input type="hidden" name="_action" value="save">
        <input type="hidden" name="id" value="<?= $edit ? (int)$edit['id'] : 0 ?>">
        <div class="col-md-4">
          <label class="form-label">Room / name</label>
          <input type="text" name="name" class="form-control" maxlength="100" required placeholder="e.g. Main office" value="<?= h($edit['name'] ?? '') ?>">
        </div>
        <div class="col-md-4">
          <label class="form-label">IP address or network name</label>
          <input type="text" name="ip_address" class="form-control" maxlength="64" required placeholder="e.g. 192.168.70.124 or C8c_BH5929142_EZVIZ.mshome.net" value="<?= h($edit['ip_address'] ?? '') ?>">
          <div class="form-text">EZVIZ app → camera → Settings → Device Information.</div>
        </div>
        <div class="col-md-4">
          <label class="form-label">Verification code</label>
          <input type="password" name="verification_code" class="form-control" maxlength="64" autocomplete="new-password"
                 <?= $edit ? '' : 'required' ?> placeholder="<?= $edit ? 'Leave empty to keep the saved code' : '6 capital letters on the sticker' ?>">
          <div class="form-text">The 6 letters on the camera's sticker, or the password set for RTSP.</div>
        </div>
        <div class="col-md-4">
          <label class="form-label">Device name</label>
          <input type="text" name="device_name" class="form-control" maxlength="100" placeholder="Leave empty to fill in automatically" value="<?= h($edit['device_name'] ?? '') ?>">
          <div class="form-text">Model and serial, e.g. C8c_BH5929142_EZVIZ. Left empty, the camera PC fills it in.</div>
        </div>
        <div class="col-md-2">
          <label class="form-label">RTSP port</label>
          <input type="number" name="rtsp_port" class="form-control" min="1" max="65535" value="<?= (int)($edit['rtsp_port'] ?? 554) ?>">
        </div>
        <div class="col-md-2">
          <label class="form-label">Stream path</label>
          <input type="text" name="stream_path" class="form-control" maxlength="100" value="<?= h($edit['stream_path'] ?? '/H.264') ?>">
        </div>
        <div class="col-md-2 d-flex align-items-end">
          <div class="form-check form-switch mb-2">
            <input class="form-check-input" type="checkbox" role="switch" id="camActive" name="is_active" value="1" <?= ($edit === null || (int)$edit['is_active']) ? 'checked' : '' ?>>
            <label class="form-check-label" for="camActive">In use</label>
          </div>
        </div>
        <div class="col-md-2 d-flex align-items-end justify-content-end gap-2">
          <?php if ($edit): ?><a class="btn btn-outline-secondary" href="attendance_cameras.php">Cancel</a><?php endif; ?>
          <button class="btn btn-primary"><?= $edit ? 'Save' : 'Add camera' ?></button>
        </div>
      </form>
    </div>
  </div>

  <?php
    $resend = hr_camera_resend_seconds($conn);
    $resendHours = $resend % 3600 === 0;
  ?>
  <div class="hr-settings-card mt-3" id="frequency">
    <div class="card-body">
      <h2 class="h6 mb-1">How often</h2>
      <div class="small text-muted mb-3">
        While someone stays in view, the camera records them at most this often; each time moves their check-out on.
      </div>
      <form method="post" action="attendance_cameras.php#frequency" class="d-flex flex-wrap align-items-center gap-2">
        <?php csrf_field(); ?>
        <input type="hidden" name="_action" value="frequency">
        <label for="camEvery" class="mb-0">Record the same person every</label>
        <input type="number" id="camEvery" name="every" class="form-control" style="width:90px" min="1" max="1440" required
               value="<?= $resendHours ? intdiv($resend, 3600) : intdiv($resend, 60) ?>">
        <select name="unit" class="form-select w-auto" aria-label="Unit">
          <option value="minute" <?= $resendHours ? '' : 'selected' ?>>minute(s)</option>
          <option value="hour" <?= $resendHours ? 'selected' : '' ?>>hour(s)</option>
        </select>
        <button class="btn btn-primary">Save</button>
      </form>
    </div>
  </div>

  </div>

  <div id="camTabPeople" class="d-none">
  <div class="hr-settings-card" id="people">
    <div class="card-body">
      <div class="d-flex flex-wrap align-items-end justify-content-between gap-2 mb-3">
        <div>
          <h2 class="h6 mb-1">Employees</h2>
          <div class="small text-muted">
            Ticked = attendance from the cameras. Not ticked = staff app, as before; the cameras ignore them.
          </div>
        </div>
        <?php if ($peopleReady): ?>
        <form method="get" action="attendance_cameras.php#people" class="d-flex gap-2">
          <select name="company_id" class="form-select form-select-sm w-auto" aria-label="Company" onchange="this.form.submit()">
            <option value="0">All companies</option>
            <?php foreach ($companies as $company): ?>
              <option value="<?= (int)$company['id'] ?>" <?= $selectedCompanyId === (int)$company['id'] ? 'selected' : '' ?>><?= h($company['name']) ?></option>
            <?php endforeach; ?>
          </select>
          <input type="search" id="peopleSearch" class="form-control form-control-sm" placeholder="Search name or code" aria-label="Search">
        </form>
        <?php endif; ?>
      </div>

      <?php if (!$peopleReady): ?>
        <div class="alert alert-warning mb-0">Run <code>database/fixes/2026_11_02_hr_attendance_cameras.sql</code> to choose who the cameras record.</div>
      <?php else: ?>
        <form method="post" action="attendance_cameras.php<?= $selectedCompanyId ? '?company_id=' . (int)$selectedCompanyId : '' ?>#people">
          <?php csrf_field(); ?>
          <input type="hidden" name="_action" value="people">
          <div class="table-responsive" style="max-height:60vh">
            <table class="table table-sm table-hover align-middle mb-0" id="peopleTable">
              <thead class="table-light" style="position:sticky;top:0;z-index:1">
                <tr>
                  <th style="width:90px">Camera</th>
                  <th style="width:80px">Photo</th>
                  <th>Employee</th>
                  <th>Last detected</th>
                  <th>Company</th>
                </tr>
              </thead>
              <tbody>
              <?php if (!$people): ?>
                <tr><td colspan="5" class="text-muted text-center py-4">No current employees in this company.</td></tr>
              <?php endif; ?>
              <?php foreach ($people as $p): ?>
                <tr data-search="<?= h(mb_strtolower($p['full_name'] . ' ' . $p['employee_code'])) ?>">
                  <td>
                    <input type="hidden" name="shown[]" value="<?= (int)$p['id'] ?>">
                    <input class="form-check-input cam-tick" type="checkbox" name="camera[]" value="<?= (int)$p['id'] ?>"
                           id="cam<?= (int)$p['id'] ?>" <?= (int)$p['on_camera'] ? 'checked' : '' ?>>
                  </td>
                  <td>
                    <button type="button" class="btn btn-link p-0 cam-face-btn" data-bs-toggle="modal" data-bs-target="#camFaceModal"
                            data-id="<?= (int)$p['id'] ?>" data-name="<?= h($p['full_name'] . ' (' . $p['employee_code'] . ')') ?>"
                            data-face="<?= h((string)$p['face']) ?>" data-v="<?= (int)$p['face_v'] ?>"
                            data-learned="<?= h(json_encode($p['learned'])) ?>" data-best="<?= h(json_encode($p['best'])) ?>"
                            data-rejected="<?= h(json_encode($p['rejected'])) ?>" data-extra="<?= h(json_encode($p['extra'])) ?>">
                      <?php if ($p['face']): ?>
                        <img src="attendance_camera_face.php?id=<?= (int)$p['id'] ?>&amp;v=<?= (int)$p['face_v'] ?>" alt="" loading="lazy"
                             class="rounded border" style="width:36px;height:36px;object-fit:cover"
                             title="<?= ['profile' => 'From the profile photo', 'file' => 'From the faces folder on the camera PC'][$p['face']] ?? 'Camera photo' ?>">
                      <?php else: ?>
                        <span class="small"><i class="bi bi-person-plus"></i> Add</span>
                      <?php endif; ?>
                      <?php if ($p['learned']): ?>
                        <span class="badge rounded-pill text-bg-light border align-top" title="Reference photos kept from the cameras">+<?= count($p['learned']) ?></span>
                      <?php endif; ?>
                    </button>
                  </td>
                  <td><label for="cam<?= (int)$p['id'] ?>" class="mb-0"><?= h($p['full_name']) ?> <span class="text-muted small">(<?= h($p['employee_code']) ?>)</span></label></td>
                  <td class="small">
                    <?php if (!(int)$p['on_camera']): ?>
                      <span class="text-muted">—</span>
                    <?php elseif ($p['detected_at']): ?>
                      <?php $dAt = strtotime((string)$p['detected_at']); ?>
                      <?php // Stored in Dubai time, like the attendance rows. ?>
                      <?= h(date('Y-m-d', $dAt) === (new DateTimeImmutable('now', new DateTimeZone('Asia/Dubai')))->format('Y-m-d')
                          ? 'Today ' . date('g:i A', $dAt) : date('j M, g:i A', $dAt)) ?>
                      <?php if ($p['detected_cam']): ?><div class="text-muted"><?= h($p['detected_cam']) ?></div><?php endif; ?>
                    <?php else: ?>
                      <span class="badge text-bg-warning">Never detected</span>
                    <?php endif; ?>
                  </td>
                  <td class="small"><?= h($p['company_name'] ?? '') ?></td>
                </tr>
              <?php endforeach; ?>
              </tbody>
            </table>
          </div>
          <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mt-3">
            <div class="form-check mb-0">
              <input class="form-check-input" type="checkbox" id="tickAllShown">
              <label class="form-check-label" for="tickAllShown">Tick all shown</label>
            </div>
            <div class="d-flex align-items-center gap-3">
              <span class="small text-muted"><span id="tickedCount"><?= (int)$onCameraCount ?></span> of <?= count($people) ?> on camera</span>
              <button class="btn btn-primary">Save</button>
            </div>
          </div>
        </form>

        <!-- Face photo popup: one for the page, filled from the row clicked. -->
        <div class="modal fade" id="camFaceModal" tabindex="-1" aria-hidden="true">
          <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
              <div class="modal-header">
                <h5 class="modal-title h6 mb-0">Face photo: <span id="camFaceName"></span></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
              </div>
              <div class="modal-body text-center">
                <img id="camFaceImg" src="" alt="Face photo" class="img-fluid rounded border d-none" style="max-height:320px">
                <div id="camFaceNone" class="text-muted py-4 border rounded">No photo yet.</div>
                <div id="camFaceSource" class="small text-muted mt-2"></div>
                <form method="post" action="attendance_camera_face.php" enctype="multipart/form-data" class="mt-3 text-start">
                  <?php csrf_field(); ?>
                  <input type="hidden" name="action" value="upload">
                  <input type="hidden" name="employee_id" class="camFaceId">
                  <input type="hidden" name="company_id" value="<?= (int)$selectedCompanyId ?>">
                  <label class="form-label small mb-1">Upload a clear, front-facing photo of the face</label>
                  <div class="input-group">
                    <input type="file" name="photo" class="form-control" accept="image/jpeg,image/png,image/webp" required>
                    <button class="btn btn-primary">Upload</button>
                  </div>
                </form>
                <form method="post" action="attendance_camera_face.php" id="camFaceDelete" class="mt-2 text-start"
                      onsubmit="return confirm('Delete this camera photo?');">
                  <?php csrf_field(); ?>
                  <input type="hidden" name="action" value="delete">
                  <input type="hidden" name="employee_id" class="camFaceId">
                  <input type="hidden" name="company_id" value="<?= (int)$selectedCompanyId ?>">
                  <button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i> Delete photo</button>
                </form>
                <!-- More photos of the same person (another angle, glasses...): used like the photo above. -->
                <div class="mt-3 pt-3 border-top text-start">
                  <div class="small mb-1">More photos of this employee <span class="text-muted">(another angle, with glasses, different light; up to <?= HR_CAMERA_EXTRA_KEEP ?>)</span></div>
                  <div id="camExtra" class="d-flex flex-wrap gap-2 mb-2"></div>
                  <form method="post" action="attendance_camera_face.php" enctype="multipart/form-data" class="m-0">
                    <?php csrf_field(); ?>
                    <input type="hidden" name="action" value="extra_upload">
                    <input type="hidden" name="employee_id" class="camFaceId">
                    <input type="hidden" name="company_id" value="<?= (int)$selectedCompanyId ?>">
                    <div class="input-group input-group-sm">
                      <input type="file" name="photo" class="form-control" accept="image/jpeg,image/png,image/webp" required>
                      <button class="btn btn-outline-primary"><i class="bi bi-plus-lg"></i> Add photo</button>
                    </div>
                  </form>
                </div>
                <!-- The closest any camera has seen this person, whatever the match. Shown only. -->
                <div id="camBestBox" class="mt-3 pt-3 border-top text-start d-none">
                  <div class="small mb-1">Best match so far from the cameras</div>
                  <div class="d-flex align-items-center gap-3">
                    <img id="camBestImg" src="" alt="Best camera match" class="rounded border" style="width:96px;height:96px;object-fit:cover">
                    <div class="small">
                      <div class="fw-semibold" id="camBestScore"></div>
                      <div class="text-muted" id="camBestAt"></div>
                      <div class="text-muted" id="camBestNote"></div>
                      <form method="post" action="attendance_camera_face.php" class="m-0 mt-1 d-flex gap-3">
                        <?php csrf_field(); ?>
                        <input type="hidden" name="employee_id" class="camFaceId">
                        <input type="hidden" name="company_id" value="<?= (int)$selectedCompanyId ?>">
                        <button name="action" value="best_use" id="camBestUse" class="btn btn-link p-0 text-success small"
                                onclick="return confirm('Use this photo as a reference? The cameras will also recognise this person from it.');"><i class="bi bi-check2-circle"></i> Use as reference</button>
                        <button name="action" value="best_reject" class="btn btn-link p-0 text-danger small"
                                onclick="return confirm('Different person? The cameras will stop matching this face to them.');"><i class="bi bi-person-x"></i> Different person</button>
                        <button name="action" value="best_delete" class="btn btn-link p-0 text-secondary small"
                                onclick="return confirm('Delete this best match? The next match takes its place.');"><i class="bi bi-trash"></i> Delete</button>
                      </form>
                    </div>
                  </div>
                </div>
                <!-- Reference photos: clear matches the cameras kept for this person, also compared with. -->
                <div id="camLearnedBox" class="mt-3 pt-3 border-top text-start d-none">
                  <div class="small mb-1">Reference photos from the cameras <span class="text-muted">(clear matches, also used to recognise this person)</span></div>
                  <div id="camLearned" class="d-flex flex-wrap gap-2"></div>
                  <div class="small text-muted mt-1"><i class="bi bi-person-x"></i> Different person: the cameras stop matching that face to them. <i class="bi bi-trash"></i> only removes the photo.</div>
                </div>
                <!-- Faces HR marked "Different person": the cameras skip faces like these for them. -->
                <div id="camRejectedBox" class="mt-3 pt-3 border-top text-start d-none">
                  <div class="small mb-1">Marked "Different person" <span class="text-muted">(the cameras will not match faces like these to them)</span></div>
                  <div id="camRejected" class="d-flex flex-wrap gap-2"></div>
                </div>
                <form method="post" action="attendance_camera_face.php" id="camLearnedDelete" class="d-none">
                  <?php csrf_field(); ?>
                  <input type="hidden" name="action" value="learned_delete" id="camLearnedAction">
                  <input type="hidden" name="employee_id" class="camFaceId">
                  <input type="hidden" name="company_id" value="<?= (int)$selectedCompanyId ?>">
                  <input type="hidden" name="name" id="camLearnedName">
                </form>
                <?php $takeCams = $canLive ? array_values(array_filter($cameras, function ($c) { return (int)$c['is_active']; })) : []; ?>
                <?php if ($takeCams): ?>
                <!-- Take it from a camera: the person stands in front of it while HR watches Live. -->
                <div class="mt-3 pt-3 border-top text-start">
                  <label class="form-label small mb-1" for="camTakeCam">Or take it from a camera: the person stands close, facing the camera</label>
                  <div class="input-group">
                    <select class="form-select" id="camTakeCam">
                      <?php foreach ($takeCams as $c): ?><option value="<?= (int)$c['id'] ?>"><?= h($c['name']) ?></option><?php endforeach; ?>
                    </select>
                    <button type="button" class="btn btn-outline-success" id="camTakeStart"><i class="bi bi-camera-video"></i> Start camera</button>
                  </div>
                  <div id="camTakeBox" class="d-none mt-2 text-center">
                    <!-- HR can drag a box around the person; Capture then looks only inside it. -->
                    <div id="camTakeArea" class="position-relative d-inline-block" style="touch-action:none;cursor:crosshair;user-select:none">
                      <img id="camTakeLive" alt="Live" class="img-fluid rounded border d-none" draggable="false">
                      <div id="camTakeRect" class="position-absolute d-none" style="border:2px solid #20c997;background:rgba(32,201,151,.15);pointer-events:none"></div>
                    </div>
                    <div id="camTakeHint" class="small text-muted d-none">Drag a box around the person to take only them. <a href="#" id="camTakeClear" class="d-none">Clear box</a></div>
                    <!-- The box, enlarged from the live picture: what Capture will look at (taken at full quality). -->
                    <div id="camTakeZoomWrap" class="d-none mt-2">
                      <div class="small text-muted mb-1">Inside the box</div>
                      <canvas id="camTakeZoom" class="rounded border" style="max-width:100%"></canvas>
                    </div>
                    <img id="camTakeShot" alt="Face taken" class="img-fluid rounded border d-none" style="max-height:260px">
                    <div id="camTakeMsg" class="small text-muted py-2"></div>
                    <div class="d-flex gap-2 justify-content-center">
                      <button type="button" class="btn btn-sm btn-primary" id="camTakeCapture"><i class="bi bi-camera"></i> Capture</button>
                      <button type="button" class="btn btn-sm btn-outline-secondary d-none" id="camTakeRetake">Retake</button>
                      <form method="post" action="attendance_camera_face.php" id="camTakeSave" class="d-none m-0">
                        <?php csrf_field(); ?>
                        <input type="hidden" name="action" value="capture">
                        <input type="hidden" name="employee_id" class="camFaceId">
                        <input type="hidden" name="company_id" value="<?= (int)$selectedCompanyId ?>">
                        <input type="hidden" name="camera_id" id="camTakeCamId">
                        <button class="btn btn-sm btn-success"><i class="bi bi-check-lg"></i> Save this photo</button>
                      </form>
                    </div>
                  </div>
                </div>
                <?php endif; ?>
              </div>
            </div>
          </div>
        </div>
        <?php if ($takeCams): ?>
        <script>
        // Take from camera: Live picture every 2 s; Capture asks the camera PC for a
        // full-quality face (it checks one clear, front-facing face) and shows it to save.
        (function () {
          var modal = document.getElementById('camFaceModal');
          var sel = document.getElementById('camTakeCam'), box = document.getElementById('camTakeBox');
          var live = document.getElementById('camTakeLive'), shot = document.getElementById('camTakeShot');
          var msg = document.getElementById('camTakeMsg'), capBtn = document.getElementById('camTakeCapture');
          var retake = document.getElementById('camTakeRetake'), save = document.getElementById('camTakeSave');
          var liveTimer = null, capTimer = null;
          var areaEl = document.getElementById('camTakeArea'), rect = document.getElementById('camTakeRect');
          var hint = document.getElementById('camTakeHint'), clear = document.getElementById('camTakeClear');
          var area = null, dragFrom = null; // area: [x, y, w, h] as fractions of the picture
          function at(e) {
            var r = live.getBoundingClientRect();
            return [Math.min(1, Math.max(0, (e.clientX - r.left) / r.width)), Math.min(1, Math.max(0, (e.clientY - r.top) / r.height))];
          }
          var zoomWrap = document.getElementById('camTakeZoomWrap'), zoom = document.getElementById('camTakeZoom');
          function drawBox(a) {
            rect.classList.toggle('d-none', !a);
            clear.classList.toggle('d-none', !a);
            if (a) {
              rect.style.left = (a[0] * 100) + '%'; rect.style.top = (a[1] * 100) + '%';
              rect.style.width = (a[2] * 100) + '%'; rect.style.height = (a[3] * 100) + '%';
            }
            drawZoom(a);
          }
          // The box cut out of the live picture and enlarged to fit about 320 px.
          function drawZoom(a) {
            var w = live.naturalWidth, h = live.naturalHeight;
            var on = !!a && !live.classList.contains('d-none') && w > 0;
            zoomWrap.classList.toggle('d-none', !on);
            if (!on) return;
            var sx = a[0] * w, sy = a[1] * h, sw = Math.max(1, a[2] * w), sh = Math.max(1, a[3] * h);
            var scale = Math.min(320 / sw, 320 / sh, 4);
            zoom.width = Math.round(sw * scale); zoom.height = Math.round(sh * scale);
            var ctx = zoom.getContext('2d');
            ctx.imageSmoothingQuality = 'high';
            ctx.drawImage(live, sx, sy, sw, sh, 0, 0, zoom.width, zoom.height);
          }
          live.addEventListener('load', function () { if (area) drawZoom(area); });
          function boxOf(p, q) { return [Math.min(p[0], q[0]), Math.min(p[1], q[1]), Math.abs(q[0] - p[0]), Math.abs(q[1] - p[1])]; }
          areaEl.addEventListener('pointerdown', function (e) {
            if (live.classList.contains('d-none')) return;
            dragFrom = at(e); areaEl.setPointerCapture(e.pointerId); e.preventDefault();
          });
          areaEl.addEventListener('pointermove', function (e) { if (dragFrom) drawBox(boxOf(dragFrom, at(e))); });
          areaEl.addEventListener('pointerup', function (e) {
            if (!dragFrom) return;
            var b = boxOf(dragFrom, at(e)); dragFrom = null;
            // A click, not a drag, keeps the box there was.
            area = (b[2] >= 0.03 && b[3] >= 0.03) ? b : area;
            drawBox(area);
          });
          clear.addEventListener('click', function (e) { e.preventDefault(); area = null; drawBox(null); });
          function show(img, blob) { var old = img.src; img.src = URL.createObjectURL(blob); if (old && old.indexOf('blob:') === 0) URL.revokeObjectURL(old); img.classList.remove('d-none'); }
          // Like Live on the camera list: stops after 5 minutes, pauses while the tab is not looked at.
          var LIVE_MS = 5 * 60 * 1000, liveStarted = 0;
          function tick() {
            if (document.hidden) return;
            if (Date.now() - liveStarted > LIVE_MS) {
              clearInterval(liveTimer); liveTimer = null;
              live.classList.add('d-none'); rect.classList.add('d-none'); zoomWrap.classList.add('d-none'); hint.classList.add('d-none');
              capBtn.disabled = true;
              msg.textContent = 'Camera stopped after 5 minutes to save internet. Click Start camera to continue.';
              return;
            }
            fetch('attendance_camera_live.php?id=' + sel.value + '&t=' + Date.now(), {credentials: 'same-origin', cache: 'no-store'})
              .then(function (r) { return r.status === 200 ? r.blob() : null; })
              .then(function (b) {
                if (!liveTimer) return;
                if (!b && msg.dataset.waiting && Date.now() - liveStarted > 20000) {
                  msg.textContent = 'No picture yet. Click Start camera to try again. If it still does not show, check that the camera program is running on the office computer.';
                }
                if (b) { show(live, b); hint.classList.remove('d-none'); if (msg.dataset.waiting) { msg.textContent = 'Ask the person to look straight at the camera, then click Capture.'; delete msg.dataset.waiting; } }
              }).catch(function () {});
          }
          function stop() {
            clearInterval(liveTimer); clearInterval(capTimer); liveTimer = capTimer = null;
            box.classList.add('d-none'); live.classList.add('d-none'); shot.classList.add('d-none');
            save.classList.add('d-none'); retake.classList.add('d-none'); capBtn.classList.remove('d-none'); capBtn.disabled = false;
            hint.classList.add('d-none'); area = null; drawBox(null);
          }
          function startLive() {
            clearInterval(liveTimer);
            box.classList.remove('d-none'); shot.classList.add('d-none');
            save.classList.add('d-none'); retake.classList.add('d-none'); capBtn.classList.remove('d-none'); capBtn.disabled = false;
            msg.textContent = 'Connecting to the camera… this takes a few seconds.'; msg.dataset.waiting = '1';
            liveStarted = Date.now();
            liveTimer = setInterval(tick, 2000); tick();
            drawBox(area); // Retake keeps the box drawn
          }
          document.getElementById('camTakeStart').addEventListener('click', startLive);
          // Another camera is another view: the box does not fit it.
          sel.addEventListener('change', function () { area = null; drawBox(null); if (liveTimer) startLive(); });
          retake.addEventListener('click', startLive);
          capBtn.addEventListener('click', function () {
            var fd = new FormData();
            fd.append('_csrf', save.querySelector('[name=_csrf]').value);
            fd.append('action', 'capture');
            fd.append('id', sel.value);
            if (area) fd.append('area', area.map(function (v) { return v.toFixed(4); }).join(','));
            capBtn.disabled = true;
            msg.textContent = 'Taking the photo… hold still.';
            fetch('attendance_camera_live.php', {method: 'POST', body: fd, credentials: 'same-origin'}).then(function () {
              var tries = 0;
              clearInterval(capTimer);
              capTimer = setInterval(function () {
                if (++tries > 40) { clearInterval(capTimer); capBtn.disabled = false; msg.textContent = 'The photo could not be taken. Click Capture to try again. If it still fails, check that the camera program is running on the office computer.'; return; }
                fetch('attendance_camera_live.php?id=' + sel.value + '&capture=1&t=' + Date.now(), {credentials: 'same-origin', cache: 'no-store'})
                  .then(function (r) {
                    if (r.status === 204) return;
                    clearInterval(capTimer);
                    capBtn.disabled = false;
                    if (r.status === 200) {
                      return r.blob().then(function (b) {
                        clearInterval(liveTimer); liveTimer = null;
                        live.classList.add('d-none'); rect.classList.add('d-none'); hint.classList.add('d-none'); zoomWrap.classList.add('d-none'); show(shot, b);
                        document.getElementById('camTakeCamId').value = sel.value;
                        capBtn.classList.add('d-none'); retake.classList.remove('d-none'); save.classList.remove('d-none');
                        msg.textContent = 'Is the face clear? Save it, or Retake.';
                      });
                    }
                    return r.text().then(function (t) { msg.textContent = t + ' Then click Capture again.'; });
                  }).catch(function () {});
              }, 1000);
            });
          });
          modal.addEventListener('hidden.bs.modal', stop);
        })();
        </script>
        <?php endif; ?>
        <script>
        document.getElementById('camFaceModal').addEventListener('show.bs.modal', function (e) {
          var b = e.relatedTarget, face = b.dataset.face;
          var img = document.getElementById('camFaceImg');
          document.getElementById('camFaceName').textContent = b.dataset.name;
          document.querySelectorAll('.camFaceId').forEach(function (i) { i.value = b.dataset.id; });
          img.classList.toggle('d-none', !face);
          img.src = face ? 'attendance_camera_face.php?id=' + b.dataset.id + '&v=' + b.dataset.v : '';
          document.getElementById('camFaceNone').classList.toggle('d-none', !!face);
          document.getElementById('camFaceSource').textContent = {
            profile: 'From the profile photo. Upload one here to use a different photo for the camera.',
            file: 'From the faces folder on the camera PC. Upload one here to use a different photo.',
            camera: 'Uploaded for the camera.'
          }[face] || '';
          // Only a photo uploaded here can be deleted; the profile photo stays on the profile.
          document.getElementById('camFaceDelete').classList.toggle('d-none', face !== 'camera');
          var learned = JSON.parse(b.dataset.learned || '[]'), list = document.getElementById('camLearned');
          list.textContent = '';
          // One small form for the photo buttons below: what to do, and with which photo.
          function act(action, name, question) {
            if (!confirm(question)) return;
            document.getElementById('camLearnedAction').value = action;
            document.getElementById('camLearnedName').value = name;
            document.getElementById('camLearnedDelete').submit();
          }
          function button(icon, title, cls, onClick) {
            var el = document.createElement('button');
            el.type = 'button';
            el.className = 'btn btn-link p-0 small ' + cls;
            el.title = title;
            el.innerHTML = '<i class="bi ' + icon + '"></i>';
            el.addEventListener('click', onClick);
            return el;
          }
          learned.forEach(function (l) {
            var cell = document.createElement('div'), pic = document.createElement('img'), del = document.createElement('button');
            cell.className = 'text-center small';
            pic.src = 'attendance_camera_face.php?id=' + b.dataset.id + '&learned=' + encodeURIComponent(l[0]);
            pic.alt = 'Reference photo';
            pic.className = 'rounded border d-block';
            pic.style.cssText = 'width:64px;height:64px;object-fit:cover';
            del.type = 'button';
            del.className = 'btn btn-link p-0 text-secondary small';
            del.title = 'Delete this reference photo';
            del.innerHTML = '<i class="bi bi-trash"></i>';
            del.addEventListener('click', function () { act('learned_delete', l[0], 'Delete this reference photo?'); });
            cell.appendChild(pic);
            cell.appendChild(document.createTextNode('match ' + Number(l[1]).toFixed(2) + ' '));
            cell.appendChild(button('bi-person-x', 'Different person', 'text-danger me-1', function () {
              act('learned_reject', l[0], 'Different person? The cameras will stop matching this face to them.');
            }));
            cell.appendChild(del);
            list.appendChild(cell);
          });
          var extra = JSON.parse(b.dataset.extra || '[]'), elist = document.getElementById('camExtra');
          elist.textContent = '';
          extra.forEach(function (name) {
            var cell = document.createElement('div'), pic = document.createElement('img');
            cell.className = 'text-center small';
            pic.src = 'attendance_camera_face.php?id=' + b.dataset.id + '&extra=' + encodeURIComponent(name);
            pic.alt = 'More photo';
            pic.className = 'rounded border d-block';
            pic.style.cssText = 'width:64px;height:64px;object-fit:cover';
            cell.appendChild(pic);
            cell.appendChild(button('bi-trash', 'Delete this photo', 'text-secondary', function () {
              act('extra_delete', name, 'Delete this photo?');
            }));
            elist.appendChild(cell);
          });
          var rejected = JSON.parse(b.dataset.rejected || '[]'), rlist = document.getElementById('camRejected');
          rlist.textContent = '';
          rejected.forEach(function (name) {
            var cell = document.createElement('div'), pic = document.createElement('img');
            cell.className = 'text-center small';
            pic.src = 'attendance_camera_face.php?id=' + b.dataset.id + '&rejected=' + encodeURIComponent(name);
            pic.alt = 'Different person';
            pic.className = 'rounded border d-block';
            pic.style.cssText = 'width:48px;height:48px;object-fit:cover;opacity:.7';
            cell.appendChild(pic);
            cell.appendChild(button('bi-arrow-counterclockwise', 'Undo: it may be this person after all', 'text-secondary', function () {
              act('rejected_delete', name, 'Undo? The cameras may match this face to them again.');
            }));
            rlist.appendChild(cell);
          });
          document.getElementById('camRejectedBox').classList.toggle('d-none', !rejected.length);
          document.getElementById('camLearnedBox').classList.toggle('d-none', !learned.length);
          var best = JSON.parse(b.dataset.best || 'null');
          document.getElementById('camBestBox').classList.toggle('d-none', !best);
          document.getElementById('camBestImg').src = best ? 'attendance_camera_face.php?id=' + b.dataset.id + '&best=1&t=' + Date.now() : '';
          document.getElementById('camBestScore').textContent = best ? 'match ' + Number(best[0]).toFixed(2) : '';
          document.getElementById('camBestAt').textContent = best ? best[1] : '';
          // From 0.40 HR can use it as a reference; below that it is shown only.
          var usable = best && best[0] >= <?= json_encode(HR_CAMERA_BEST_USE_MIN) ?>;
          document.getElementById('camBestUse').classList.toggle('d-none', !usable || best[2]);
          document.getElementById('camBestNote').textContent = best && best[2] ? '✓ Used as reference (in the reference photos below).'
            : usable ? 'Shown only until you click Use as reference.' : 'Below 0.40: shown only, too far to use as a reference.';
        });
        </script>
        <script>
        (function () {
          var table = document.getElementById('peopleTable');
          var search = document.getElementById('peopleSearch');
          var all = document.getElementById('tickAllShown');
          var count = document.getElementById('tickedCount');
          if (!table) return;
          function rows() { return Array.prototype.slice.call(table.tBodies[0].rows).filter(function (r) { return r.dataset.search !== undefined; }); }
          function shown() { return rows().filter(function (r) { return r.style.display !== 'none'; }); }
          function refresh() {
            count.textContent = table.querySelectorAll('.cam-tick:checked').length;
            var s = shown();
            all.checked = s.length > 0 && s.every(function (r) { return r.querySelector('.cam-tick').checked; });
          }
          search.addEventListener('input', function () {
            var q = search.value.trim().toLowerCase();
            rows().forEach(function (r) { r.style.display = !q || r.dataset.search.indexOf(q) !== -1 ? '' : 'none'; });
            refresh();
          });
          search.form.addEventListener('submit', function (e) { if (document.activeElement === search) e.preventDefault(); });
          all.addEventListener('change', function () {
            shown().forEach(function (r) { r.querySelector('.cam-tick').checked = all.checked; });
            refresh();
          });
          table.addEventListener('change', refresh);
          refresh();
        })();
        </script>
      <?php endif; ?>
    </div>
  </div>
  </div>

  <script>
  (function () {
    var panes = {cameras: document.getElementById('camTabCameras'), people: document.getElementById('camTabPeople')};
    function show(tab) {
      Object.keys(panes).forEach(function (k) { panes[k].classList.toggle('d-none', k !== tab); });
      document.querySelectorAll('[data-cam-tab]').forEach(function (a) { a.classList.toggle('active', a.dataset.camTab === tab); });
    }
    document.querySelectorAll('[data-cam-tab]').forEach(function (a) {
      a.addEventListener('click', function (e) {
        e.preventDefault();
        show(a.dataset.camTab);
        history.replaceState(null, '', a.dataset.camTab === 'people' ? '#people' : location.pathname + location.search);
      });
    });
    show(location.hash === '#people' ? 'people' : 'cameras');
  })();
  </script>

<?php endif; ?>

<?php require_once __DIR__ . '/includes/hr_layout_footer.php'; ?>
